<?php

namespace App\Services;

use App\Models\Revenda;
use App\Models\Sistema;

/**
 * Provisiona uma revenda num sistema integrado pelo contrato
 * /api/matriz/v1/revendas, criando a revenda + usuário administrador lá, e
 * ancora a revenda local no sistema para o sincronizador reconhecê-la.
 */
class ProvisionadorRevendaService
{
    private readonly ContratoMatriz $contrato;

    public function __construct(private readonly Sistema $sistema)
    {
        $this->contrato = new ContratoMatriz($sistema);
    }

    /**
     * @param  array<string, mixed>  $admin  dados do usuário administrador (nome, email, senha)
     * @return array<string, mixed> resposta do sistema (id_externo, nome, admin_id_externo, email_admin)
     */
    public function provisionar(Revenda $revenda, array $admin): array
    {
        $this->contrato->exigirConfigurado();

        if ($revenda->idExternoNoSistema($this->sistema) !== null) {
            throw new \RuntimeException("A revenda {$revenda->nome} já está provisionada no {$this->sistema->nome}.");
        }

        $envelope = $this->contrato->enviar('/revendas', [
            'nome_revenda' => $revenda->nome,
            'cnpj' => $revenda->cnpj,
            'email' => $revenda->contato_email,
            'telefone' => $revenda->contato_telefone,
            'contato_adm' => $revenda->contato_nome,
            'email_adm' => $revenda->contato_email,
            'nome_admin' => $admin['nome'],
            'email_admin' => $admin['email'],
            'senha_admin' => $admin['senha'],
        ]);

        $dados = $envelope['dados'] ?? [];

        $revenda->ancorarEm($this->sistema, (string) $dados['id_externo']);

        return $dados;
    }
}
