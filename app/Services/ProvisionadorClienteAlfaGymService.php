<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Sistema;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Cria o cliente no AlfaGym pelo contrato /api/matriz/v1/clientes
 * (autenticado por X-Matriz-Key).
 *
 * É o caminho da revenda: o cliente nasce lá aguardando liberação de licença,
 * exatamente como nasceria se a revenda tivesse cadastrado pelo painel do gym.
 * Quem libera continua sendo o administrador da Alfa, pelo
 * GerenciadorLicencaAlfaGymService.
 *
 * Ao voltar, ancora o cliente no sistema e grava o estado no vínculo
 * cliente_sistema — o mesmo retrato que o sincronizador mantém. Sem a âncora,
 * o próximo ciclo de sync criaria um segundo cliente para a mesma academia.
 */
class ProvisionadorClienteAlfaGymService
{
    private const CONTRATO = '1.0';

    public function __construct(private readonly Sistema $sistema) {}

    /**
     * @param  array{nome_admin: string, email_admin: string, senha_admin: string}  $admin
     * @return array<string, mixed> o cliente criado no gym (id_externo, status, ...)
     */
    public function provisionar(Cliente $cliente, array $admin): array
    {
        if (! $this->sistema->base_url || ! $this->sistema->token) {
            throw new \RuntimeException('Sistema sem endereço ou sem chave configurada.');
        }

        if ($cliente->idExternoNoSistema($this->sistema) !== null) {
            throw new \RuntimeException("O cliente {$cliente->nome} já existe no AlfaGym.");
        }

        $revendaIdExterno = $cliente->revenda?->idExternoNoSistema($this->sistema);

        // A revenda precisa existir no gym antes do cliente dela: sem a âncora,
        // o gym não teria a quem vincular a academia.
        if ($revendaIdExterno === null) {
            throw new \RuntimeException(
                'A revenda deste cliente ainda não está provisionada no AlfaGym. '
                .'Provisione a revenda antes de cadastrar clientes para ela.'
            );
        }

        try {
            $resposta = Http::withHeaders(['X-Matriz-Key' => $this->sistema->token])
                ->acceptJson()
                ->timeout(30)
                ->post($this->base().'/clientes', [
                    'revenda_id_externo' => $revendaIdExterno,
                    'nome' => $cliente->nome,
                    'cnpj' => $cliente->cpf_cnpj,
                    'telefone' => $cliente->telefonePrincipal()?->telefone,
                    'cidade' => $cliente->cidade,
                    'uf' => $cliente->uf,
                    'nome_admin' => $admin['nome_admin'],
                    'email_admin' => $admin['email_admin'],
                    'senha_admin' => $admin['senha_admin'],
                ])
                ->throw();
        } catch (RequestException $e) {
            throw new \RuntimeException($this->mensagemDeErro($e), 0, $e);
        }

        $corpo = $resposta->json();

        if (($corpo['contrato'] ?? null) !== self::CONTRATO) {
            throw new \RuntimeException('AlfaGym respondeu com contrato incompatível.');
        }

        $dados = $corpo['dados'] ?? [];

        $cliente->ancorarEm($this->sistema, (string) $dados['id_externo']);

        // O estado já no vínculo: a tela mostra "pendente de licença" agora, sem
        // esperar o próximo ciclo do sincronizador.
        $cliente->sistemas()->syncWithoutDetaching([$this->sistema->id => [
            'ativo' => true,
            'ativado_em' => now()->toDateString(),
            'status_saas' => $dados['status'] ?? 'pendente',
            'bloqueia_acesso' => 0,
        ]]);

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
