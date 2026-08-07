<?php

namespace App\Services\Integracao;

use App\Models\FaturamentoSnapshot;
use App\Models\Sistema;
use App\Models\SistemaCliente;
use App\Models\SistemaContador;
use Illuminate\Support\Collection;

/**
 * Onde o que os sistemas dizem não bate com o que a Alfa faturou.
 *
 * É o motivo de a integração existir: sem esta comparação, o painel só
 * mostraria dois números lado a lado e deixaria a conferência para a cabeça de
 * quem olha.
 *
 * Regra que atravessa tudo aqui: APONTAR O CASO, não só o total. Um total
 * divergente sem o caso não ajuda ninguém a agir — e uma tela que não ajuda a
 * agir passa a ser ignorada em duas semanas.
 */
class DivergenciaService
{
    /** @return array<string, Collection> */
    public function apurar(string $competencia): array
    {
        return [
            'sem_vinculo' => $this->clientesSemVinculo(),
            'ativos_so_na_matriz' => $this->ativosSoNaMatriz(),
            'unidades' => $this->unidadesDivergentes($competencia),
            'sem_contrato' => $this->ativosSemValorContratado(),
        ];
    }

    public function total(string $competencia): int
    {
        return collect($this->apurar($competencia))->sum(fn (Collection $linhas) => $linhas->count());
    }

    /**
     * Cliente que existe no sistema e não corresponde a ninguém na matriz.
     *
     * Enquanto ele estiver assim, não é cobrado por ninguém — e é o caso mais
     * caro de todos, porque some sem deixar rastro no financeiro.
     */
    private function clientesSemVinculo(): Collection
    {
        return SistemaCliente::query()
            ->presentes()
            ->ativos()
            ->semVinculo()
            ->with('sistema')
            ->orderBy('nome')
            ->get();
    }

    /**
     * Cliente que a matriz considera ativo num sistema, mas que lá dentro está
     * inativo, bloqueado ou nem existe.
     *
     * É o inverso do anterior: aqui a Alfa está cobrando por algo que o
     * sistema não reconhece mais.
     */
    private function ativosSoNaMatriz(): Collection
    {
        $linhas = collect();

        foreach (Sistema::where('categoria', 'saas')->where('ativo', true)->get() as $sistema) {
            // Nada a comparar num sistema que nunca foi lido: acusar tudo como
            // divergente seria transformar "ainda não sincronizei" em alarme.
            if ($sistema->sincronizado_em === null) {
                continue;
            }

            $ativosNoSistema = SistemaCliente::doSistema($sistema)->ativos()
                ->whereNotNull('cliente_id')
                ->pluck('cliente_id')
                ->all();

            $sistema->clientes()
                ->where('clientes.ativo', true)
                ->where('cliente_sistema.ativo', true)
                ->whereNotIn('clientes.id', $ativosNoSistema)
                ->get(['clientes.id', 'clientes.nome'])
                ->each(fn ($cliente) => $linhas->push([
                    'sistema' => $sistema,
                    'cliente' => $cliente,
                ]));
        }

        return $linhas;
    }

    /**
     * A contagem de unidades do sistema contra a que a Alfa faturou.
     *
     * A apuração do faturamento já guarda, por sistema e revenda, quantos
     * clientes foram cobrados — é o outro lado exato da comparação, sem
     * precisar recalcular nada.
     */
    private function unidadesDivergentes(string $competencia): Collection
    {
        $linhas = collect();

        $apuracoes = FaturamentoSnapshot::where('competencia', $competencia)
            ->with(['sistema', 'revenda'])
            ->get();

        foreach ($apuracoes as $apuracao) {
            $contador = SistemaContador::where('sistema_id', $apuracao->sistema_id)
                ->where('competencia', $competencia)
                ->first();

            if (! $contador || ! $apuracao->revenda) {
                continue;
            }

            $noSistema = $this->unidadesNoSistema($contador, $apuracao->sistema_id, $apuracao->revenda_id);

            // Revenda que o sistema não conhece não é divergência de contagem:
            // é falta de vínculo, que já aparece no primeiro bloco.
            if ($noSistema === null || $noSistema === (int) $apuracao->clientes_ativos) {
                continue;
            }

            $linhas->push([
                'sistema' => $apuracao->sistema,
                'revenda' => $apuracao->revenda,
                'no_sistema' => $noSistema,
                'faturado' => (int) $apuracao->clientes_ativos,
                'diferenca' => $noSistema - (int) $apuracao->clientes_ativos,
            ]);
        }

        return $linhas;
    }

    /**
     * Cliente ativo dentro do sistema, vinculado na matriz, e sem valor
     * mensal cadastrado.
     *
     * Está sendo usado e não está sendo cobrado de ninguém. O valor vive na
     * matriz — nenhum sistema informa dinheiro —, então a ausência aqui é a
     * ausência de verdade, e não uma falha de sincronização.
     */
    private function ativosSemValorContratado(): Collection
    {
        return SistemaCliente::query()
            ->presentes()
            ->ativos()
            ->whereNotNull('cliente_id')
            ->with(['sistema', 'cliente.revenda'])
            ->get()
            ->filter(fn (SistemaCliente $registro) => blank($registro->cliente?->valor_mensal)
                || (float) $registro->cliente->valor_mensal <= 0)
            ->map(fn (SistemaCliente $registro) => [
                'sistema' => $registro->sistema,
                'cliente' => $registro->cliente,
                'nome' => $registro->nome,
                'unidades' => (int) $registro->unidades_ativas,
            ])
            ->values();
    }

    /** Quantas unidades o sistema atribui a esta revenda da matriz. */
    private function unidadesNoSistema(SistemaContador $contador, int $sistemaId, ?int $revendaId): ?int
    {
        if ($revendaId === null) {
            return null;
        }

        // O contador fala em identificador do SISTEMA; a apuração fala em
        // revenda da matriz. O retrato é a ponte entre os dois.
        $idExterno = \App\Models\SistemaRevenda::where('sistema_id', $sistemaId)
            ->where('revenda_id', $revendaId)
            ->value('id_externo');

        return $idExterno === null ? null : $contador->unidadesDaRevenda((string) $idExterno);
    }
}
