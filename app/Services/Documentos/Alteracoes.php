<?php

namespace App\Services\Documentos;

use App\Models\Documento;
use App\Models\DocumentoOperacao;
use App\Models\DocumentoPasta;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\MimeTypes;

/**
 * Documentos em mão dupla (docs/specs/documentos-mao-dupla). O Matriz não
 * alcança o OneDrive: aqui só se ANOTA a alteração, com quem pediu; a máquina
 * do dono aplica na pasta e devolve o resultado, que então vale no Matriz sem
 * esperar o próximo espelho (FR-005).
 */
class Alteracoes
{
    public function __construct(private Espelho $espelho) {}

    public function criarPasta(DocumentoPasta $pasta, User $quem, string $nivel, string $nome): DocumentoOperacao
    {
        $nome = trim($nome);
        if ($nome === '' || mb_strlen($nome) > 120 || preg_match('/[\/\\\\:*?"<>|\x00-\x1F]/u', $nome) || str_starts_with($nome, '.')) {
            throw ValidationException::withMessages(['nome' => 'Nome de pasta inválido (sem / \\ : * ? " < > |).']);
        }
        $caminho = $this->caminho(ltrim($nivel.'/'.$nome, '/'));
        if ($this->existe($pasta, $caminho)) {
            throw ValidationException::withMessages(['nome' => 'Já existe "'.$nome.'" aqui.']);
        }

        return $this->anotar($pasta, $quem, ['tipo' => 'criar_pasta', 'caminho' => $caminho]);
    }

    /**
     * Uma parte do arquivo que o navegador envia (8 MB cada, como o runner). Na
     * última, confere o tamanho, calcula o sha256 e anota o envio.
     */
    public function receberParte(DocumentoPasta $pasta, User $quem, array $d, UploadedFile $parte): ?DocumentoOperacao
    {
        $nome = trim((string) $d['nome']);
        if ($nome === '' || preg_match('/[\/\\\\\x00-\x1F]/u', $nome)) {
            throw ValidationException::withMessages(['nome' => 'Nome de arquivo inválido.']);
        }
        $caminho = $this->caminho(ltrim(trim((string) ($d['nivel'] ?? ''), '/').'/'.$nome, '/'));
        if ((int) $d['tamanho'] > Documento::MAX_BYTES) {
            throw ValidationException::withMessages(['tamanho' => 'Arquivo acima de 200 MB.']);
        }
        $disco = Storage::disk(Documento::DISCO);
        $alvo = $disco->path("documentos/_envios/{$pasta->id}/".sha1($quem->id.'|'.$d['envio']).'.part');
        @mkdir(dirname($alvo), 0775, true);
        $n = (int) $d['parte'];
        $atual = is_file($alvo) ? filesize($alvo) : 0;
        if ($n > 0 && $atual !== $n * Espelho::PARTE_BYTES) {
            throw ValidationException::withMessages(['parte' => 'Parte fora de ordem: envie o arquivo de novo.']);
        }
        file_put_contents($alvo, file_get_contents($parte->getRealPath()), $n === 0 ? 0 : FILE_APPEND);
        if ($n < (int) $d['partes'] - 1) {
            return null;
        }
        clearstatcache(true, $alvo);
        if (filesize($alvo) !== (int) $d['tamanho']) {
            @unlink($alvo);
            throw ValidationException::withMessages(['tamanho' => 'O arquivo chegou incompleto: envie de novo.']);
        }
        $sha = hash_file('sha256', $alvo);
        $ext = Espelho::extensao($caminho);
        $guardado = "documentos/_envios/{$pasta->id}/{$sha}".($ext !== '' ? ".{$ext}" : '');
        rename($alvo, $disco->path($guardado));

        return $this->anotar($pasta, $quem, ['tipo' => 'enviar', 'caminho' => $caminho, 'arquivo' => $guardado, 'tamanho' => (int) $d['tamanho'], 'sha256' => $sha]);
    }

