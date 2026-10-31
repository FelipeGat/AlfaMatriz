<?php

namespace App\Http\Controllers;

use App\Models\SuporteRoteiro;
use App\Services\Suporte\Conhecimento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Suporte › Pedro (docs/specs/suporte-pedro-whatsapp/conhecimento.md): onde o
 * Felipe padroniza as mensagens e ensina o negócio ao Pedro. Quem tem `suporte`
 * vê o que o Pedro sabe; só os editores (`services.suporte_pedro.editores`) mudam.
 */
class SuporteConhecimentoController extends Controller
{
    public function index(Request $request, Conhecimento $conhecimento): View
    {
        return view('suporte.pedro', [
            'roteiros' => SuporteRoteiro::with('atualizadoPor:id,name')->orderBy('ordem')->orderBy('id')->get(),
            'ajustes' => collect(array_keys(Conhecimento::AJUSTES))->mapWithKeys(fn ($k) => [$k => $conhecimento->ajuste($k)]),
            'podeEditar' => $this->editor($request),
            'podeAcessos' => $this->dono($request),
            'acessos' => $this->dono($request) ? $conhecimento->ajuste(\App\Services\Suporte\AcessoInterno::AJUSTE) : '',
        ]);
    }

    /** A lista de acesso interno do Pedro: só a conta dona dele (acesso-interno.md, FR-A1). */
    public function acessos(Request $request, Conhecimento $conhecimento): RedirectResponse
    {
        abort_unless($this->dono($request), 403);
        $texto = (string) $request->validate(['acessos_internos' => ['nullable', 'string', 'max:3000']])['acessos_internos'];
        if ($ruins = \App\Services\Suporte\AcessoInterno::linhasInvalidas($texto)) {
            return back()->withInput()->withErrors(['acessos_internos' => 'Linha não entendida (use telefone | nome | diretor, gestor ou suporte): '.$ruins[0]]);
        }
        $conhecimento->gravarAjuste(\App\Services\Suporte\AcessoInterno::AJUSTE, $texto);
        // O Gestor decide Pedro ou menu antes de a mensagem chegar aqui: sem a lista lá, quem
        // é da casa recebe o menu fora do horário (FR-A11).
        $gestor = app(\App\Services\Suporte\AcessoInterno::class)->mandarAoGestor();

        return back()->with('status', $gestor === false
            ? 'Acessos internos salvos, mas o Gestor não recebeu a lista: fora do horário, quem é da casa ainda recebe o menu. Nova tentativa em até 15 minutos.'
            : 'Acessos internos salvos: valem a partir da próxima mensagem.');
    }

    private function dono(Request $request): bool
    {
        return mb_strtolower((string) $request->user()?->email) === mb_strtolower((string) config('services.suporte_pedro.conta'));
    }

    public function empresa(Request $request, Conhecimento $conhecimento): RedirectResponse
    {
        abort_unless($this->editor($request), 403);
        $d = $request->validate([
            'apresentacao' => ['nullable', 'string', 'max:4000'],
            'site' => ['nullable', 'string', 'max:1000'],
            'contatos' => ['nullable', 'string', 'max:2000'],
            'portal' => ['nullable', 'string', 'max:500'],
            'folders' => ['nullable', 'string', 'max:3000'],
            'cobranca_minutos' => ['required', 'integer', 'min:15', 'max:1440'],
        ]);
        foreach ($d as $chave => $valor) {
            $conhecimento->gravarAjuste($chave, (string) $valor);
        }

        return back()->with('status', 'Apresentação e cobrança salvas: o Pedro usa a partir da próxima mensagem.');
    }

    public function salvar(Request $request, ?int $roteiro = null): RedirectResponse
    {
        abort_unless($this->editor($request), 403);
        $r = $roteiro ? SuporteRoteiro::findOrFail($roteiro) : new SuporteRoteiro;
        $d = $request->validate([
            'titulo' => ['required', 'string', 'max:80'],
            'quando' => ['required', 'string', 'max:1000'],
            'orientacao' => ['nullable', 'string', 'max:3000'],
            'mensagem' => ['nullable', 'string', 'max:1500'],
            'prioridade' => ['nullable', Rule::in(array_keys(SuporteRoteiro::PRIORIDADES))],
            'prazo_horas' => ['nullable', 'integer', 'min:1', 'max:720'],
            'ordem' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);
        $r->fill($d + ['abre_atendimento' => $request->boolean('abre_atendimento'), 'ativo' => $request->boolean('ativo'), 'atualizado_por' => $request->user()->id]);
        $r->ordem = (int) ($d['ordem'] ?? ($r->ordem ?: (SuporteRoteiro::max('ordem') + 1)));
        $r->save();

        return redirect(route('suporte.pedro').'#roteiro-'.$r->id)->with('status', "Roteiro \"{$r->titulo}\" salvo.");
    }

    public function apagar(Request $request, int $roteiro): RedirectResponse
    {
        abort_unless($this->editor($request), 403);
        $r = SuporteRoteiro::findOrFail($roteiro);
        $r->delete();

        return redirect()->route('suporte.pedro')->with('status', "Roteiro \"{$r->titulo}\" apagado.");
    }

    private function editor(Request $request): bool
    {
        return in_array(mb_strtolower((string) $request->user()?->email), array_map('mb_strtolower', (array) config('services.suporte_pedro.editores')), true);
    }
}
