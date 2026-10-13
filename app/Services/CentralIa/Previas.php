<?php

namespace App\Services\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaPrevia;
use App\Models\CentralIaTelegramVinculo;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prévias visuais (FR-034 a FR-036). O agente salva o que quer mostrar numa
 * pasta da execução; a máquina envia para cá; a resposta mostra miniatura,
 * PDF e "Abrir prévia", e imagem e PDF vão ao Telegram.
 *
 * O endereço da prévia leva um código aleatório da execução, e não a sessão:
 * a página é servida em sandbox (origem opaca, sem cookie nem API do
 * AlfaMatriz), e numa página assim nem as imagens e o CSS dela levariam a
 * sessão. Quem tem o link vê o rascunho — só o dono o recebe — e ele vence
 * em 7 dias.
 */
class Previas
{
    public const TIPOS = [
        'html' => 'text/html', 'htm' => 'text/html', 'css' => 'text/css', 'js' => 'text/javascript', 'json' => 'application/json',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf', 'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'txt' => 'text/plain',
    ];

    public const MAX_ARQUIVO = 20 * 1024 * 1024;

    public const MAX_EXECUCAO = 60 * 1024 * 1024;

    public const MAX_ARQUIVOS = 80;

    public const DIAS = 7;

    public function pronto(): bool
    {
        return Schema::hasTable('central_ia_previas');
    }

    /** Caminho relativo seguro: sem "..", sem barra inicial, extensão conhecida. */
    public static function caminhoValido(string $caminho): ?string
    {
        $c = str_replace('\\', '/', trim($caminho));
        if ($c === '' || str_starts_with($c, '/') || str_contains($c, '..') || ! preg_match('#^[A-Za-z0-9._/ -]{1,300}$#', $c)) return null;
        return array_key_exists(Str::lower(pathinfo($c, PATHINFO_EXTENSION)), self::TIPOS) ? $c : null;
    }

    public function guardar(User $user, CentralIaExecucao $e, string $caminho, UploadedFile $arquivo): CentralIaPrevia
    {
        $c = self::caminhoValido($caminho);
        abort_unless($c, 422, 'Caminho ou tipo de prévia não aceito.');
        abort_if($arquivo->getSize() > self::MAX_ARQUIVO, 413, 'Prévia acima de 20 MB.');
        $ja = CentralIaPrevia::where('execucao_id', $e->id);
        abort_if((clone $ja)->count() >= self::MAX_ARQUIVOS || (clone $ja)->sum('tamanho') + $arquivo->getSize() > self::MAX_EXECUCAO, 413, 'Limite de prévias da execução.');

        $chave = (clone $ja)->value('chave') ?? Str::random(48);
        $mime = self::TIPOS[Str::lower(pathinfo($c, PATHINFO_EXTENSION))];
        Storage::disk('local')->putFileAs("central-ia/previas/{$user->id}/{$e->id}/".dirname($c), $arquivo, basename($c));
        $visual = str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml' || $mime === 'application/pdf';

        return CentralIaPrevia::updateOrCreate(['execucao_id' => $e->id, 'caminho' => $c], [
            'user_id' => $user->id, 'chave' => $chave, 'mime' => $mime, 'tamanho' => $arquivo->getSize(),
            'sha256' => hash_file('sha256', $arquivo->getRealPath()),
            // Imagem e PDF vão ao Telegram (dono, 13/10/2026), quando há vínculo.
            'telegram' => $visual && CentralIaTelegramVinculo::where('user_id', $user->id)->exists() ? 'pendente' : null,
        ]);
    }

    /** O que a resposta mostra: imagens, PDFs e as páginas (os .html). */
    public function daExecucao(int $execucaoId): array
    {
        if (! $this->pronto()) return [];

        return CentralIaPrevia::where('execucao_id', $execucaoId)->orderBy('caminho')->get()
            ->filter(fn ($p) => in_array($p->tipo(), ['imagem', 'pdf', 'pagina'], true))
            ->map(fn ($p) => ['caminho' => $p->caminho, 'tipo' => $p->tipo(),
                'url' => route('central-ia.previa', ['chave' => $p->chave, 'caminho' => $p->caminho])])
            ->values()->all();
    }

    public function servir(string $chave, string $caminho): Response
    {
        $c = self::caminhoValido($caminho);
        $p = $c ? CentralIaPrevia::where('chave', $chave)->where('caminho', $c)->first() : null;
        abort_unless($p && $p->created_at->gt(now()->subDays(self::DIAS)), 404);
        $arquivo = "central-ia/previas/{$p->user_id}/{$p->execucao_id}/{$p->caminho}";
        abort_unless(Storage::disk('local')->exists($arquivo), 404);

        return response(Storage::disk('local')->get($arquivo), 200, [
            'Content-Type' => $p->mime.(str_starts_with($p->mime, 'text/') ? '; charset=utf-8' : ''),
            // Feita por IA: roda isolada — origem opaca, sem cookie nem API.
            'Content-Security-Policy' => 'sandbox allow-scripts allow-forms allow-popups',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