    /**
     * Um arquivo que nasceu no próprio Matriz (o PDF do orçamento enviado) e vai
     * para a pasta do OneDrive pelo mesmo caminho do arrastar: o runner grava e,
     * se o nome já existir lá, ganha " (2)" em vez de sobrescrever (FR-004).
     */
    public function enviarArquivo(DocumentoPasta $pasta, User $quem, string $caminho, string $local): DocumentoOperacao
    {
        $caminho = $this->caminho($caminho);
        $sha = hash_file('sha256', $local);
        $ext = Espelho::extensao($caminho);
        $guardado = "documentos/_envios/{$pasta->id}/{$sha}".($ext !== '' ? ".{$ext}" : '');
        Storage::disk(Documento::DISCO)->put($guardado, (string) file_get_contents($local));

        return $this->anotar($pasta, $quem, ['tipo' => 'enviar', 'caminho' => $caminho, 'arquivo' => $guardado, 'tamanho' => filesize($local), 'sha256' => $sha]);
    }

    public function apagar(DocumentoPasta $pasta, User $quem, string $caminho): DocumentoOperacao
    {
        $caminho = $this->caminho($caminho);
        if (! $this->existe($pasta, $caminho)) {
            throw ValidationException::withMessages(['caminho' => 'Não encontrei "'.$caminho.'" nesta pasta.']);
        }

        return $this->anotar($pasta, $quem, ['tipo' => 'apagar', 'caminho' => $caminho]);
    }

    public function mover(DocumentoPasta $pasta, User $quem, string $caminho, string $destino): DocumentoOperacao
    {
        $caminho = $this->caminho($caminho);
        $destino = $this->caminho(trim(str_replace('\\', '/', $destino), '/'));
        if ($caminho === $destino) {
            throw ValidationException::withMessages(['destino' => 'O destino é o mesmo lugar.']);
        }
        if (str_starts_with($destino.'/', $caminho.'/')) {
            throw ValidationException::withMessages(['destino' => 'Uma pasta não pode ir para dentro dela mesma.']);
        }
        if (! $this->existe($pasta, $caminho)) {
            throw ValidationException::withMessages(['caminho' => 'Não encontrei "'.$caminho.'" nesta pasta.']);
        }
        if ($this->existe($pasta, $destino)) {
            throw ValidationException::withMessages(['destino' => 'Já existe "'.$destino.'" (FR-004).']);
        }

        return $this->anotar($pasta, $quem, ['tipo' => 'mover', 'caminho' => $caminho, 'destino' => $destino]);
    }

    /** As pendentes das pastas da conta do runner, mais velhas primeiro. */
    public function pendentes(User $dono): Collection
    {
        return DocumentoOperacao::with('pasta:id,nome')->where('situacao', 'pendente')
            ->whereHas('pasta', fn ($q) => $q->where('user_id', $dono->id))->orderBy('id')->limit(50)->get();
    }

    /**
     * A máquina aplicou (ou não) a alteração. Feita, o efeito vale aqui na hora:
     * o envio vira documento com o próprio arquivo, o mover troca os caminhos,
     * o apagar tira os documentos (FR-005). Falha não se repete sozinha (FR-008).
     */
    public function concluir(DocumentoOperacao $op, array $d): void
    {
        DB::transaction(function () use ($op, $d) {
            $op = DocumentoOperacao::whereKey($op->id)->lockForUpdate()->firstOrFail();
            abort_unless($op->situacao === 'pendente', 409);
            $pasta = $op->pasta;
            if ($d['situacao'] !== 'feita') {
                $op->update(['situacao' => 'falhou', 'erro' => mb_substr((string) ($d['erro'] ?? 'a máquina não conseguiu'), 0, 190), 'feita_em' => now()]);
                $this->soltarEnvio($op);

                return;
            }
            match ($op->tipo) {
                'enviar' => $this->publicarEnvio($op, $d),
                'mover' => $this->moverAqui($pasta, $op->caminho, $op->destino),
                'apagar' => $this->apagarAqui($pasta, $op->caminho),
                default => null,
            };
            $subpastas = collect($pasta->subpastas ?? []);
            $subpastas = match ($op->tipo) {
                'criar_pasta' => $subpastas->push($op->caminho),
                'apagar' => $subpastas->reject(fn ($s) => $s === $op->caminho || str_starts_with($s, $op->caminho.'/')),
                'mover' => $subpastas->map(fn ($s) => $s === $op->caminho || str_starts_with($s, $op->caminho.'/') ? $op->destino.substr($s, strlen($op->caminho)) : $s),
                default => $subpastas,
            };
            $pasta->update(['subpastas' => $subpastas->unique()->sort()->values()->all()]);
            $this->espelho->recontar($pasta);
            $op->update(['situacao' => 'feita', 'erro' => null, 'feita_em' => now(),
                'caminho' => $op->tipo === 'enviar' && ! empty($d['caminho_final']) ? $this->caminho($d['caminho_final']) : $op->caminho]);
        });
    }

