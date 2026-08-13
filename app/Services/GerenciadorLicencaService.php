<?php

namespace App\Services;

use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\Sistema;

/**
 * Gerencia a licença de um cliente num sistema integrado pelo contrato
 * /api/matriz/v1/licencas.
 *
 * As operações espelham o ciclo de vida da licença lá: a revenda cadastra o
 * cliente, que nasce pendente; o admin da Matriz libera (liberar), renova o
 * plano quando vence (renovar), ou interrompe/retoma o acesso
 * (bloquear/desbloquear). O retorno é gravado no vínculo cliente_sistema (o
 * mesmo retrato que o sincronizador mantém), para a tela refletir a mudança sem
 * esperar o próximo ciclo de sync.
 */
class GerenciadorLicencaService
{
    private readonly ContratoMatriz $contrato;

    public function __construct(private readonly Sistema $sistema)
    {
        $this->contrato = new ContratoMatriz($sistema);
    }

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

        return $this->auditando($cliente, 'liberar', function () use ($idExterno, $dados, $cliente) {
            $resposta = $this->post('/licencas', [
                'cliente_id_externo' => $idExterno,
                'tipo' => $dados['tipo'],
                'valor' => $dados['valor'],
                'obs' => $dados['obs'] ?? null,
            ]);

            return $this->espelhar($cliente, $resposta);
        });
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

        return $this->auditando($cliente, 'renovar', function () use ($idExternoLicenca, $dados, $cliente) {
            $resposta = $this->post("/licencas/{$idExternoLicenca}/renovar", [
                'tipo' => $dados['tipo'],
                'valor' => $dados['valor'],
                'obs' => $dados['obs'] ?? null,
            ]);

            return $this->espelhar($cliente, $resposta);
        });
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

        return $this->auditando($cliente, $acao, function () use ($idExternoLicenca, $acao, $cliente) {
            $resposta = $this->post("/licencas/{$idExternoLicenca}/{$acao}", []);

            $dados = $resposta['dados'] ?? [];

            // Bloquear/desbloquear devolve o status do cliente, não da licença.
            $cliente->sistemas()->syncWithoutDetaching([$this->sistema->id => [
                'status_saas' => $dados['status'] ?? null,
                'bloqueia_acesso' => ($dados['status'] ?? null) === 'bloqueado' ? 1 : 0,
            ]]);

            return $dados;
        });
    }

    /**
     * Roda a operação e grava o que ela fez com a licença.
     *
     * O registro é escrito à mão porque a licença mora no PIVÔ
     * `cliente_sistema`, e `syncWithoutDetaching` não dispara evento nenhum do
     * Eloquent — o trait de auditoria não vê nada acontecer. Sem isto, cortar o
     * acesso de uma academia seria a operação mais consequente do painel e a
     * única sem rastro.
     *
     * Fica no SERVIÇO, e não nos quatro métodos do controller, porque é aqui
     * que passa toda mudança de licença. No controller seriam quatro cópias,
     * esperando a quinta operação nascer sem a sua.
     *
     * O retrato de depois é relido do banco em vez de montado a partir da
     * resposta do gym: o que a auditoria precisa afirmar é o que FICOU gravado
     * aqui, e não o que o outro lado disse que ia acontecer.
     *
     * @param  callable(): array<string, mixed>  $operacao
     * @return array<string, mixed>
     */
    private function auditando(Cliente $cliente, string $acao, callable $operacao): array
    {
        $antes = $this->retratoDaLicenca($cliente);

        // Fora do try: operação que estourou não mudou nada, e uma linha de
        // auditoria para ela diria que mudou.
        $resultado = $operacao();

        $depois = $this->retratoDaLicenca($cliente);

        $mudou = ['operação' => ['de' => null, 'para' => $acao]];

        foreach ($depois as $campo => $valor) {
            if (($antes[$campo] ?? null) !== $valor) {
                $mudou[$campo] = ['de' => $antes[$campo] ?? null, 'para' => $valor];
            }
        }

        Auditoria::registrar(
            recurso: 'clientes',
            acao: 'licenca',
            alvo: $cliente,
            descricao: $cliente->nome.' · '.$this->sistema->nome,
            alteracoes: $mudou,
        );

        return $resultado;
    }

    /**
     * O estado da licença deste cliente neste sistema, agora.
     *
     * @return array<string, mixed>
     */
    private function retratoDaLicenca(Cliente $cliente): array
    {
        $pivo = $cliente->sistemas()
            ->where('sistemas.id', $this->sistema->id)
            ->first()?->pivot;

        if (! $pivo) {
            return [];
        }

        $campos = [
            'licenca_status', 'plano', 'licenca_inicio_em',
            'licenca_fim_em', 'status_saas', 'bloqueia_acesso',
        ];

        return array_combine($campos, array_map(fn ($campo) => $pivo->{$campo}, $campos));
    }

    /**
     * A operação de escrita no contrato, com o envelope validado.
     *
     * @param  array<string, mixed>  $corpo
     * @return array<string, mixed> o envelope `{ contrato, dados }`
     */
    private function post(string $caminho, array $corpo): array
    {
        return $this->contrato->enviar($caminho, $corpo);
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
            'licenca_id_externo' => $licenca['id_externo'] ?? null,
            'status_saas' => $licenca['status'] ?? null,
            'bloqueia_acesso' => ($licenca['status'] ?? null) === 'bloqueado' ? 1 : 0,
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
        $this->contrato->exigirConfigurado();
    }
}
