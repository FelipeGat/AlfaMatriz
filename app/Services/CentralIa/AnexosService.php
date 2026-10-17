<?php

namespace App\Services\CentralIa;

use App\Models\CentralIaAnexo;
use App\Models\CentralIaMensagem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AnexosService
{
    // 12 MB: o PHP de produção aceita upload até 12M (deploy/php-alfamatriz.ini);
    // acima disso o corpo do POST era descartado e a tela mostrava outro erro.
    public const MAX_BYTES = 12 * 1024 * 1024;
    public const MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'video/mp4', 'video/webm', 'video/quicktime'];

    /**
     * Texto e documentos de escritório (dono, 17/10/2026: "tenho que
     * conseguir importar txt, pdf, doc, xls, csv, ppt"). Valem pelo PAR
     * extensão + tipo real: o finfo sozinho não separa csv de txt nem docx de
     * zip, e a extensão sozinha deixaria um executável entrar como .doc. O
     * MIME guardado é o canônico da extensão, que é o que o runner reconhece.
     * Formatos com macro (docm, xlsm, pptm) ficam fora de propósito.
     */
    public const DOCUMENTOS = [
        'txt' => 'text/plain', 'md' => 'text/markdown', 'csv' => 'text/csv', 'tsv' => 'text/tab-separated-values',
        'json' => 'application/json', 'log' => 'text/plain', 'rtf' => 'application/rtf',
        'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel', 'ppt' => 'application/vnd.ms-powerpoint',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
    ];

    public const MENSAGEM_TIPOS = 'Envie imagem (JPG, PNG, WebP), PDF, vídeo (MP4, WebM, MOV), texto (TXT, MD, CSV, TSV, JSON, LOG) ou documento (DOC, DOCX, XLS, XLSX, PPT, PPTX, ODT, ODS, ODP, RTF) de até 12 MB.';

    /** O tipo a guardar, ou null se o par extensão + conteúdo não fecha. */
    public static function tipo(string $nome, string $detectado): ?string
    {
        if (in_array($detectado, self::MIMES, true)) {
            return $detectado;
        }
        $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        $canonico = self::DOCUMENTOS[$ext] ?? null;
        $aceitos = match (true) {
            $canonico === null => [],
            in_array($ext, ['txt', 'md', 'csv', 'tsv', 'json', 'log'], true) => null, // qualquer text/*
            $ext === 'rtf' => ['text/rtf', 'application/rtf'],
            in_array($ext, ['doc', 'xls', 'ppt'], true) => [$canonico, 'application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office'],
            // OOXML e ODF são zip; libmagic antigo não olha dentro.
            default => [$canonico, 'application/zip'],
        };
        $ok = $aceitos === null
            ? str_starts_with($detectado, 'text/') || in_array($detectado, ['application/json', 'application/csv'], true)
            : in_array($detectado, $aceitos, true);
        return $ok ? $canonico : null;
    }

    public function guardar(User $user, UploadedFile $arquivo, string $id): CentralIaAnexo
    {
        $mime = self::tipo($arquivo->getClientOriginalName(), (string) (new \finfo(FILEINFO_MIME_TYPE))->file($arquivo->getRealPath()));
        if (! $mime || $arquivo->getSize() > self::MAX_BYTES || $arquivo->getSize() < 1) {
            throw ValidationException::withMessages(['arquivo' => self::MENSAGEM_TIPOS]);
        }
        $hash = hash_file('sha256', $arquivo->getRealPath());
        // Trava por conta: reenvios simultâneos não sobrescrevem o arquivo.
        return DB::transaction(function () use ($user, $arquivo, $id, $mime, $hash) {
            User::whereKey($user->id)->lockForUpdate()->first();
            $existente = CentralIaAnexo::find($id);
            if ($existente) {
                if ($existente->user_id !== $user->id || $existente->sha256 !== $hash) {
                    throw ValidationException::withMessages(['arquivo' => 'Identificador de anexo já usado.']);
                }
                return $existente;
            }
            $nome = mb_substr(preg_replace('/[\x00-\x1f\x7f\\\\\/]/u', '_', $arquivo->getClientOriginalName()), 0, 180);
            $caminho = $arquivo->storeAs('central-ia/'.$user->id, $id, 'central_ia');
            if (! $caminho) {
                throw ValidationException::withMessages(['arquivo' => 'Não foi possível guardar o arquivo. Tente novamente.']);
            }
            try {
                return CentralIaAnexo::create(['id' => $id, 'user_id' => $user->id, 'nome' => $nome,
                    'mime' => $mime, 'tamanho' => $arquivo->getSize(), 'caminho' => $caminho, 'sha256' => $hash]);
            } catch (\Throwable $e) {
                Storage::disk('central_ia')->delete($caminho);
                throw $e;
            }
        });
    }

    public function associar(User $user, CentralIaMensagem $mensagem, array $ids): void
    {
        if (count($ids) > 5 || count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages(['anexos' => 'Envie até cinco anexos diferentes.']);
        }
        $linhas = CentralIaAnexo::where('user_id', $user->id)->whereIn('id', $ids)->lockForUpdate()->get();
        if ($linhas->count() !== count($ids) || $linhas->contains(fn ($a) => $a->mensagem_id && $a->mensagem_id !== $mensagem->id)) {
            throw ValidationException::withMessages(['anexos' => 'Anexo indisponível para esta mensagem.']);
        }
        $atuais = CentralIaAnexo::where('mensagem_id', $mensagem->id)->pluck('id')->all();
        if (! $mensagem->wasRecentlyCreated && (array_diff($atuais, $ids) || array_diff($ids, $atuais))) {
            throw ValidationException::withMessages(['anexos' => 'O reenvio deve manter os mesmos anexos.']);
        }
        foreach ($linhas as $anexo) {
            $anexo->update(['mensagem_id' => $mensagem->id]);
        }
    }

    public function baixar(User $user, string $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $anexo = CentralIaAnexo::where('user_id', $user->id)->findOrFail($id);
        abort_unless(Storage::disk('central_ia')->exists($anexo->caminho), 404);
        return Storage::disk('central_ia')->download($anexo->caminho, $anexo->nome, [
            'Content-Type' => $anexo->mime, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
