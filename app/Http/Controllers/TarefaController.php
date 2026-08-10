<?php

namespace App\Http\Controllers;

use App\Models\Tarefa;
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

        return view('tarefas.index', compact('tarefas', 'colunas', 'etapas'));
    }

    public function store(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'titulo' => 'required|string|max:255',
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
        ]);

        $fluxo->mover($tarefa, $data['status'], ['motivo' => $data['motivo'] ?? null]);

        return redirect()->route('tarefas.index')->with('status', 'Tarefa movida.');
    }

    public function historico(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        return view('tarefas.historico');
    }
}
