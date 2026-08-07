<?php

namespace App\Services\Integracao;

use App\Services\Integracao\Dto\ContadoresExternos;
use Carbon\CarbonImmutable;

/**
 * Um sistema de mentira que cumpre o contrato.
 *
 * É o que permite provar a sincronização, a importação e as telas sem nenhuma
 * rede — e sem depender de o AlfaGym estar no ar quando alguém roda a suíte.
 *
 * Também serve de referência executável do contrato: quem for integrar o
 * segundo sistema pode ler esta classe para ver a forma exata das respostas.
 *
 * Não carrega arquivo nenhum de propósito: quem monta as amostras é o teste,
 * porque código de produção não deve saber que existe uma pasta de amostras.
 */
class ConectorFalso implements ConectorSistema
{
    /** @var array<int, array{escopo: string, argumentos: array}> */
    private array $chamadas = [];

    private ?ErroIntegracao $falha = null;

    /** @var array<string, int> escopos que devem falhar só na primeira vez */
    private array $falhasPassageiras = [];

    /**
     * @param  array<string, array>  $dados  por escopo: clientes, revendas,
     *                                       planos, usuarios, licencas,
     *                                       financeiro, contadores, ping
     */
    public function __construct(
        private array $dados = [],
        private readonly string $sistema = 'sistema-falso',
        private readonly string $contrato = '1.0',
        private readonly int $tamanhoPagina = 200,
    ) {}

    /** Programa uma falha em TODAS as chamadas seguintes. */
    public function falharCom(string $codigo, ?int $httpStatus = null): self
    {
        $this->falha = $httpStatus !== null
            ? ErroIntegracao::doSistema($codigo, "Falha programada: {$codigo}.", $httpStatus)
            : new ErroIntegracao($codigo, "Falha programada: {$codigo}.");

        return $this;
    }

    /**
     * Programa uma falha só nas N primeiras chamadas de um escopo.
     *
     * É como se prova a varredura interrompida no meio: sem isso, só dá para
     * testar "tudo funcionou" ou "nada funcionou", e o caso que mais importa
     * fica de fora.
     */
    public function falharNoEscopo(string $escopo, string $codigo, int $vezes = 1): self
    {
        $this->falhasPassageiras[$escopo] = $vezes;
        $this->falha ??= new ErroIntegracao($codigo, "Falha programada em {$escopo}.");

        return $this;
    }

    public function pararDeFalhar(): self
    {
        $this->falha = null;
        $this->falhasPassageiras = [];

        return $this;
    }

    /** Troca os dados de um escopo — para simular o que mudou entre execuções. */
    public function com(string $escopo, array $itens): self
    {
        $this->dados[$escopo] = $itens;

        return $this;
    }

    /** @return array<int, array{escopo: string, argumentos: array}> */
    public function chamadas(): array
    {
        return $this->chamadas;
    }

    public function chamou(string $escopo): bool
    {
        foreach ($this->chamadas as $chamada) {
            if ($chamada['escopo'] === $escopo) {
                return true;
            }
        }

        return false;
    }

    public function vezesQueChamou(string $escopo): int
    {
        return count(array_filter($this->chamadas, fn ($c) => $c['escopo'] === $escopo));
    }

    public function ping(): array
    {
        $this->registrar('ping');

        return $this->dados['ping'] ?? [
            'sistema' => $this->sistema,
            'versao' => 'teste',
            'contrato' => $this->contrato,
            'unidade_cobranca' => 'cliente ativo',
            'relogio' => CarbonImmutable::now()->toIso8601String(),
            'cadastro_local_aberto' => true,
        ];
    }

    public function revendas(int $pagina = 1): RespostaIntegracao
    {
        return $this->colecao('revendas', $pagina);
    }

    public function clientes(int $pagina = 1): RespostaIntegracao
    {
        return $this->colecao('clientes', $pagina);
    }

    public function planos(int $pagina = 1): RespostaIntegracao
    {
        return $this->colecao('planos', $pagina);
    }

    public function usuarios(int $pagina = 1): RespostaIntegracao
    {
        return $this->colecao('usuarios', $pagina);
    }

    public function licencas(int $pagina = 1): RespostaIntegracao
    {
        return $this->colecao('licencas', $pagina);
    }

    public function financeiro(string $competencia, int $pagina = 1): RespostaIntegracao
    {
        $todos = $this->dados['financeiro'] ?? [];
        $daCompetencia = array_values(array_filter(
            $todos,
            fn ($item) => ($item['competencia'] ?? null) === $competencia
        ));

        return $this->colecao('financeiro', $pagina, $daCompetencia, ['competencia' => $competencia]);
    }

    public function contadores(string $competencia): ContadoresExternos
    {
        $this->registrar('contadores', ['competencia' => $competencia]);

        return ContadoresExternos::deArray(
            ($this->dados['contadores'] ?? []) + ['competencia' => $competencia]
        );
    }

    private function colecao(string $escopo, int $pagina, ?array $itens = null, array $extra = []): RespostaIntegracao
    {
        $this->registrar($escopo, ['pagina' => $pagina] + $extra);

        $itens ??= $this->dados[$escopo] ?? [];

        // Ordenação estável por identificador, como o contrato exige: sem ela,
        // a paginação perderia registro, e o dublê precisa se comportar como o
        // sistema real para o teste valer alguma coisa.
        usort($itens, fn ($a, $b) => strcmp((string) ($a['id_externo'] ?? ''), (string) ($b['id_externo'] ?? '')));

        $total = count($itens);
        $totalPaginas = max(1, (int) ceil($total / $this->tamanhoPagina));
        $fatia = array_slice($itens, ($pagina - 1) * $this->tamanhoPagina, $this->tamanhoPagina);

        return new RespostaIntegracao(
            contrato: $this->contrato,
            sistema: $this->sistema,
            geradoEm: CarbonImmutable::now(),
            dados: array_values($fatia),
            paginaNumero: $pagina,
            paginaTamanho: $this->tamanhoPagina,
            totalItens: $total,
            totalPaginas: $totalPaginas,
        );
    }

    private function registrar(string $escopo, array $argumentos = []): void
    {
        $this->chamadas[] = ['escopo' => $escopo, 'argumentos' => $argumentos];

        if (isset($this->falhasPassageiras[$escopo]) && $this->falhasPassageiras[$escopo] > 0) {
            $this->falhasPassageiras[$escopo]--;
            throw $this->falha ?? new ErroIntegracao('erro_interno', "Falha programada em {$escopo}.");
        }

        if ($this->falha !== null && $this->falhasPassageiras === []) {
            throw $this->falha;
        }
    }
}
