<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\ClienteTelefone;
use App\Models\SuporteConversa;
use App\Services\Suporte\CanalWhatsApp;
use App\Services\Suporte\DiretorioGestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Suporte › WhatsApp (docs/specs/suporte-pedro-whatsapp): as conversas do número
 * de suporte, com o cliente e o que ele tem ao lado. Quem responde aqui assume a
 * conversa — o Pedro fica quieto nela até alguém a devolver.
 */
class SuporteConversasController extends Controller
{
    public function index(Request $request, ?int $conversa = null): View
    {
        $atual = $conversa ? SuporteConversa::with(['mensagens.autor:id,name', 'cliente.sistemas', 'cliente.contratos', 'humano:id,name', 'tarefa:id,titulo,status', 'atendimento.roteiro:id,titulo', 'atendimento.registradoPor:id,name'])->findOrFail($conversa) : null;
        // Lida antes de montar a lista, para a conversa aberta não aparecer com o contador antigo.
        if ($atual && $atual->nao_lidas > 0) {
            $atual->update(['nao_lidas' => 0]);
        }
        $lista = SuporteConversa::with('cliente:id,nome,nome_fantasia')->orderByDesc('ultima_mensagem_em')->limit(100)->get();

        return view('suporte.conversas', [
            'lista' => $lista, 'atual' => $atual, 'configurado' => app(CanalWhatsApp::class)->configurado(),
            'clientes' => $atual && ! $atual->cliente_id ? Cliente::where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'nome_fantasia']) : collect(),
        ]);
    }

    /** Para a tela recarregar sozinha só quando chegou coisa nova. */
    public function marca(): JsonResponse
    {
        return response()->json(['marca' => (string) SuporteConversa::max('updated_at')]);
    }

    public function responder(Request $request, int $conversa, CanalWhatsApp $canal): RedirectResponse
    {
        $c = SuporteConversa::findOrFail($conversa);
        $d = $request->validate(['texto' => ['required', 'string', 'max:4000']]);
        $canal->enviar($c, trim($d['texto']), 'humano_matriz', $request->user());
        $c->update(['situacao' => 'humano', 'humano_id' => $request->user()->id, 'humano_ate' => now()->addHours(SuporteConversa::HUMANO_HORAS)]);

        return back();
    }

    public function assumir(Request $request, int $conversa): RedirectResponse
    {
        SuporteConversa::findOrFail($conversa)->update(['situacao' => 'humano', 'humano_id' => $request->user()->id, 'humano_ate' => now()->addHours(SuporteConversa::HUMANO_HORAS)]);

        return back()->with('status', 'Você assumiu a conversa: o Pedro não responde nela por '.SuporteConversa::HUMANO_HORAS.' h ou até você devolver.');
    }

    public function devolver(int $conversa): RedirectResponse
    {
        $c = SuporteConversa::with('atendimento', 'tarefa')->findOrFail($conversa);
        if ($c->atendimento?->faltaNumeroDoGestor() && $c->tarefa && ! in_array($c->tarefa->status, \App\Models\Tarefa::STATUS_TERMINAIS, true)) {
            return back()->withErrors(['gestor_numero' => 'Antes de devolver ao Pedro, lance o atendimento no Gestor e informe o número aqui.']);
        }
        $c->update(['situacao' => 'pedro', 'humano_id' => null, 'humano_ate' => null]);

        return back()->with('status', 'Conversa devolvida ao Pedro.');
    }

    /**
     * O nº do atendimento lançado no Gestor (obrigatório quando o destino é o
     * Gestor). Confere no retrato: número que lá é de OUTRO cliente é recusado;
     * número que ainda não aparece (o retrato é de até 10 min atrás) é aceito.
     */
    public function gestorNumero(Request $request, int $conversa, DiretorioGestor $diretorio): RedirectResponse
    {
        $c = SuporteConversa::with('atendimento')->findOrFail($conversa);
        abort_unless($c->atendimento, 404);
        $d = $request->validate(['gestor_numero' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-\/.]+$/']],
            ['gestor_numero.regex' => 'Informe só o número do atendimento, como aparece no Gestor.']);
        $numero = trim($d['gestor_numero']);
        $noGestor = $diretorio->atendimentoPorNumero($numero);
        $clienteGestor = $c->atendimento->gestor_cliente_id ?? $c->gestor_cliente_id;
        if ($noGestor && $clienteGestor && (int) ($noGestor['cliente_id'] ?? 0) !== (int) $clienteGestor) {
            return back()->withErrors(['gestor_numero' => "O atendimento nº {$numero} é de outro cliente no Gestor. Confira o número."]);
        }
        $c->atendimento->update(['gestor_numero' => $numero, 'gestor_registrado_por' => $request->user()->id, 'gestor_registrado_em' => now(), 'destino' => 'gestor']);
        $c->update(['gestor_atendimento' => $numero]);

        return back()->with('status', "Atendimento do Gestor nº {$numero} registrado.".($noGestor ? '' : ' Ele ainda não aparece no retrato do Gestor (lido a cada 10 min).'));
    }

    /** Ligar o número ao cliente é ato humano: daqui em diante o Pedro o reconhece. */
    public function vincular(Request $request, int $conversa): RedirectResponse
    {
        $c = SuporteConversa::findOrFail($conversa);
        $d = $request->validate(['cliente_id' => ['required', 'integer', 'exists:clientes,id']]);
        $c->update(['cliente_id' => $d['cliente_id']]);
        $ja = ClienteTelefone::where('cliente_id', $d['cliente_id'])->get()->contains(fn ($t) => CanalWhatsApp::chave((string) $t->telefone) === CanalWhatsApp::chave($c->telefone));
        if (! $ja) {
            ClienteTelefone::create(['cliente_id' => $d['cliente_id'], 'telefone' => $c->telefoneLegivel(), 'principal' => false]);
        }

        return back()->with('status', 'Número ligado ao cliente'.($ja ? '.' : ' e acrescentado aos telefones dele.'));
    }
}
