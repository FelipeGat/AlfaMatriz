<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Sistema;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Gerencia a licença de um cliente no AlfaGym pelo contrato
 * /api/matriz/v1/licencas (autenticado por X-Matriz-Key).
 *
 * As operações espelham o ciclo de vida da licença no gym: a revenda cadastra
 * o cliente, que nasce PENDING_LICENSE; o admin da Matriz libera (libera),
 * renova o plano quando vence (renovar), ou interrompe/retoma o acesso
 * (bloquear/desbloquear). O retorno do gym é gravado no vínculo
 * cliente_sistema (o mesmo retrato que o sincronizador mantém), para a tela
 * refletir a mudança sem esperar o próximo ciclo de sync.
 */
class GerenciadorLicencaAlfaGymService
{
    private const CONTRATO = '1.0';

    public function __construct(private readonly Sistema $sistema) {}

    /**
     * Libera a licença: o cliente PENDING_LICENSE vira ativo.
     *
     * @param  array{tipo: string, valor: float|null, obs: string|null}  $dados
     * @return array<string, mixed> a licença criada no gym (status, inicio_em, fim_em, ...)
     */
    public function liberar(Cliente $cliente, array $dados): array
    {
        $this->exigirConfigurado();

        $idExterno = $cliente->idExternoNoSistema($this->sistema);

        if ($idExterno === null) {
            throw new \RuntimeException('Cliente não está ancorado no AlfaGym; não há licença para liberar.');
        }

        $resposta = $this->post('/licencas', [
            'cliente_id_externo' => $idExterno,
            'tipo' => $dados['tipo'],
            'valor' => $dados['valor'],
            'obs' => $dados['obs'] ?? null,
        ]);

        return $this->espelhar($cliente, $resposta);
    }

    /**
     * Renova a licença existente (novo período mensal/anual).
     *
     * @param  array{tipo: string, valor: float|null, obs: string|null}  $dados
     * @return array<string, mixed> a licença renovada no gym
     */
    public function renovar(Cliente $cliente, array $dados): array
    {
        $this->exigirConfigurado();

        $idExternoLicenca = $this->idExternoLicenca($cliente);

        if ($idExternoLicenca === null) {
            throw new \RuntimeException('Cliente não possui licença no AlfaGym para renovar.');
        }

        $resposta = $this->post("/licencas/{$idExternoLicenca}/renovar", [
            'tipo' => $dados['tipo'],
            'valor' => $dados['valor'],
            'obs' => $dados['obs'] ?? null,
        ]);

        return $this->espelhar($cliente, $resposta);
    }

    /**
     * Bloqueia o acesso do cliente no gym (a academia para de operar).
     *
     * @return array<string, mixed> o novo status do cliente no gym
     */
    public function bloquear(Cliente $cliente): array
    {
        return $this->mudarAcesso($cliente, 'bloquear');
    }

    /**
     * Desbloqueia o acesso do cliente no gym (a academia volta a operar).
     *
     * @return array<string, mixed> o novo status do cliente no gym
     */
    public function desbloquear(Cliente $cliente): array
    {
        return $this->mudarAcesso($cliente, 'desbloquear');
    }

    /**
     * @return array<string, mixed> o retorno do gym (status do cliente)
     */
    private function mudarAcesso(Cliente $cliente, string $acao): array
    {
        $this->exigirConfigurado();

        $idExternoLicenca = $this->idExternoLicenca($cliente);

        if ($idExternoLicenca === null) {
            throw new \RuntimeException('Cliente não possui licença no AlfaGym para '.$acao.'.');
        }

        $resposta = $this->post("/licencas/{$idExternoLicenca}/{$acao}", []);

        $dados = $resposta['dados'] ?? [];

        // Bloquear/desbloquear devolve o status do cliente, não da licença.
        $cliente->sistemas()->syncWithoutDetaching([$this->sistema->id => [
            'status_saas' => $dados['status'] ?? null,
            'bloqueia_acesso' => ($dados['status'] ?? null) === 'bloqueado' ? 1 : 0,
        ]]);

        return $dados;
    }

    /**
     * A operação de escrita no contrato, com o envelope validado.
     *
     * @param  array<string, mixed>  $corpo
     * @return array<string, mixed> o envelope `{ contrato, dados }`
     */
    private function post(string $caminho, array $corpo): array
    {
        try {
            $resposta = Http::withHeaders(['X-Matriz-Key' => $this->sistema->token])
                ->acceptJson()
                ->timeout(30)
                ->post($this->base().$caminho, $corpo)
                ->throw();
        } catch (RequestException $e) {
            throw new \RuntimeException($this->mensagemDeErro($e), 0, $e);
        }

        $envelope = $resposta->json();

        if (($envelope['contrato'] ?? null) !== self::CONTRATO) {
            throw new \RuntimeException('AlfaGym respondeu com contrato incompatível.');
        }

        return $envelope;
    }

    /**
     * Espelha o retorno da licença no vínculo cliente_sistema — mesmo retrato
     * que o sincronizador grava, para a tela refletir sem esperar o sync.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed> os dados da licença
     */
    private function espelhar(Cliente $cliente, array $envelope): array
    {
        $licenca = $envelope['dados'] ?? [];

        $cliente->sistemas()->syncWithoutDetaching([$this->sistema->id => [
            'licenca_status' => $licenca['status'] ?? null,
            'plano' => $licenca['plano'] ?? null,
            'licenca_inicio_em' => $licenca['inicio_em'] ?? null,
            'licenca_fim_em' => $licenca['fim_em'] ?? null,
            'bloqueia_acesso' => $licenca['bloqueia_acesso'] ?? null,
            'licenca_id_externo' => $licenca['id_externo'] ?? null,
            'status_saas' => $licenca['status'] ?? null,
        ]]);

        return $licenca;
    }

    /**
     * O id_externo da licença deste cliente no gym (do vínculo, preenchido
     * pelo sync ou por uma liberação anterior).
     */
    private function idExternoLicenca(Cliente $cliente): ?string
    {
        $vinculo = $cliente->sistemas()
            ->where('sistemas.id', $this->sistema->id)
            ->first();

        $id = $vinculo?->pivot->licenca_id_externo ?? null;

        return $id === null || $id === '' ? null : (string) $id;
    }

    private function exigirConfigurado(): void
    {
        if (! $this->sistema->base_url || ! $this->sistema->token) {
            throw new \RuntimeException('Sistema sem endereço ou sem chave configurada.');
        }
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
