<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Sistema;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Libera a licença de um cliente no AlfaGym pelo contrato
 * POST /api/matriz/v1/licencas (autenticado por X-Matriz-Key).
 *
 * A liberação é feita pelo admin da Matriz a pedido da revenda: o cliente
 * nasce PENDING_LICENSE no gym, e este serviço o coloca ATIVO informando o
 * tipo (mensal/anual), o valor e uma observação. O retorno do gym é gravado
 * no vínculo cliente_sistema (o mesmo retrato que o sincronizador mantém).
 */
class LiberadorLicencaAlfaGymService
{
    private const CONTRATO = '1.0';

    public function __construct(private readonly Sistema $sistema) {}

    /**
     * @param  array{tipo: string, valor: float|null, obs: string|null}  $dados
     * @return array<string, mixed> a licença criada no gym (status, inicio_em, fim_em, ...)
     */
    public function liberar(Cliente $cliente, array $dados): array
    {
        if (! $this->sistema->base_url || ! $this->sistema->token) {
            throw new \RuntimeException('Sistema sem endereço ou sem chave configurada.');
        }

        $idExterno = $cliente->idExternoNoSistema($this->sistema);

        if ($idExterno === null) {
            throw new \RuntimeException('Cliente não está ancorado no AlfaGym; não há licença para liberar.');
        }

        try {
            $resposta = Http::withHeaders(['X-Matriz-Key' => $this->sistema->token])
                ->acceptJson()
                ->timeout(30)
                ->post($this->base().'/licencas', [
                    'cliente_id_externo' => $idExterno,
                    'tipo' => $dados['tipo'],
                    'valor' => $dados['valor'],
                    'obs' => $dados['obs'] ?? null,
                ])
                ->throw();
        } catch (RequestException $e) {
            throw new \RuntimeException($this->mensagemDeErro($e), 0, $e);
        }

        $corpo = $resposta->json();

        if (($corpo['contrato'] ?? null) !== self::CONTRATO) {
            throw new \RuntimeException('AlfaGym respondeu com contrato incompatível.');
        }

        $licenca = $corpo['dados'] ?? [];

        // Espelha no vínculo o mesmo retrato que o sincronizador grava, para a
        // tela refletir a liberação sem esperar o próximo ciclo de sync.
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
