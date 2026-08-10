<?php

namespace App\Http\Controllers;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\TarefaRelatorioTeste;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Http\Request;

class TarefaController extends Controller
{
    public function index(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        // O quadro é o trabalho EM CURSO: concluída e cancelada não têm coluna
        // (AC-082, AC-096). Sete colunas não cabiam na tela e as duas terminais
        // eram as de menor valor no dia a dia — encerrou, sai do quadro e passa
        // a viver no histórico (`historico()`), de onde também se reabre.
        // Isso aposenta o antigo recorte de 30 dias: não há mais o que recortar.
        $emCurso = collect(Tarefa::STATUS)->reject(
            fn ($label, $status) => in_array($status, Tarefa::STATUS_TERMINAIS, true)
        );

        // `eventos` entra no eager load porque o card lê a etapa atual de cada
        // tarefa para o chip de tempo — sem isso é uma consulta por card.
        $tarefas = Tarefa::with(['sistema', 'responsavel', 'eventos'])
            ->whereIn('status', $emCurso->keys())
            ->orderByDesc('created_at')
            ->get();

        $colunas = $emCurso->mapWithKeys(fn ($label, $status) => [
            $status => $this->ordenarColuna($tarefas->where('status', $status)->values()),
        ]);

        $etapas = $emCurso->map(fn ($label, $status) => [
            'chave' => $status,
            'label' => $label,
            'cor' => $this->corDaEtapa($status),
            'quantidade' => $colunas[$status]->count(),
        ])->values()->all();

        $sistemas = Sistema::where('ativo', true)->orderBy('nome')->get();
        $usuarios = User::whereNull('revenda_id')->orderBy('name')->get();

        return view('tarefas.index', compact('tarefas', 'colunas', 'etapas', 'sistemas', 'usuarios'));
    }

    public function store(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'titulo' => 'required|string|max:255',
            'sistema_id' => 'nullable|exists:sistemas,id',
            'responsavel_id' => 'nullable|exists:users,id',
            'prioridade' => 'required|in:'.implode(',', array_keys(Tarefa::PRIORIDADES)),
        ]);

        $data['criado_por_id'] = auth()->id();

        Tarefa::create($data);

        return redirect()->route('tarefas.index')->with('status', 'Tarefa criada.');
    }

    public function update(Request $request, Tarefa $tarefa)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'titulo' => 'required|string|max:255',
            'sistema_id' => 'nullable|exists:sistemas,id',
            'responsavel_id' => 'nullable|exists:users,id',
            'prioridade' => 'required|in:'.implode(',', array_keys(Tarefa::PRIORIDADES)),
        ]);

        $tarefa->update($data);

        return redirect()->route('tarefas.index')->with('status', 'Tarefa atualizada.');
    }

    public function mover(Request $request, Tarefa $tarefa, FluxoTarefaService $fluxo)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'status' => 'required|in:'.implode(',', array_keys(Tarefa::STATUS)),
            'motivo' => 'nullable|string',
            'relatorio_aprovado' => 'nullable|boolean',
            'relatorio_notas' => 'nullable|string',
        ]);

        // A confirmação de "Em testes → Concluída" pede as notas do teste no
        // próprio movimento (ASM-033): registra o relatório antes de checar a
        // transição, para que um relatório aprovado agora já libere a mesma
        // conclusão.
        if ($data['status'] === 'concluida' && $request->filled('relatorio_notas')) {
            TarefaRelatorioTeste::create([
                'tarefa_id' => $tarefa->id,
                'aprovado' => $request->boolean('relatorio_aprovado'),
                'notas' => $data['relatorio_notas'],
            ]);
        }

        try {
            $fluxo->mover($tarefa, $data['status'], ['motivo' => $data['motivo'] ?? null]);
        } catch (\RuntimeException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return redirect()->route('tarefas.index')->with('status', 'Tarefa movida.');
    }

    public function historico(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        // Sem recorte de período (AC-097): é o caminho de auditoria para o
        // que o quadro enxuto (`index()`) já tirou de vista.
        // `eventos` para a duração do ciclo de cada linha (AC-133).
        $tarefas = Tarefa::with(['sistema', 'responsavel', 'eventos'])
            ->whereIn('status', Tarefa::STATUS_TERMINAIS)
            ->orderByDesc('updated_at')
            ->get();

        return view('tarefas.historico', compact('tarefas'));
    }

    /**
     * Ordem dos cards dentro de uma coluna: gravidade primeiro, e no empate a
     * tarefa mais parada na etapa (AC-128).
     *
     * Antes a ordem era só `created_at desc`, o que fazia uma crítica antiga
     * afundar embaixo de tarefas baixas recentes — a prioridade ficava
     * legível no selo e sem efeito nenhum na leitura da coluna.
     *
     * O desempate usa a entrada na etapa ATUAL, o mesmo instante que o card
     * mostra no chip de tempo: se a ordem seguisse outro critério, a coluna
     * pareceria embaralhada para quem lê os chips de cima para baixo.
     *
     * @param  \Illuminate\Support\Collection<int, Tarefa>  $tarefas
     * @return \Illuminate\Support\Collection<int, Tarefa>
     */
    private function ordenarColuna($tarefas)
    {
        $gravidade = array_flip(['critica', 'alta', 'media', 'baixa']);

        // Chave composta em vez de `sortBy([closure, closure])`: essa forma
        // NÃO ordena por múltiplas chaves — ela considera só a última, e a
        // gravidade era silenciosamente ignorada.
        return $tarefas
            ->sortBy(fn (Tarefa $tarefa) => sprintf(
                '%d-%020d',
                $gravidade[$tarefa->prioridade] ?? count($gravidade),
                $this->entrouNaEtapaEm($tarefa)->getTimestamp(),
            ))
            ->values();
    }

    /**
     * Quando a tarefa entrou na etapa em que está: o evento ainda sem saída.
     * Tarefa que nunca se moveu conta a partir da criação — mesmo critério do
     * card (`_card.blade.php`).
     */
    private function entrouNaEtapaEm(Tarefa $tarefa)
    {
        return $tarefa->eventos->firstWhere('saiu_em', null)?->entrou_em ?? $tarefa->created_at;
    }

    /**
     * Token de cor da etapa, pintado na coluna — nunca no card (AC-127).
     *
     * A coluna é o lugar do status porque ela já o nomeia: repetir a cor na
     * borda de cada card diria sete vezes o que o cabeçalho diz uma, e
     * roubaria a borda do card, que é o único canal do aviso de tarefa
     * esquecida (AC-093).
     *
     * A escala segue o Funil de Vendas: entrada em `accent`, o meio do fluxo
     * na marca, o atrito em `warn`, a chegada em `good`. Cancelada fica
     * neutra de propósito — é terminal sem valor e não disputa atenção.
     */
    private function corDaEtapa(string $status): string
    {
        return match ($status) {
            'aberta', 'backlog' => 'accent',
            'em_desenvolvimento', 'em_testes' => 'brand',
            'ajustes_necessarios' => 'warn',
            'concluida' => 'good',
            default => 'line',
        };
    }
}
