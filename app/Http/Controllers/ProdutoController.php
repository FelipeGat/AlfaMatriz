<?php

namespace App\Http\Controllers;

use App\Models\Sistema;
use App\Models\Tarefa;
use Illuminate\Http\Request;

class ProdutoController extends Controller
{
    public function index(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        // A conta dos produtos roda nas DUAS abas, e não só na deles: a aba de
        // internos exibe a contagem do catálogo comercial no próprio rótulo, e
        // o cabeçalho da tela também. Poupá-la exigiria um segundo caminho de
        // contagem — e dois caminhos discordam. O catálogo é de punhado.
        $aba = $request->query('aba') === 'internos' ? 'internos' : 'produtos';

        $produtos = Sistema::produtos()
            ->with(['modulos.contratacoes' => fn ($q) => $q->where('status', 'ativo')])
            ->orderBy('nome')->get()->map(function (Sistema $sistema) {
            $ativos = $sistema->clientesAtivosCount();
            $cancelados = $sistema->clientesCanceladosCount();
            // O MRR do produto é o recorrente inteiro: licença mais módulos.
            // Contando só a licença, o topo da tela somava menos que a fatura,
            // e o produto aparecia menor do que é. A linha de módulos logo
            // abaixo abre a parcela, não acrescenta a ela.
            $mrrModulos = $sistema->mrrModulos();
            $mrr = $sistema->mrrEstimado() + $mrrModulos;

            return [
                'sistema' => $sistema,
                'clientes_ativos' => $ativos,
                'clientes_cancelados' => $cancelados,
                'mrr' => $mrr,
                'arr' => $mrr * 12,
                'ticket_medio' => $ativos > 0 ? $mrr / $ativos : 0,
                'taxa_cancelamento' => ($ativos + $cancelados) > 0 ? ($cancelados / ($ativos + $cancelados)) * 100 : 0,
                // Sem tier de atacado o sistema não entra no faturamento das
                // revendas — é a pendência que a tela precisa gritar.
                'sem_tier' => $sistema->tiersVigentes()->isEmpty(),
                // Módulos são receita à parte da licença: sem eles na tela, o
                // MRR do produto parece menor do que é. O valor sai da mesma
                // regra de vigência que a fatura usa — a contagem própria que
                // morava aqui somava contratação de cliente já desativado e
                // ignorava a vigência na competência.
                'modulos_ativos' => $sistema->modulos->filter(fn ($m) => $m->contratacoes->isNotEmpty())->count(),
                'mrr_modulos' => $mrrModulos,
            ];
        });

        $mrrTotal = (float) $produtos->sum('mrr');

        // Ordenar por receita é o que transforma sete cartões soltos numa
        // lista comparável: a primeira linha é o produto que sustenta a casa.
        $produtos = $produtos
            ->map(fn ($p) => $p + ['share' => $mrrTotal > 0 ? $p['mrr'] / $mrrTotal : 0])
            ->sortByDesc('mrr')
            ->values();

        // A largura da barra é calculada contra o maior MRR da lista INTEIRA,
        // ainda aqui em cima. Se o divisor fosse o maior da página, o segundo
        // colocado apareceria com barra cheia na página 2 e a leitura "quem
        // sustenta a casa" — a razão de ser desta lista — se perderia.
        $maiorMrr = (float) ($produtos->max('mrr') ?: 0);

        // Os internos não passam por nada disso: eles não têm cliente, tier nem
        // MRR, e a única coisa que se pode dizer deles é quanto trabalho está
        // aberto contra cada um. Vêm inteiros, sem paginar — o catálogo de
        // dentro de casa é de punhado, e um paginador aqui dividiria o `page`
        // com a outra aba.
        $internos = Sistema::internos()
            ->withCount(['tarefas' => fn ($q) => $q->whereNotIn('status', Tarefa::STATUS_TERMINAIS)])
            ->orderBy('nome')
            ->get();

        return view('produtos.index', [
            'aba' => $aba,
            'internos' => $internos,
            'produtos' => $this->paginarColecao($produtos->map(fn ($p) => $p + [
                'largura' => $maiorMrr > 0 ? $p['mrr'] / $maiorMrr : 0,
            ])),
            'mrrTotal' => $mrrTotal,
            'totais' => [
                'mrr' => $mrrTotal,
                'arr' => $mrrTotal * 12,
                'ativos' => (int) $produtos->sum('clientes_ativos'),
                'cancelados' => (int) $produtos->sum('clientes_cancelados'),
            ],
            'contagens' => [
                'sistemas' => $produtos->count(),
                'ativos' => $produtos->filter(fn ($p) => $p['sistema']->ativo)->count(),
                'sem_tier' => $produtos->where('sem_tier', true)->count(),
                'internos' => $internos->count(),
            ],
        ]);
    }

    public function update(Request $request, Sistema $sistema)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'versao' => 'nullable|string|max:255',
            'responsavel' => 'nullable|string|max:255',
            'roadmap' => 'nullable|string',
        ]);

        $sistema->update($data);

        return redirect()->route('produtos.index')->with('status', "Informações de gestão de {$sistema->nome} atualizadas.");
    }
}
