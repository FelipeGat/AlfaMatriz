<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\ClienteTelefone;
use App\Models\SuporteConversa;
use App\Models\SuporteMensagem;
use App\Models\SuportePedido;
use App\Models\User;
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
    /** Cliente esperando resposta há mais que isso: "atrasado" (o mesmo selo do Gestor). */
    public const ESPERA_MINUTOS = 15;

    public const SITUACOES = ['pedro' => 'Com o Pedro', 'humano' => 'Com humano', 'fila' => 'Na fila', 'gestor' => 'No Gestor', 'esperando' => 'Esperando resposta', 'avaliacao' => 'Aguardando avaliação'];

    /**
     * Filtros, indicadores e lista no molde da tela de WhatsApp do Gestor
     * (Felipe, 20/10/2026: "replique os filtros e informações úteis de lá"),
     * com a situação do suporte (Pedro, humano, Gestor) no lugar dos setores.
     */
    public function index(Request $request, ?int $conversa = null): View
    {
        $atual = $conversa ? SuporteConversa::with(['mensagens.autor:id,name', 'cliente.sistemas', 'cliente.contratos', 'humano:id,name', 'tarefa:id,titulo,status', 'atendimento.roteiro:id,titulo', 'atendimento.registradoPor:id,name'])->findOrFail($conversa) : null;
        // Lida antes de montar a lista, para a conversa aberta não aparecer com o contador antigo.
        if ($atual && $atual->nao_lidas > 0) {
            $atual->update(['nao_lidas' => 0]);
        }
        $filtros = [
            'busca' => trim((string) $request->query('busca', '')),
            'situacao' => array_key_exists((string) $request->query('situacao'), self::SITUACOES) ? (string) $request->query('situacao') : '',
            'atendente' => (string) $request->query('atendente', ''),
            'periodo' => in_array($request->query('periodo'), ['hoje', 'ontem', '7dias', '30dias'], true) ? (string) $request->query('periodo') : '',
            'janela' => in_array($request->query('janela'), ['aberta', 'fechada'], true) ? (string) $request->query('janela') : '',
            'nao_lidas' => $request->boolean('nao_lidas'),
        ];
        $ultima = fn (string $coluna) => SuporteMensagem::select($coluna)->whereColumn('conversa_id', 'suporte_conversas.id')->orderByDesc('id')->limit(1);
        $lista = $this->filtrar(SuporteConversa::query(), $filtros)
            ->with(['cliente:id,nome,nome_fantasia', 'humano:id,name'])
            ->addSelect(['suporte_conversas.*', 'ultimo_texto' => $ultima('texto'), 'ultima_direcao' => $ultima('direcao'), 'ultimo_tipo' => $ultima('tipo')])
            ->orderByDesc('ultima_mensagem_em')->limit(100)->get();

        return view('suporte.conversas', [
            'lista' => $lista, 'atual' => $atual, 'configurado' => app(CanalWhatsApp::class)->configurado(),
            'clientes' => $atual && ! $atual->cliente_id ? Cliente::where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'nome_fantasia']) : collect(),
            'filtros' => $filtros, 'indicadores' => $this->indicadores(),
            'atendentes' => User::whereIn('id', SuporteConversa::whereNotNull('humano_id')->distinct()->pluck('humano_id')->push($request->user()->id))->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Cliente falou por último e ninguém respondeu há mais de ESPERA_MINUTOS. */
    public static function atrasada(SuporteConversa $c): bool
    {
        return $c->situacao !== 'gestor' && $c->ultima_entrada_em && $c->ultima_mensagem_em
            && $c->ultima_entrada_em->gte($c->ultima_mensagem_em) && $c->ultima_entrada_em->lt(now()->subMinutes(self::ESPERA_MINUTOS))
            && $c->janelaAberta();
    }

    private function filtrar($q, array $f)
    {
        if ($f['busca'] !== '') {
            $termo = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $f['busca']).'%';
            $digitos = preg_replace('/\D/', '', $f['busca']);
            $q->where(fn ($w) => $w->where('nome_perfil', 'like', $termo)
                ->when(strlen($digitos) >= 4, fn ($w) => $w->orWhere('telefone', 'like', '%'.$digitos.'%'))
                ->orWhereHas('cliente', fn ($c) => $c->where('nome', 'like', $termo)->orWhere('nome_fantasia', 'like', $termo)));
        }
        match ($f['situacao']) {
            'pedro' => $q->where('situacao', 'pedro'),
            'humano' => $q->where('situacao', 'humano')->where(fn ($w) => $w->whereNull('humano_ate')->orWhere('humano_ate', '>', now())),
            'gestor' => $q->where('situacao', 'gestor'),
            // Esperando uma pessoa: passou pelo Pedro e ninguém assumiu.
            'fila' => $q->where('situacao', 'humano')->whereNull('humano_id')->whereNotNull('fila_desde')->where('humano_ate', '>', now()),
            'avaliacao' => $q->where('situacao', 'avaliacao'),
            'esperando' => $q->where('situacao', '!=', 'gestor')->whereColumn('ultima_entrada_em', '>=', 'ultima_mensagem_em')
                ->where('ultima_entrada_em', '<', now()->subMinutes(self::ESPERA_MINUTOS))
                ->where('ultima_entrada_em', '>=', now()->subHours(SuporteConversa::JANELA_HORAS)),
            default => null,
        };
        if ($f['atendente'] === 'sem') {
            $q->whereNull('humano_id');
        } elseif (ctype_digit($f['atendente'])) {
            $q->where('humano_id', (int) $f['atendente']);
        }
        match ($f['periodo']) {
            'hoje' => $q->where('ultima_mensagem_em', '>=', today()),
            'ontem' => $q->whereBetween('ultima_mensagem_em', [today()->subDay(), today()]),
            '7dias' => $q->where('ultima_mensagem_em', '>=', today()->subDays(6)),
            '30dias' => $q->where('ultima_mensagem_em', '>=', today()->subDays(29)),
            default => null,
        };
        $limite = now()->subHours(SuporteConversa::JANELA_HORAS);
        match ($f['janela']) {
            'aberta' => $q->where('ultima_entrada_em', '>', $limite),
            'fechada' => $q->where(fn ($w) => $w->whereNull('ultima_entrada_em')->orWhere('ultima_entrada_em', '<=', $limite)),
            default => null,
        };
        if ($f['nao_lidas']) {
            $q->where('nao_lidas', '>', 0);
        }

        return $q;
    }

    /** Os números do dia, sobre as mensagens e os pedidos do Pedro (não sobre a lista filtrada). */
    private function indicadores(): array
    {
        $ativas = SuporteConversa::where('ultima_entrada_em', '>', now()->subHours(SuporteConversa::JANELA_HORAS))->get();
        $hoje = SuporteMensagem::where('direcao', 'entrada')->where('created_at', '>=', today());
        // 1ª resposta: da primeira mensagem do cliente no dia até a primeira resposta depois dela.
        $tempos = [];
        foreach ((clone $hoje)->selectRaw('conversa_id, MIN(id) as primeira')->groupBy('conversa_id')->get() as $r) {
            $entrada = SuporteMensagem::find($r->primeira);
            $resposta = SuporteMensagem::where('conversa_id', $r->conversa_id)->where('id', '>', $r->primeira)->where('direcao', '!=', 'entrada')->orderBy('id')->first();
            if ($entrada && $resposta) {
                $tempos[] = $entrada->created_at->diffInSeconds($resposta->created_at);
            }
        }
        $pedidos = fn (string $acao) => SuportePedido::where('acao', $acao)->where('created_at', '>=', today())->count();

        return [
            'recebidas_hoje' => (clone $hoje)->distinct()->count('conversa_id'),
            'com_pedro' => $ativas->where('situacao', 'pedro')->count(),
            'com_humano' => $ativas->filter->comHumano()->count(),
            'no_gestor' => $ativas->where('situacao', 'gestor')->count(),
            'esperando' => $ativas->filter(fn ($c) => self::atrasada($c))->count(),
            'primeira_resposta' => $tempos ? (int) round(array_sum($tempos) / count($tempos)) : null,
            'especialista_hoje' => $pedidos('especialista'),
            'atendimentos_hoje' => $pedidos('abrir_atendimento'),
            'na_fila' => $ativas->filter(fn ($c) => $c->situacao === 'humano' && ! $c->humano_id && $c->fila_desde && $c->comHumano())->count(),
            // A nota média dos últimos 30 dias e quantas responderam (encerramento.md).
            'avaliacao' => \App\Models\SuporteAvaliacao::whereNotNull('nota')->where('respondida_em', '>=', now()->subDays(30))->selectRaw('AVG(nota) as media, COUNT(*) as n')->first(),
        ];
    }

    /** A foto, o áudio ou o vídeo da mensagem, para a tela (do disco, ou buscado no Gestor). */
    public function midia(int $mensagem, \App\Services\Suporte\Midias $midias): \Symfony\Component\HttpFoundation\Response
    {
        $m = SuporteMensagem::whereIn('tipo', ['image', 'audio', 'video'])->findOrFail($mensagem);
        $bytes = $midias->bytes($m);
        abort_unless($bytes !== null, 404);

        return response($bytes, 200, ['Content-Type' => $m->fresh()->midia_mime ?: 'application/octet-stream', 'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => 'inline']);
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

    /** Encerra o atendimento e pede a avaliação ao cliente (encerramento.md). */
    public function encerrar(Request $request, int $conversa, \App\Services\Suporte\Encerramento $encerramento): RedirectResponse
    {
        $encerramento->encerrar(SuporteConversa::findOrFail($conversa), $request->user());

        return back()->with('status', 'Atendimento encerrado; a avaliação foi pedida ao cliente.');
    }

    public function devolver(int $conversa): RedirectResponse
    {
        $c = SuporteConversa::with('atendimento', 'tarefa')->findOrFail($conversa);
        if ($c->atendimento?->faltaNumeroDoGestor() && $c->tarefa && ! in_array($c->tarefa->status, \App\Models\Tarefa::STATUS_TERMINAIS, true)) {
            return back()->withErrors(['gestor_numero' => 'Antes de devolver ao Pedro, lance o atendimento no Gestor e informe o número aqui.']);
        }
        $c->update(['situacao' => 'pedro', 'humano_id' => null, 'humano_ate' => null, 'fila_desde' => null, 'fila_lembrada_em' => null]);

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
