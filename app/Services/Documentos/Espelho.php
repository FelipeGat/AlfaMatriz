<?php

namespace App\Services\Documentos;

use App\Models\Documento;
use App\Models\DocumentoPasta;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\MimeTypes;

/**
 * O espelho de uma pasta do OneDrive (docs/specs/documentos-espelhados): a
 * máquina do dono manda o inventário, recebe a lista do que subir e envia em
 * partes; o que sumiu da origem sai daqui, e a cópia junto (FR-005).
 */
class Espelho
{
    /** Tamanho de cada parte que a máquina envia (cabe no limite de 13 MB do PHP). */
    public const PARTE_BYTES = 8 * 1024 * 1024;

    /**
     * @param  list<array{caminho: string, tamanho: int, modificado: string}>  $arquivos
     * @param  list<string>|null  $subpastas  todas as pastas, vazias inclusive (documentos-mao-dupla FR-006)
     * @return array{pasta: DocumentoPasta, enviar: list<string>, removidos: int, miniaturas: list<string>}
     */
    public function inventario(User $dono, string $maquina, string $nome, array $arquivos, ?array $subpastas = null): array
    {
        $vistos = [];
        foreach ($arquivos as $a) {
            $caminho = self::caminhoSeguro($a['caminho']);
            if ($caminho === null || (int) $a['tamanho'] > Documento::MAX_BYTES) {
                continue;
            }
            $vistos[sha1($caminho)] = ['caminho' => $caminho, 'tamanho' => (int) $a['tamanho'], 'modificado' => self::data($a['modificado'])];
        }

        $pastas = $subpastas === null ? null : collect($subpastas)->map(fn ($c) => self::caminhoSeguro($c))->filter()->unique()->sort()->values()->all();

        return DB::transaction(function () use ($dono, $maquina, $nome, $vistos, $pastas) {
            $pasta = DocumentoPasta::firstOrCreate(['user_id' => $dono->id, 'nome' => $nome]);
            $atuais = $pasta->documentos()->get()->keyBy('caminho_hash');

            $removidos = 0;
            foreach ($atuais as $hash => $doc) {
                if (! isset($vistos[$hash])) {
                    $this->apagar($doc);
                    $removidos++;
                }
            }
            $enviar = [];
            foreach ($vistos as $hash => $v) {
                $doc = $atuais[$hash] ?? null;
                if (! $doc || $doc->tamanho !== $v['tamanho'] || ! $doc->modificado_em || abs($doc->modificado_em->diffInSeconds($v['modificado'])) > 2) {
                    $enviar[] = $v['caminho'];
                }
            }
            $pasta->update(['maquina' => $maquina, 'lido_em' => now()] + ($pastas === null ? [] : ['subpastas' => $pastas]));
            $this->recontar($pasta);
            // PDF e vídeo sem miniatura: a máquina gera e manda (o que acabou de subir entra na próxima).
            $miniaturas = $pasta->documentos()->whereNull('miniatura')->whereIn('extensao', Miniaturas::DA_MAQUINA)
                ->whereNotIn('caminho', $enviar)->orderBy('id')->limit(40)->pluck('caminho')->all();

            return ['pasta' => $pasta, 'enviar' => $enviar, 'removidos' => $removidos, 'miniaturas' => $miniaturas];
        });
    }

