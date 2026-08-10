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

        $tarefas = Tarefa::with(['sistema', 'responsavel'])
            ->orderByDesc('created_at')
            ->get();

        $colunas = collect(Tarefa::STATUS)->mapWithKeys(fn ($label, $status) => [
            $status => $tarefas->where('status', $status)->values(),
        ]);

        $etapas = collect(Tarefa::STATUS)->map(fn ($label, $status) => [
            'chave' => $status,
            'label' => $label,
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

        return view('tarefas.historico');
    }
}
