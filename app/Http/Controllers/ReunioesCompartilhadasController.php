<?php

namespace App\Http\Controllers;

use App\Models\SecretarioReuniao;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A reunião vista por quem participou (docs/specs/secretario-reuniao-produto).
 * Sem rota de perfil: o acesso é estar marcado pelo dono (FR-002), e o que se
 * vê é o retrato da reunião — nunca a transcrição nem o que o dono ainda não
 * revisou (FR-004).
 */
class ReunioesCompartilhadasController extends Controller
{
    public function index(Request $request): View
    {
        $reunioes = SecretarioReuniao::compartilhadasCom($request->user())->with(['sistema:id,nome', 'dono:id,name'])
            ->orderByDesc('id')->paginate(20);

        return view('secretario.reunioes.compartilhadas', ['reunioes' => $reunioes]);
    }

    public function mostrar(Request $request, int $reuniao): View
    {
        $r = SecretarioReuniao::compartilhadasCom($request->user())->with(['sistema:id,nome', 'dono:id,name', 'compartilhadaCom:id,name'])->findOrFail($reuniao);
        $propostas = $r->propostas()->where('status', '!=', 'descartada')->get();

        return view('secretario.reunioes.compartilhada', [
            'r' => $r,
            'decisoes' => $propostas->where('categoria', 'decisao'),
            // Tarefa e pendência só depois que o dono aprovou: o resto ainda é rascunho dele.
            'tarefas' => $propostas->whereIn('categoria', ['tarefa', 'pendencia'])->where('status', 'aprovada'),
        ]);
    }
}