    /** Uma parte do arquivo; na última, confere tamanho e sha256 e publica. Devolve o documento quando completa. */
    public function receberParte(DocumentoPasta $pasta, array $d, UploadedFile $parte): ?Documento
    {
        $caminho = self::caminhoSeguro($d['caminho']) ?? throw ValidationException::withMessages(['caminho' => 'Caminho recusado.']);
        if ((int) $d['tamanho'] > Documento::MAX_BYTES) {
            throw ValidationException::withMessages(['tamanho' => 'Arquivo acima de 200 MB.']);
        }
        $disco = Storage::disk(Documento::DISCO);
        $temp = "documentos/_partes/{$pasta->id}/".sha1($caminho).'.part';
        $alvo = $disco->path($temp);
        @mkdir(dirname($alvo), 0775, true);
        $n = (int) $d['parte'];
        $atual = is_file($alvo) ? filesize($alvo) : 0;
        if ($n === 0) {
            $atual = 0;
        } elseif ($atual !== $n * self::PARTE_BYTES) {
            throw ValidationException::withMessages(['parte' => 'Parte fora de ordem: recomece o arquivo.']);
        }
        file_put_contents($alvo, file_get_contents($parte->getRealPath()), $n === 0 ? 0 : FILE_APPEND);
        if ($n < (int) $d['partes'] - 1) {
            return null;
        }

        clearstatcache(true, $alvo);
        if (filesize($alvo) !== (int) $d['tamanho'] || ! hash_equals(strtolower($d['sha256']), hash_file('sha256', $alvo))) {
            @unlink($alvo);
            throw ValidationException::withMessages(['sha256' => 'O arquivo chegou diferente do que saiu: recomece.']);
        }
        $ext = self::extensao($caminho);
        $sha = strtolower($d['sha256']);
        $final = "documentos/{$pasta->id}/".substr($sha, 0, 2)."/{$sha}".($ext !== '' ? ".{$ext}" : '');
        @mkdir(dirname($disco->path($final)), 0775, true);
        rename($alvo, $disco->path($final));

        return DB::transaction(function () use ($pasta, $caminho, $d, $sha, $ext, $final) {
            $doc = Documento::firstOrNew(['pasta_id' => $pasta->id, 'caminho_hash' => sha1($caminho)]);
            $anterior = $doc->exists ? $doc->arquivo : null;
            $subpasta = str_contains($caminho, '/') ? substr($caminho, 0, strrpos($caminho, '/')) : '';
            $doc->fill(['caminho' => $caminho, 'subpasta' => $subpasta, 'nome' => basename($caminho), 'extensao' => $ext,
                'mime' => MimeTypes::getDefault()->getMimeTypes($ext)[0] ?? 'application/octet-stream',
                'tamanho' => (int) $d['tamanho'], 'sha256' => $sha, 'modificado_em' => self::data($d['modificado']), 'arquivo' => $final,
                // Conteúdo novo, miniatura nova.
                'miniatura' => $doc->exists && $doc->sha256 === $sha ? $doc->miniatura : null])->save();
            if ($anterior && $anterior !== $final && ! Documento::where('arquivo', $anterior)->exists()) {
                Storage::disk(Documento::DISCO)->delete($anterior);
            }
            $this->recontar($pasta);

            return $doc;
        });
    }

    /** Caminho relativo, com `/`, sem `..`, sem absoluto, sem controle e de extensão aceita (FR-002, FR-006). */
    public static function caminhoSeguro(mixed $caminho): ?string
    {
        $c = str_replace('\\', '/', trim((string) $caminho));
        if ($c === '' || mb_strlen($c) > 600 || str_starts_with($c, '/') || preg_match('/[\x00-\x1F\x7F]/', $c) || preg_match('/^[A-Za-z]:/', $c)) {
            return null;
        }
        foreach (explode('/', $c) as $parte) {
            if ($parte === '' || $parte === '.' || $parte === '..') {
                return null;
            }
        }

        return in_array(self::extensao($c), Documento::BLOQUEADAS, true) ? null : $c;
    }

    public static function extensao(string $caminho): string
    {
        $e = mb_strtolower(pathinfo($caminho, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,16}$/', $e) ? $e : '';
    }

    private static function data(mixed $v): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse((string) $v)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return CarbonImmutable::now();
        }
    }

    public function apagar(Documento $doc): void
    {
        $arquivo = $doc->arquivo;
        $miniatura = $doc->caminhoMiniatura();
        $doc->delete();
        if (! Documento::where('arquivo', $arquivo)->exists()) {
            Storage::disk(Documento::DISCO)->delete($arquivo);
        }
        if (! Documento::where('pasta_id', $doc->pasta_id)->where('sha256', $doc->sha256)->exists()) {
            Storage::disk(Documento::DISCO)->delete($miniatura);
        }
    }

    public function recontar(DocumentoPasta $pasta): void
    {
        $pasta->update(['arquivos' => $pasta->documentos()->count(), 'bytes' => (int) $pasta->documentos()->sum('tamanho')]);
    }
}
