<?php

namespace App\Http\Controllers;

use App\Models\CentralIaRunner;
use App\Models\SecretarioReuniao;
use App\Models\Notificacao;
use App\Models\SecretarioReuniaoProposta;
use App\Models\Sistema;
use App\Models\User;
use App\Services\Secretario\CentralDeGestao;
use App\Services\Secretario\AnaliseDeReuniao;
use App\Services\Secretario\Reunioes;
use App\Services\Secretario\RevisaoDeReuniao;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Secretário → Reuniões (docs/specs/secretario-reunioes). Toda consulta por
 * `de($request->user())`: reunião, transcrição, proposta ou ata de outra conta,
 * administrador incluído, é 404 (constitution I).
 */
class SecretarioReunioesController extends Controller
{
    /** Abas da lista (desenho de 17/10/2026) => situações da reunião. */
    public const ABAS = [
        'todas' => ['Todas', null],
        'processando' => ['Processando', [SecretarioReuniao::AGUARDANDO, SecretarioReuniao::TRANSCREVENDO, SecretarioReuniao::ANALISANDO]],
        'revisar' => ['Aguardando revisão', [SecretarioReuniao::PRONTA]],
        'revisadas' => ['Revisadas', [SecretarioReuniao::REVISADA]],
        'falhas' => ['Com falha', [SecretarioReuniao::FALHOU]],
    ];

