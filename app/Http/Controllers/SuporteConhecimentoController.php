<?php

namespace App\Http\Controllers;

use App\Models\DocumentoPasta;
use App\Models\SuporteRoteiro;
use App\Services\Suporte\Conhecimento;
use App\Services\Suporte\ContextoDoSuporte;
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
            'base' => $this->resumoDaBase(),
            // Só as pastas que o editor já vê em Documentos: a escolha não revela pasta de ninguém (documentos-espelhados FR-001).
            'pastasDaBase' => $this->editor($request) ? DocumentoPasta::visiveisPara($request->user())->orderBy('nome')->get(['id', 'nome', 'user_id']) : collect(),
        ]);
    }

    /**
     * A base "Contexto do Suporte" (T-476; contexto-do-suporte.md): o editor
     * escolhe qual pasta espelhada o Pedro lê. Vazio desliga — o Pedro volta a
     * usar só a apresentação e os roteiros.
     */
    public function base(Request $request, Conhecimento $conhecimento): RedirectResponse
    {
        abort_unless($this->editor($request), 403);
        $id = $request->validate(['base_pasta' => ['nullable', 'integer']])['base_pasta'] ?? null;
        if ($id && ! DocumentoPasta::visiveisPara($request->user())->whereKey($id)->exists()) {
            return back()->withErrors(['base_pasta' => 'Escolha uma pasta espelhada que você vê em Documentos.']);
        }
        $conhecimento->gravarAjuste(ContextoDoSuporte::AJUSTE, $id ? (string) $id : '');

        return back()->with('status', $id ? 'Base de conhecimento ligada: o Pedro a consulta a partir da próxima mensagem.' : 'Base de conhecimento desligada.');
    }

    /** @return array{pasta: DocumentoPasta, notas: int, clientes: int}|null */
    private function resumoDaBase(): ?array
    {
        $pasta = app(ContextoDoSuporte::class)->pasta();
        if (! $pasta) {
            return null;
        }
        $caminhos = $pasta->documentos()->where('extensao', 'md')->pluck('caminho');

        return ['pasta' => $pasta, 'notas' => $caminhos->count(),
            'clientes' => $caminhos->filter(fn ($c) => preg_match('#^'.preg_quote(ContextoDoSuporte::CLIENTES, '#').'[^/]+/contexto\.md$#u', $c))->count()];
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

        return back()->with('status', 'Acessos internos salvos: valem a partir da próxima mensagem.');
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
