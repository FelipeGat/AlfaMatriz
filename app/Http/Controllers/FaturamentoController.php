<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\ClienteModulo;
use App\Models\Cobranca;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\FaturamentoService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FaturamentoController extends Controller
{
    public function index(Request $request)
    {
        $competencia = $request->input('competencia', now()->format('Y-m'));
        $mes = \Illuminate\Support\Carbon::createFromFormat('Y-m', $competencia)->startOfMonth();

        $escopo = auth()->user()->temEscopoDeRevenda()
            ? ['id' => auth()->user()->revenda_id]
            : [];

        $revendas = Revenda::when($escopo, fn ($q) => $q->where($escopo))
            ->where('ativo', true)
            ->orderBy('nome')
            ->get();

        $preview = $revendas
            ->map(fn (Revenda $revenda) => $this->painelDaRevenda($revenda, $competencia))
            ->filter(fn ($painel) => $painel['linhas']->isNotEmpty())
            ->values();

        $cobrancasGeradas = Cobranca::where('tipo', 'locacao_sistema')
            ->where('competencia', $competencia)
            ->when(auth()->user()->temEscopoDeRevenda(), fn ($q) => $q->where('revenda_id', auth()->user()->revenda_id))
            ->with('revenda')
            ->get();

        $pendencias = $preview->flatMap(fn ($p) => $p['linhas']->where('sem_tier', true)
            ->map(fn ($l) => [
                'revenda' => $p['revenda']->nome,
                'sistema' => $l['sistema'],
                'unidades' => $l['unidades'],
                'unidade_cobranca' => $l['unidade_cobranca'],
            ]))->values();

        // A exportação sai DAQUI, e não de uma rota própria, porque ela é a
        // mesma prévia — mesmo escopo de revenda, mesma competência, mesmas
        // linhas. Rota separada seria uma segunda montagem para manter em dia
        // com esta, e o dia em que divergissem a planilha passaria a discordar
        // da tela sem nada acusar.
        //
        // A recusa também é aqui, pelo mesmo motivo: `permissao:faturamento`
        // no `GET` resolve para `ler`, e é o que tem de valer para a TELA. É o
        // parâmetro que separa ver de levar embora, e ele só existe neste
        // ponto.
        if ($request->input('exportar') === 'csv') {
            abort_unless(
                $request->user()->canPermissao('faturamento', 'imprimir'),
                403,
                'Você não tem permissão para exportar o faturamento.'
            );

            return $this->exportarPrevia($preview, $competencia);
        }

        return view('faturamento.index', [
            'competencia' => $competencia,
            'preview' => $preview,
            'cobrancasGeradas' => $cobrancasGeradas,
            'pendencias' => $pendencias,
            'ciclo' => [
                // O total do ciclo é a soma dos subtotais, que por sua vez são
                // a soma das linhas. Nenhum número é digitado em lugar nenhum.
                'total' => (float) $preview->sum('total'),
                'revendas' => $preview->count(),
                'linhas' => (int) $preview->sum(fn ($p) => $p['linhas']->count()),
                'pendencias' => $pendencias->count(),
                'gerado' => $cobrancasGeradas->isNotEmpty(),
                // Mesma regra do motor que gera: fim da competência + 5 dias.
                'vencimento' => $mes->copy()->endOfMonth()->addDays(5),
                'rotulo' => $mes->translatedFormat('m/Y'),
            ],
        ]);
    }

    /**
     * A prévia como planilha — uma linha por sistema de cada revenda.
     *
     * O botão "Exportar prévia" existia na tela desde sempre e não exportava
     * nada: ele apontava para esta mesma rota com `?exportar=csv`, e ninguém
     * lia o parâmetro. Recarregava a página e pronto — descoberto em
     * 15/08/2026, ao dar função à permissão de imprimir.
     *
     * As colunas são as da tela, na mesma ordem, incluindo `calculo`: é a
     * conta que originou o valor, e uma planilha de conferência sem ela obriga
     * quem confere a voltar para a tela justamente no momento em que precisa
     * comparar as duas.
     *
     * `;` como separador e BOM no começo porque o destino é o Excel em
     * português: com vírgula ele joga a linha inteira numa célula só, e sem o
     * BOM ele lê o UTF-8 como Latin-1 e todo acento vira ruído.
     *
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $preview
     */
    private function exportarPrevia($preview, string $competencia): StreamedResponse
    {
        $linhas = $preview->flatMap(fn ($painel) => $painel['linhas']->map(fn ($linha) => [
            $painel['revenda']->nome,
            $linha['sistema'],
            $linha['unidades'],
            $linha['unidade_cobranca'],
            $linha['tier'] ?? 'sem faixa',
            $linha['calculo'],
            $linha['valor_licenca'],
            $linha['valor_modulos'],
            $linha['valor'],
        ]));

        return response()->streamDownload(function () use ($linhas) {
            $saida = fopen('php://output', 'w');

            fwrite($saida, "\xEF\xBB\xBF");

            fputcsv($saida, [
                'Revenda', 'Sistema', 'Unidades', 'Unidade de cobrança',
                'Faixa', 'Cálculo', 'Licença', 'Módulos', 'Total',
            ], ';');

            foreach ($linhas as $linha) {
                fputcsv($saida, $linha, ';');
            }

            fclose($saida);
        }, "faturamento-{$competencia}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * O painel de uma revenda no ciclo: uma linha por sistema, cada uma com a
     * conta que a originou.
     *
     * @return array<string, mixed>
     */
    private function painelDaRevenda(Revenda $revenda, string $competencia): array
    {
        $sistemas = Sistema::produtos()->where('ativo', true)
            ->whereHas('clientes', fn ($q) => $q->where('revenda_id', $revenda->id)
                ->where('clientes.ativo', true)
                ->where('cliente_sistema.ativo', true))
            ->orderBy('nome')
            ->get();

        $linhas = $sistemas->map(function (Sistema $sistema) use ($revenda, $competencia) {
            $clientes = $sistema->clientes()
                ->where('revenda_id', $revenda->id)
                ->where('clientes.ativo', true)
                ->where('cliente_sistema.ativo', true)
                ->pluck('clientes.id');

            $unidades = $clientes->count();

            $tier = $sistema->tierParaVolume($unidades, $revenda->id);
            $licenca = $tier?->calcularMensalidade($unidades);

            // Módulos entram na linha como segunda parcela, igual ao motor que
            // gera a cobrança. A prévia somava só a licença e prometia um total
            // menor do que o "Gerar" ia produzir — numa tela cuja razão de
            // existir é ser auditável ANTES de gerar.
            $modulos = (float) ClienteModulo::vigentesNaCompetencia($sistema->id, $clientes, $competencia)
                ->sum('valor_mensal');

            return [
                'sistema' => $sistema->nome,
                'unidades' => $unidades,
                'unidade_cobranca' => $sistema->unidade_cobranca,
                'tier' => $tier?->nome,
                'tipo_tier' => $this->tipoDoTier($tier),
                'calculo' => $this->calculo($tier, $unidades, $modulos),
                'valor' => $tier === null ? null : $licenca + $modulos,
                'valor_licenca' => $licenca,
                'valor_modulos' => $modulos,
                'sem_tier' => $tier === null,
            ];
        });

        return [
            'revenda' => $revenda,
            'linhas' => $linhas,
            'unidades' => (int) $linhas->sum('unidades'),
            // Linha sem tier fica FORA do subtotal — cobrar por ela seria
            // inventar um preço que ninguém configurou.
            'total' => (float) $linhas->where('sem_tier', false)->sum('valor'),
            'foraDoTotal' => $linhas->where('sem_tier', true)->count(),
        ];
    }

    private function tipoDoTier(?\App\Models\PrecoAtacado $tier): string
    {
        if (! $tier) {
            return 'sem_tier';
        }

        return $tier->valor_excedente_unidade !== null ? 'metrado' : 'fixo';
    }

    /**
     * A conta em uma frase — a coluna que torna o valor auditável antes de
     * virar cobrança.
     */
    private function calculo(?\App\Models\PrecoAtacado $tier, int $unidades, float $modulos = 0.0): ?string
    {
        if (! $tier) {
            return null;
        }

        $inclusas = $tier->unidades_inclusas ?? 0;
        $emReais = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');

        // Os módulos aparecem como parcela própria da conta: o valor da linha
        // deixa de ser conferível se a soma tiver uma parte que a frase não diz.
        $comModulos = fn (string $conta) => $modulos > 0
            ? $conta.' + módulos '.$emReais($modulos)
            : $conta;

        if ($unidades <= $inclusas || $tier->valor_excedente_unidade === null) {
            return $comModulos($tier->limite_unidades
                ? 'fixo · teto '.number_format($tier->limite_unidades, 0, ',', '.')
                : 'fixo do tier');
        }

        $excedente = $unidades - $inclusas;
        $conta = $excedente.' × '.$emReais($tier->valor_excedente_unidade);

        return $comModulos($tier->preco_base > 0
            ? $emReais($tier->preco_base).' + '.$conta
            : $conta);
    }

    public function gerar(Request $request, FaturamentoService $service)
    {
        abort_if(auth()->user()->temEscopoDeRevenda(), 403, 'O fechamento do ciclo é feito pela matriz.');

        $competencia = $request->input('competencia', now()->format('Y-m'));

        $resultado = $service->gerarParaCompetencia($competencia);

        $cobrancasGeradas = collect($resultado)->filter(fn ($r) => isset($r['cobranca_id']))->count();

        // As receitas criadas já deixam a própria linha, uma a uma. Esta é a
        // linha do FECHAMENTO: quem apertou o botão e em que competência. Sem
        // ela, as cobranças geradas se parecem com dezenas de lançamentos
        // avulsos feitos no mesmo segundo, e ninguém consegue dizer se foram um
        // fechamento ou um engano repetido.
        Auditoria::registrar(
            recurso: 'faturamento',
            acao: 'gerou',
            descricao: "competência {$competencia} · {$cobrancasGeradas} cobrança(s)",
        );

        return redirect()->route('faturamento.index', ['competencia' => $competencia])
            ->with('status', "Faturamento da competência {$competencia} gerado: {$cobrancasGeradas} cobrança(s) consolidada(s) criada(s).");
    }
}
