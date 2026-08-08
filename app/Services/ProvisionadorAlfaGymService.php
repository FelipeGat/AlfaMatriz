<?php

namespace App\Services;

use App\Models\Revenda;
use App\Models\Sistema;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Provisiona uma revenda no AlfaGym pelo contrato /api/matriz/v1/revendas
 * (autenticado por X-Matriz-Key), criando a revenda + usuário ADMIN_REVENDA lá,
 * e ancora a revenda local no sistema para o sincronizador reconhecê-la.
 */
class ProvisionadorAlfaGymService
{
    private const CONTRATO = '1.0';

    public function __construct(private readonly Sistema $sistema) {}

    /**
     * @param  array<string, mixed>  $admin  dados do usuário ADMIN_REVENDA (nome, email, senha)
     * @return array<string, mixed> resposta do gym (id_externo, nome, admin_id_externo, email_admin)
     */
    public function provisionar(Revenda $revenda, array $admin): array
    {
        if (! $this->sistema->base_url || ! $this->sistema->token) {
            throw new \RuntimeException('Sistema sem endereço ou sem chave configurada.');
        }

        if ($revenda->idExternoNoSistema($this->sistema) !== null) {
            throw new \RuntimeException("A revenda {$revenda->nome} já está provisionada no AlfaGym.");
        }

        try {
            $resposta = Http::withHeaders(['X-Matriz-Key' => $this->sistema->token])
                ->acceptJson()
                ->timeout(30)
                ->post($this->base().'/revendas', [
                    'nome_revenda' => $revenda->nome,
                    'cnpj' => $revenda->cnpj,
                    'email' => $revenda->contato_email,
                    'telefone' => $revenda->contato_telefone,
                    'contato_adm' => $revenda->contato_nome,
                    'email_adm' => $revenda->contato_email,
                    'nome_admin' => $admin['nome'],
                    'email_admin' => $admin['email'],
                    'senha_admin' => $admin['senha'],
                ])
                ->throw();
        } catch (RequestException $e) {
            $mensagem = $this->mensagemDeErro($e);

            throw new \RuntimeException($mensagem, 0, $e);
        }

        $corpo = $resposta->json();

        if (($corpo['contrato'] ?? null) !== self::CONTRATO) {
            throw new \RuntimeException('AlfaGym respondeu com contrato incompatível.');
        }

        $dados = $corpo['dados'] ?? [];

        $revenda->ancorarEm($this->sistema, (string) $dados['id_externo']);

        return $dados;
    }

    private function base(): string
    {
        return rtrim($this->sistema->base_url, '/').'/api/matriz/v1';
    }

    private function mensagemDeErro(RequestException $e): string
    {
        $corpo = $e->response?->json();

        $mensagem = $corpo['erro']['mensagem'] ?? null;

        if (is_string($mensagem) && $mensagem !== '') {
            return 'AlfaGym recusou: '.$mensagem;
        }

        return 'AlfaGym respondeu '.$e->response?->status().'.';
    }
}
