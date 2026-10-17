<?php

namespace App\Http\Controllers;

use App\Models\CentralIaRunner;
use App\Models\SecretarioReuniao;
use App\Models\SecretarioReuniaoProposta;
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
    public function index(Request $request): View
    {
        $busca = trim((string) $request->query('q', ''));
        $reunioes = SecretarioReuniao::de($request->user())->where('modo', 'reuniao')
            ->when($busca !== '', function ($q) use ($busca) {
                $termo = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $busca).'%';
                $q->where(fn ($w) => $w->where('titulo', 'like', $termo)->orWhere('resumo', 'like', $termo)
                    ->orWhereHas('trechos', fn ($t) => $t->where('texto', 'like', $termo)));
            })
            ->withCount(['propostas as tarefas_pendentes' => fn ($q) => $q->whereIn('categoria', ['tarefa', 'pendencia'])->where('status', 'pendente')])
            ->orderByDesc('id')->paginate(20)->withQueryString();

        return view('secretario.reunioes.index', [
            'reunioes' => $reunioes, 'busca' => $busca,
            'maquinaOnline' => CentralIaRunner::where('user_id', $request->user()->id)->get()->contains(fn ($r) => $r->online()),
        ]);
    }

    public function enviar(Request $request, Reunioes $reunioes): JsonResponse|RedirectResponse
    {
        $dados = $request->validate([
            'audio' => ['required', 'file', 'max:12288'],
            'origem' => ['nullable', Rule::in(['upload', 'gravacao'])],
        ]);
        $arquivo = $dados['audio'];
        $r = $reunioes->receber($request->user(), $arquivo->getRealPath(), $arquivo->getClientOriginalName(), $dados['origem'] ?? 'upload');

        return $request->expectsJson()
            ? response()->json(['id' => $r->id, 'url' => route('secretario.reunioes.mostrar', $r->id)], 201)
            : redirect()->route('secretario.reunioes.mostrar', $r->id);
    }

    public function mostrar(Request $request, int $reuniao): View|JsonResponse
    {
        $r = SecretarioReuniao::de($request->user())->with(['trechos', 'propostas'])->findOrFail($reuniao);
        if ($request->query('formato') === 'json') {
            return response()->json(['status' => $r->status, 'rotulo' => SecretarioReuniao::ROTULOS_STATUS[$r->status] ?? $r->status, 'erro' => $r->erro]);
        }

        return view('secretario.reunioes.mostrar', ['r' => $r]);
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
}
