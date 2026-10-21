<?php

namespace App\Services\Secretario;

use App\Models\Notificacao;
use App\Models\SecretarioGoogleConta;
use App\Models\User;
use App\Services\CentralIa\Eventos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OAuth 2.0 e Calendar v3 do Google, sem SDK (research D1; endpoints em
 * contracts/google.md, da documentação oficial). Mesmo desenho da
 * `TelegramApi`: o erro volta classificado, e token nenhum entra em exceção,
 * log ou evento (spec FR-003).
 */
class GoogleAgenda
{
    public const ESCOPOS = 'https://www.googleapis.com/auth/calendar.readonly https://www.googleapis.com/auth/calendar.events';
    private const AUTORIZAR = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN = 'https://oauth2.googleapis.com/token';
    private const REVOGAR = 'https://oauth2.googleapis.com/revoke';
    private const API = 'https://www.googleapis.com/calendar/v3';

    public function configurado(): bool
    {
        return filled(config('services.google_secretario.client_id')) && filled(config('services.google_secretario.client_secret'));
    }

    public function redirecionamento(): string
    {
        return route('secretario.google.retorno');
    }

    public function urlDeAutorizacao(string $state): string
    {
        return self::AUTORIZAR.'?'.http_build_query([
            'client_id' => config('services.google_secretario.client_id'),
            'redirect_uri' => $this->redirecionamento(),
            'response_type' => 'code',
            'scope' => self::ESCOPOS,
            'access_type' => 'offline',
            // Sem `consent`, a segunda autorização não devolve o refresh token.
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /** @return array{ok: bool, erro?: string, tokens?: array} */
    public function trocarCodigo(string $codigo): array
    {
        return $this->postToken([
            'code' => $codigo, 'client_id' => config('services.google_secretario.client_id'),
            'client_secret' => config('services.google_secretario.client_secret'),
            'redirect_uri' => $this->redirecionamento(), 'grant_type' => 'authorization_code',
        ]);
    }

    public function revogar(SecretarioGoogleConta $conta): void
    {
        foreach (array_filter([$conta->refresh_token, $conta->access_token]) as $token) {
            try {
                Http::asForm()->connectTimeout(5)->timeout(10)->post(self::REVOGAR, ['token' => $token]);
            } catch (\Throwable) {
                // Revogar é melhor esforço: os tokens são apagados aqui de qualquer jeito.
            }
        }
    }

    /**
     * Um token de acesso válido da conta, renovando com trava se faltar pouco
     * (FR-019). null = sem acesso (reconectar ou rede): o motivo fica na conta.
     */
    public function tokenValido(SecretarioGoogleConta $conta): ?string
    {
        if (! $conta->conectada()) {
            return null;
        }
        if ($conta->expira_em->greaterThan(now()->addMinutes(2))) {
            return $conta->access_token;
        }

        return DB::transaction(function () use ($conta) {
            $c = SecretarioGoogleConta::whereKey($conta->id)->lockForUpdate()->first();
            // Quem renovou primeiro já deixou o token novo: usa ele.
            if (! $c || ! $c->conectada()) {
                return null;
            }
            if ($c->expira_em->greaterThan(now()->addMinutes(2))) {
                $conta->setRawAttributes($c->getAttributes(), true);

                return $c->access_token;
            }
            if (! $c->refresh_token) {
                $this->marcarReconectar($c, 'sem_refresh');

                return null;
            }
            $r = $this->postToken([
                'refresh_token' => $c->refresh_token, 'client_id' => config('services.google_secretario.client_id'),
                'client_secret' => config('services.google_secretario.client_secret'), 'grant_type' => 'refresh_token',
            ]);
            if (! $r['ok']) {
                $r['erro'] === 'invalid_grant' ? $this->marcarReconectar($c, 'invalid_grant') : $c->update(['erro' => $r['erro']]);

                return null;
            }
            $c->update(['access_token' => $r['tokens']['access_token'], 'expira_em' => now()->addSeconds((int) ($r['tokens']['expires_in'] ?? 3600)), 'erro' => null]);
            $conta->setRawAttributes($c->getAttributes(), true);

            return $c->access_token;
        });
    }

    /** Autorização retirada no Google: conta em "reconectar" e UM aviso no sino (FR-008). */
    public function marcarReconectar(SecretarioGoogleConta $c, string $motivo): void
    {
        $c->update(['situacao' => SecretarioGoogleConta::RECONECTAR, 'erro' => $motivo]);
        if (! $c->aviso_reconectar_em) {
            Notificacao::create([
                'destinatario_id' => $c->user_id, 'tipo' => 'lembrete', 'nivel' => 'atencao', 'icone' => 'calendar',
                'titulo' => 'Google Agenda desconectado do Secretário', 'meta' => 'Reconecte em Secretário → Configurações',
                'rota' => route('secretario.configuracoes'),
            ]);
            $c->update(['aviso_reconectar_em' => now()]);
            Eventos::registrar($c->user_id, 'secretario_google_reconectar', "O Google recusou a renovação ({$motivo}); a conta precisa ser reconectada.", ['origem' => 'google'], 'aviso');
        }
    }

    /** @return array{ok: bool, erro?: string, dados?: array} */
    public function get(SecretarioGoogleConta $conta, string $caminho, array $query = []): array
    {
        return $this->chamar($conta, 'get', $caminho, $query);
    }

    /** @return array{ok: bool, erro?: string, dados?: array} */
    public function post(SecretarioGoogleConta $conta, string $caminho, array $corpo, array $query = []): array
    {
        return $this->chamar($conta, 'post', $caminho.($query ? '?'.http_build_query($query) : ''), $corpo);
    }

    private function chamar(SecretarioGoogleConta $conta, string $metodo, string $caminho, array $dados): array
    {
        $token = $this->tokenValido($conta);
        if (! $token) {
            return ['ok' => false, 'erro' => $conta->fresh()?->conectada() ? ($conta->fresh()->erro ?? 'sem_token') : 'reconectar'];
        }
        try {
            $r = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(20)->{$metodo}(self::API.$caminho, $dados);
        } catch (\Throwable $e) {
            Log::warning('Google Agenda: chamada falhou', ['caminho' => strtok($caminho, '?'), 'classe' => class_basename($e)]);

            return ['ok' => false, 'erro' => 'nao_enviada'];
        }
        if ($r->successful()) {
            return ['ok' => true, 'dados' => $r->json() ?? []];
        }

        return ['ok' => false, 'erro' => match (true) {
            $r->status() === 401 => 'nao_autorizado',
            $r->status() === 429, $r->status() === 403 && str_contains((string) $r->body(), 'ateLimit') => 'limite',
            $r->clientError() => 'recusada',
            default => 'incerta',
        }];
    }

    /** @return array{ok: bool, erro?: string, tokens?: array} */
    private function postToken(array $dados): array
    {
        try {
            $r = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(15)->post(self::TOKEN, $dados);
        } catch (\Throwable $e) {
            Log::warning('Google OAuth: troca de token falhou', ['classe' => class_basename($e)]);

            return ['ok' => false, 'erro' => 'nao_enviada'];
        }
        $corpo = $r->json() ?? [];
        if ($r->successful() && filled($corpo['access_token'] ?? null)) {
            return ['ok' => true, 'tokens' => $corpo];
        }

        // Só o código do erro (`invalid_grant`…), nunca o corpo: ele pode ecoar o que foi enviado.
        return ['ok' => false, 'erro' => is_string($corpo['error'] ?? null) ? mb_substr($corpo['error'], 0, 40) : 'recusada'];
    }

    /** O dono de uma conta pode ler o Secretário? (conta desativada ou sem perfil não sincroniza) */
    public static function contaAtiva(SecretarioGoogleConta $c): bool
    {
        $u = User::find($c->user_id);

        return $u && $u->ativo && ! $u->temEscopoDeRevenda() && $u->canPermissao('secretario', 'ler');
    }
}