    public function index(Request $request): View
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:120'], 'de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d'],
            'empresa' => ['nullable', Rule::in(array_keys(CentralDeGestao::EMPRESAS))], 'aba' => ['nullable', Rule::in(array_keys(self::ABAS))],
        ]);
        $busca = trim((string) ($f['q'] ?? ''));
        $aba = $f['aba'] ?? 'todas';
        $base = SecretarioReuniao::de($request->user())->where('modo', 'reuniao')
            ->when($busca !== '', function ($q) use ($busca) {
                $termo = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $busca).'%';
                $q->where(fn ($w) => $w->where('titulo', 'like', $termo)->orWhere('resumo', 'like', $termo)
                    ->orWhereHas('trechos', fn ($t) => $t->where('texto', 'like', $termo)));
            })
            ->when($f['de'] ?? null, fn ($q, $de) => $q->where('created_at', '>=', $de.' 00:00:00'))
            ->when($f['ate'] ?? null, fn ($q, $ate) => $q->where('created_at', '<=', $ate.' 23:59:59'))
            ->when($f['empresa'] ?? null, fn ($q, $e) => $q->where('empresa', $e));
        $contagem = collect(self::ABAS)->map(fn ($a) => (clone $base)->when($a[1], fn ($q, $st) => $q->whereIn('status', $st))->count());
        $reunioes = (clone $base)->when(self::ABAS[$aba][1], fn ($q, $st) => $q->whereIn('status', $st))
            ->withCount(['propostas as tarefas_pendentes' => fn ($q) => $q->whereIn('categoria', ['tarefa', 'pendencia'])->where('status', 'pendente')])
            ->orderByDesc('id')->paginate(20)->withQueryString();

        return view('secretario.reunioes.index', [
            'reunioes' => $reunioes, 'busca' => $busca, 'filtros' => $f + ['aba' => $aba], 'contagem' => $contagem,
            'maquinaOnline' => CentralIaRunner::where('user_id', $request->user()->id)->get()->contains(fn ($r) => $r->online()),
            'produtos' => self::produtos()->get(['id', 'nome']),
            'compartilhadas' => SecretarioReuniao::compartilhadasCom($request->user())->count(),
        ]);
    }

    public function enviar(Request $request, Reunioes $reunioes): JsonResponse|RedirectResponse
    {
        $dados = $request->validate([
            'audio' => ['required', 'file', 'max:12288'],
            'origem' => ['nullable', Rule::in(['upload', 'gravacao'])],
            'empresa' => ['nullable', Rule::in(array_keys(CentralDeGestao::EMPRESAS))],
        ]);
        $arquivo = $dados['audio'];
        $r = $reunioes->receber($request->user(), $arquivo->getRealPath(), $arquivo->getClientOriginalName(), $dados['origem'] ?? 'upload');
        if (! empty($dados['empresa'])) {
            $r->update(['empresa' => $dados['empresa']]);
        }

        return $request->expectsJson()
            ? response()->json(['id' => $r->id, 'url' => route('secretario.reunioes.mostrar', $r->id)], 201)
            : redirect()->route('secretario.reunioes.mostrar', $r->id);
    }

    public function mostrar(Request $request, int $reuniao): View|JsonResponse
    {
        $r = SecretarioReuniao::de($request->user())->with(['trechos', 'propostas'])->findOrFail($reuniao);
        if ($request->query('formato') === 'json') {
            return response()->json(['status' => $r->status, 'rotulo' => SecretarioReuniao::ROTULOS_STATUS[$r->status] ?? $r->status, 'erro' => $r->erro,
                'andamento' => $r->andamento()]);
        }

        return view('secretario.reunioes.mostrar', ['r' => $r, 'produtos' => self::produtos()->get(['id', 'nome']),
            'pessoas' => User::where('ativo', true)->whereKeyNot($r->user_id)->orderBy('name')->get(['id', 'name']),
            'marcados' => $r->compartilhadaCom()->pluck('users.id')->all(), 'sugeridos' => $r->sugestaoDeParticipantes()]);
    }

    public function ata(Request $request, int $reuniao): View
    {
        $r = SecretarioReuniao::de($request->user())->with(['trechos', 'propostas'])->findOrFail($reuniao);
        abort_unless(in_array($r->status, [SecretarioReuniao::PRONTA, SecretarioReuniao::REVISADA], true) && $r->resumo, 404);

        return view('secretario.reunioes.ata', ['r' => $r]);
    }

    public function analisarDeNovo(Request $request, int $reuniao, AnaliseDeReuniao $analise): RedirectResponse
    {
        $r = SecretarioReuniao::de($request->user())->findOrFail($reuniao);
        abort_unless($r->status === SecretarioReuniao::FALHOU && $r->transcrita_em && $r->modo === 'reuniao', 409);
        $analise->pedir($request->user(), $r);

        return redirect()->route('secretario.reunioes.mostrar', $r->id);
    }

    public function proposta(Request $request, int $proposta, RevisaoDeReuniao $revisao): JsonResponse
    {
        $dados = $request->validate([
            'acao' => ['required', Rule::in(['aprovar', 'descartar', 'editar'])],
            'texto' => ['sometimes', 'string', 'min:1', 'max:500'],
            'responsavel' => ['sometimes', 'nullable', 'string', 'max:120'],
            'prazo' => ['sometimes', 'nullable', 'regex:/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/'],
            'prioridade' => ['sometimes', Rule::in(['baixa', 'normal', 'alta'])],
        ]);
        $p = SecretarioReuniaoProposta::where('user_id', $request->user()->id)->findOrFail($proposta);
        if (array_intersect_key($dados, array_flip(['texto', 'responsavel', 'prazo', 'prioridade']))) {
            $p = $revisao->editar($request->user(), $p, $dados);
        }
        match ($dados['acao']) {
            'aprovar' => $p = $revisao->aprovar($request->user(), $p),
            'descartar' => $revisao->descartar($request->user(), $p),
            default => null,
        };

        return response()->json(['status' => $p->fresh()->status, 'item_id' => $p->fresh()->item_id]);
    }

    public function aprovarLote(Request $request, int $reuniao, RevisaoDeReuniao $revisao): JsonResponse
    {
        $r = SecretarioReuniao::de($request->user())->findOrFail($reuniao);

        return response()->json(['aprovadas' => $revisao->aprovarLote($request->user(), $r)]);
    }

    /** O produto da reunião (secretario-reuniao-produto): só o dono troca (FR-001). */
    public function produto(Request $request, int $reuniao): RedirectResponse
    {
        $d = $request->validate(['sistema_id' => ['nullable', 'integer']]);
        $r = SecretarioReuniao::de($request->user())->findOrFail($reuniao);
        $id = $d['sistema_id'] ?? null;
        abort_if($id && ! self::produtos()->whereKey($id)->exists(), 422);
        $r->update(['sistema_id' => $id ?: null]);

        return back();
    }

    /**
     * Com quem participou e tem conta (FR-002, FR-003): marcar dá acesso e avisa
     * no sino; desmarcar tira o acesso na hora. Cliente não tem conta, não entra.
     */
    public function participantes(Request $request, int $reuniao): RedirectResponse
    {
        $r = SecretarioReuniao::de($request->user())->findOrFail($reuniao);
        $ids = $request->validate(['pessoas' => ['nullable', 'array'], 'pessoas.*' => ['integer']])['pessoas'] ?? [];
        $validos = User::where('ativo', true)->whereKeyNot($r->user_id)->whereIn('id', $ids)->pluck('id');
        $antes = $r->compartilhadaCom()->pluck('users.id');
        $r->compartilhadaCom()->sync($validos);
        foreach ($validos->diff($antes) as $novo) {
            Notificacao::create(['destinatario_id' => $novo, 'tipo' => 'lembrete', 'nivel' => 'info', 'icone' => 'document',
                'titulo' => Notificacao::caber($request->user()->name.' compartilhou a reunião "'.$r->titulo.'"'), 'meta' => 'Reuniões',
                'rota' => route('reunioes.compartilhada', $r->id)]);
        }

        return back()->with('status', $validos->isEmpty() ? 'A reunião não está compartilhada com ninguém.' : 'Compartilhada com '.$validos->count().' pessoa(s).');
    }

    /**
     * A reunião que aconteceu sem estar na Agenda vira compromisso dela (Felipe, 22/10/2026: a da
     * Medsenior). Passa pelas regras da Agenda (`AgendaService::marcar`), com quem participou.
     */
    public function agendar(Request $request, int $reuniao, \App\Services\AgendaService $agenda): RedirectResponse
    {
        $r = SecretarioReuniao::de($request->user())->findOrFail($reuniao);
        abort_if($r->compromisso_id && $r->compromisso, 409, 'Esta reunião já está na agenda.');
        $d = $request->validate(['data' => ['required', 'date'], 'hora' => ['required', 'date_format:H:i'], 'hora_fim' => ['required', 'date_format:H:i', 'after:hora'],
            'pessoas' => ['nullable', 'array'], 'pessoas.*' => ['integer']]);
        $pessoas = User::where('ativo', true)->whereIn('id', $d['pessoas'] ?? [])->pluck('id')->push($request->user()->id)->unique()->values()->all();
        $c = $agenda->marcar(['titulo' => mb_substr((string) $r->titulo, 0, 150) ?: 'Reunião', 'categoria' => 'cliente',
            'descricao' => 'Reunião gravada em Secretário → Reuniões (#'.$r->id.').', 'data' => $d['data'], 'hora' => $d['hora'],
            'duracao_modo' => false, 'data_fim' => $d['data'], 'hora_fim' => $d['hora_fim'], 'participantes' => $pessoas], $request->user());
        $r->update(['compromisso_id' => $c->id]);

        return back()->with('status', 'Reunião marcada na agenda: '.$c->data->format('d/m').', das '.$d['hora'].' às '.$d['hora_fim'].'.');
    }

    /** Os produtos que podem ser vinculados: o catálogo inteiro, VixCard inclusive. */
    public static function produtos(): \Illuminate\Database\Eloquent\Builder
    {
        // Era ['produto', 'vixcard']; a VixCard saiu do Matriz em 31/10/2026.
        return Sistema::whereIn('natureza', ['produto' /* , 'vixcard' */])->where('ativo', true)->orderBy('nome');
    }

    /** A empresa da reunião, trocada na própria lista. */
    public function empresa(Request $request, int $reuniao): RedirectResponse
    {
        $d = $request->validate(['empresa' => ['nullable', Rule::in(array_keys(CentralDeGestao::EMPRESAS))]]);
        SecretarioReuniao::de($request->user())->findOrFail($reuniao)->update(['empresa' => $d['empresa'] ?: null]);

        return back();
    }
}