    /** Arquivo ou pasta que existe no espelho (ou foi criada e ainda está vazia). */
    public function existe(DocumentoPasta $pasta, string $caminho): bool
    {
        return $pasta->documentos()->where(fn ($q) => $q->where('caminho', $caminho)->orWhere('subpasta', $caminho)->orWhere('subpasta', 'like', addcslashes($caminho, '%_\\').'/%'))->exists()
            || in_array($caminho, $pasta->subpastas ?? [], true);
    }

    private function anotar(DocumentoPasta $pasta, User $quem, array $dados): DocumentoOperacao
    {
        return $pasta->operacoes()->create($dados + ['user_id' => $quem->id, 'situacao' => 'pendente']);
    }

    private function caminho(string $c): string
    {
        return Espelho::caminhoSeguro($c) ?? throw ValidationException::withMessages(['caminho' => 'Caminho recusado.']);
    }

    private function publicarEnvio(DocumentoOperacao $op, array $d): void
    {
        $caminho = ! empty($d['caminho_final']) ? $this->caminho($d['caminho_final']) : $op->caminho;
        $disco = Storage::disk(Documento::DISCO);
        $ext = Espelho::extensao($caminho);
        $final = "documentos/{$op->pasta_id}/".substr($op->sha256, 0, 2)."/{$op->sha256}".($ext !== '' ? ".{$ext}" : '');
        if ($op->arquivo && $disco->exists($op->arquivo)) {
            @mkdir(dirname($disco->path($final)), 0775, true);
            rename($disco->path($op->arquivo), $disco->path($final));
        }
        $doc = Documento::firstOrNew(['pasta_id' => $op->pasta_id, 'caminho_hash' => sha1($caminho)]);
        $doc->fill(['caminho' => $caminho, 'subpasta' => str_contains($caminho, '/') ? substr($caminho, 0, strrpos($caminho, '/')) : '',
            'nome' => basename($caminho), 'extensao' => $ext, 'mime' => MimeTypes::getDefault()->getMimeTypes($ext)[0] ?? 'application/octet-stream',
            'tamanho' => (int) $op->tamanho, 'sha256' => $op->sha256, 'arquivo' => $final,
            'modificado_em' => $this->data($d['modificado'] ?? null), 'miniatura' => null])->save();
        $op->arquivo = null;
    }

    private function moverAqui(DocumentoPasta $pasta, string $de, string $para): void
    {
        foreach ($pasta->documentos()->get() as $doc) {
            if ($doc->caminho !== $de && ! str_starts_with($doc->caminho, $de.'/')) {
                continue;
            }
            $novo = $para.substr($doc->caminho, strlen($de));
            Documento::where('pasta_id', $pasta->id)->where('caminho_hash', sha1($novo))->whereKeyNot($doc->id)->get()->each(fn ($d) => $this->espelho->apagar($d));
            $doc->update(['caminho' => $novo, 'caminho_hash' => sha1($novo), 'nome' => basename($novo), 'extensao' => Espelho::extensao($novo),
                'subpasta' => str_contains($novo, '/') ? substr($novo, 0, strrpos($novo, '/')) : '']);
        }
    }

    private function apagarAqui(DocumentoPasta $pasta, string $caminho): void
    {
        $pasta->documentos()->get()->filter(fn ($d) => $d->caminho === $caminho || str_starts_with($d->caminho, $caminho.'/'))
            ->each(fn ($d) => $this->espelho->apagar($d));
    }

    private function soltarEnvio(DocumentoOperacao $op): void
    {
        if ($op->tipo === 'enviar' && $op->arquivo) {
            Storage::disk(Documento::DISCO)->delete($op->arquivo);
            $op->update(['arquivo' => null]);
        }
    }

    private function data(mixed $v): CarbonImmutable
    {
        try {
            return $v ? CarbonImmutable::parse((string) $v)->setTimezone(config('app.timezone')) : CarbonImmutable::now();
        } catch (\Throwable) {
            return CarbonImmutable::now();
        }
    }
}
