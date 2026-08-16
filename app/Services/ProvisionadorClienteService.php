<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Notificacao;
use App\Models\Sistema;
use App\Models\User;

/**
 * Cria o cliente num sistema integrado pelo contrato /api/matriz/v1/clientes.
 *
 * É o caminho da revenda: o cliente nasce lá aguardando liberação de licença,
 * exatamente como nasceria se a revenda tivesse cadastrado pelo painel do
 * próprio sistema. Quem libera continua sendo o administrador da Alfa, pelo
 * GerenciadorLicencaService.
 *
 * Ao voltar, ancora o cliente no sistema e grava o estado no vínculo
 * cliente_sistema — o mesmo retrato que o sincronizador mantém. Sem a âncora,
 * o próximo ciclo de sync criaria um segundo cliente para a mesma unidade.
 */
class ProvisionadorClienteService
{
    private readonly ContratoMatriz $contrato;

    public function __construct(private readonly Sistema $sistema)
    {
        $this->contrato = new ContratoMatriz($sistema);
    }

    /**
     * @param  array{nome_admin: string, email_admin: string, senha_admin: string}  $admin
     * @return array<string, mixed> o cliente criado no sistema (id_externo, status, ...)
     */
    public function provisionar(Cliente $cliente, array $admin): array
    {
        $this->contrato->exigirConfigurado();

        if ($cliente->idExternoNoSistema($this->sistema) !== null) {
            throw new \RuntimeException("O cliente {$cliente->nome} já existe no {$this->sistema->nome}.");
        }

        $revendaIdExterno = $cliente->revenda?->idExternoNoSistema($this->sistema);

        // A revenda precisa existir lá antes do cliente dela: sem a âncora, o
        // outro lado não teria a quem vincular a unidade.
        if ($revendaIdExterno === null) {
            throw new \RuntimeException(
                "A revenda deste cliente ainda não está provisionada no {$this->sistema->nome}. "
                .'Provisione a revenda antes de cadastrar clientes para ela.'
            );
        }

        $envelope = $this->contrato->enviar('/clientes', [
            'revenda_id_externo' => $revendaIdExterno,
            'nome' => $cliente->nome,
            'cnpj' => $cliente->cpf_cnpj,
            'telefone' => $cliente->telefonePrincipal()?->telefone,
            'cidade' => $cliente->cidade,
            'uf' => $cliente->uf,
            'nome_admin' => $admin['nome_admin'],
            'email_admin' => $admin['email_admin'],
            'senha_admin' => $admin['senha_admin'],
        ]);

        $dados = $envelope['dados'] ?? [];

        $cliente->ancorarEm($this->sistema, (string) $dados['id_externo']);

        // O estado já no vínculo: a tela mostra "pendente de licença" agora, sem
        // esperar o próximo ciclo do sincronizador.
        $cliente->sistemas()->syncWithoutDetaching([$this->sistema->id => [
            'ativo' => true,
            'ativado_em' => now()->toDateString(),
            'status_saas' => $dados['status'] ?? 'pendente',
            'bloqueia_acesso' => 0,
        ]]);

        $this->avisarPedidoDeLicenca($cliente, $dados['status'] ?? 'pendente');

        return $dados;
    }

    /**
     * O cliente pendente é um pedido da revenda esperando a Alfa — e quem
     * decide não deveria descobri-lo varrendo a lista de clientes.
     *
     * É o análogo comercial da pergunta aguardando resposta: alguém agiu, a
     * bola está com outro lado, e o outro lado não está olhando. O destinatário
     * é quem EDITA clientes na matriz — a mesma capacidade da tela onde a
     * licença se libera. `avisar` cala para o autor: o admin da matriz que
     * cadastra direto não pede licença a si mesmo por sino.
     */
    private function avisarPedidoDeLicenca(Cliente $cliente, string $status): void
    {
        if ($status !== 'pendente') {
            return;
        }

        foreach (User::idsDeQuemVe('clientes', 'editar') as $destinatarioId) {
            Notificacao::avisar($destinatarioId, auth()->id(), [
                'tipo' => 'licenca_pendente',
                'nivel' => 'atencao',
                'icone' => 'clock',
                'titulo' => $cliente->nome.' aguarda liberação de licença',
                'meta' => $this->sistema->nome.($cliente->revenda ? ' · pedido de '.$cliente->revenda->nome : ''),
                'rota' => route('clientes.index'),
            ]);
        }
    }
}
