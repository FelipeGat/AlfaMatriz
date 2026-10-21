<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\SuporteNota;
use App\Services\Suporte\BaseDoSuporte;
use App\Services\Suporte\DiretorioGestor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Suporte › Base (T-476; docs/specs/suporte-pedro-whatsapp/base-do-suporte.md): o
 * que o Pedro consulta, e por onde a equipe confirma os registros que ele propõe.
 * Quem tem `suporte` vê; quem edita no suporte escreve e confirma; validar
 * procedimento, conhecimento e empresa é dos editores do Pedro e dos administradores.
 */
class SuporteBaseController extends Controller
{
    public function __construct(private BaseDoSuporte $base) {}

    public function index(Request $request): View
    {
        $f = $request->validate(['tipo' => ['nullable', Rule::in(array_keys(SuporteNota::TIPOS))], 'q' => ['nullable', 'string', 'max:120'],
            'validacao' => ['nullable', Rule::in(['rascunho', 'validado'])]]);
        $notas = SuporteNota::ativas()->with(['atualizadoPor:id,name', 'validadoPor:id,name', 'cliente:id,nome,nome_fantasia'])
            ->when($f['tipo'] ?? null, fn ($q, $t) => $q->where('tipo', $t))
            ->when($f['validacao'] ?? null, fn ($q, $v) => $q->whereIn('tipo', SuporteNota::GERAIS)->where('validacao', $v))
            // Todas as palavras, inteiras ou no começo ("port" acha "portão"), como a busca do quadro.
            ->when(filled($f['q'] ?? null), function ($q) use ($f) {
                foreach (array_filter(explode(' ', trim(SuporteNota::indexar($f['q'])))) as $p) {
                    $q->where('termos', 'like', '% '.$p.'%');
                }
            })
            ->orderByDesc('updated_at')->paginate(30)->withQueryString();

        return view('suporte.base', [
            'notas' => $notas,
            'filtros' => $f,
            'propostas' => SuporteNota::propostas()->with(['conversa:id,telefone,nome_perfil,cliente_id,humano_id', 'conversa.cliente:id,nome,nome_fantasia'])->orderBy('id')->get(),
            'gestor' => $this->nomesNoGestor($notas->getCollection()),
            'podeEscrever' => $request->user()->canPermissao('suporte', 'editar'),
        ]);
    }

    public function nova(Request $request): View
    {
        $tipo = $request->validate(['tipo' => ['required', Rule::in(array_keys(SuporteNota::TIPOS))]])['tipo'];

        return $this->formulario($request, new SuporteNota(['tipo' => $tipo, 'corpo' => SuporteNota::MODELOS[$tipo], 'situacao' => $tipo === 'atendimento' ? 'em_curso' : null]));
    }

    public function ver(Request $request, int $nota): View
    {
        return $this->formulario($request, SuporteNota::with(['conversa', 'tarefa:id,status', 'origem:id,titulo', 'validadoPor:id,name',
            'confirmadoPor:id,name', 'autor:id,name', 'atualizadoPor:id,name'])->findOrFail($nota));
    }

    public function criar(Request $request): RedirectResponse
    {
        $tipo = $request->validate(['tipo' => ['required', Rule::in(array_keys(SuporteNota::TIPOS))]])['tipo'];
        $n = $this->base->salvar(new SuporteNota(['tipo' => $tipo]), $this->dados($request, $tipo), $request->user());

        return redirect()->route('suporte.base.ver', $n->id)->with('status', 'Nota salva'.($n->geral() ? ' como rascunho: o Pedro só a segue como instrução depois de validada.' : '.'));
    }

    public function salvar(Request $request, int $nota): RedirectResponse
    {
        $n = SuporteNota::where('estado', '!=', 'descartada')->findOrFail($nota);
        // A proposta só sai do estado de proposta pelo "Confirmar", que confere quem pode.
        if ($n->estado === 'proposta') {
            abort_unless($this->base->podeConfirmar($request->user(), $n), 403);
        }
        $estava = $n->validada();
        $this->base->salvar($n, $this->dados($request, $n->tipo, $n), $request->user());

        return back()->with('status', $estava && ! $n->validada() ? 'Nota salva. Ela voltou a rascunho: alguém que valida precisa conferir a mudança.' : 'Nota salva.');
    }

    /** O registro que o Pedro propôs entra na base: confirma quem atendeu (decisão do dono, 21/10/2026). */
    public function confirmar(Request $request, int $nota): RedirectResponse
    {
        $n = SuporteNota::propostas()->findOrFail($nota);
        abort_unless($this->base->podeConfirmar($request->user(), $n), 403, 'Quem confirma este registro é quem atendeu a conversa.');
        $this->base->confirmar($n, $this->dados($request, $n->tipo, $n), $request->user());

        return redirect()->route('suporte.base')->with('status', "Registro {$n->codigo} confirmado: o Pedro já o usa na próxima conversa deste cliente.");
    }

    public function descartar(Request $request, int $nota): RedirectResponse
    {
        $n = SuporteNota::propostas()->findOrFail($nota);
        abort_unless($this->base->podeConfirmar($request->user(), $n), 403, 'Quem descarta este registro é quem atendeu a conversa.');
        $n->forceFill(['estado' => 'descartada', 'confirmado_por' => $request->user()->id, 'confirmado_em' => now()])->save();

        return redirect()->route('suporte.base')->with('status', "Registro {$n->codigo} descartado.");
    }

    public function validar(Request $request, int $nota): RedirectResponse
    {
        abort_unless($this->base->podeValidar($request->user()), 403, 'Só o Felipe ou um administrador valida.');
        $n = SuporteNota::ativas()->whereIn('tipo', SuporteNota::GERAIS)->findOrFail($nota);
        $this->base->validar($n, $request->user());

        return back()->with('status', 'Validada: o Pedro passa a seguir esta nota como instrução.');
    }

    public function conhecimento(Request $request, int $nota): RedirectResponse
    {
        $n = SuporteNota::ativas()->where('tipo', 'atendimento')->findOrFail($nota);
        $novo = $this->base->virarConhecimento($n, $request->user());

        return redirect()->route('suporte.base.ver', $novo->id)->with('status', 'Conhecimento criado como rascunho. Tire o que é só deste cliente e peça a validação.');
    }

    public function apagar(Request $request, int $nota): RedirectResponse
    {
        abort_unless($this->base->podeValidar($request->user()), 403, 'Só o Felipe ou um administrador apaga nota da base.');
        $n = SuporteNota::where('estado', 'ativa')->findOrFail($nota);
        $n->delete();

        return redirect()->route('suporte.base')->with('status', "Nota \"{$n->titulo}\" apagada.");
    }

    private function formulario(Request $request, SuporteNota $n): View
    {
        $u = $request->user();
        $podeEscrever = $u->canPermissao('suporte', 'editar') && $n->estado !== 'descartada'
            && ($n->estado !== 'proposta' || $this->base->podeConfirmar($u, $n));

        return view('suporte.base-nota', [
            'nota' => $n,
            'podeEscrever' => $podeEscrever,
            'podeValidar' => $this->base->podeValidar($u),
            'quemConfirma' => $n->estado === 'proposta' && $n->conversa ? $this->base->quemAtendeu($n->conversa) : null,
            'clientes' => in_array($n->tipo, ['cliente', 'atendimento'], true) ? Cliente::where('ativo', true)->orderBy('nome')->get(['id', 'nome', 'nome_fantasia']) : collect(),
            'gestor' => $this->nomesNoGestor(collect([$n])),
        ]);
    }

    /** @return array<string, mixed> */
    private function dados(Request $request, string $tipo, ?SuporteNota $atual = null): array
    {
        $regras = ['titulo' => ['required', 'string', 'max:160']];
        if ($tipo === 'atendimento') {
            $regras += ['situacao' => ['required', Rule::in(array_keys(SuporteNota::SITUACOES))],
                'relato' => ['nullable', 'string', 'max:4000'], 'verificado' => ['nullable', 'string', 'max:4000'],
                // "Resolvido" sem dizer o que resolveu não é resolvido (base-do-suporte.md, FR-B7).
                'solucao' => ['nullable', 'required_if:situacao,resolvido', 'string', 'max:4000']];
        } else {
            $regras += ['corpo' => ['required', 'string', 'max:20000']];
        }
        if (in_array($tipo, ['procedimento', 'conhecimento'], true)) {
            $regras += ['quando_usar' => ['nullable', 'string', 'max:500']];
        }
        if (in_array($tipo, ['cliente', 'atendimento'], true)) {
            $regras += ['gestor_cliente_id' => ['nullable', 'integer', 'min:1'], 'cliente_id' => ['nullable', 'integer', Rule::exists('clientes', 'id')]];
        }
        $d = $request->validate($regras, ['solucao.required_if' => 'Para marcar como resolvido, escreva o que resolveu e quem confirmou.']);

        if ($tipo === 'cliente') {
            if (empty($d['gestor_cliente_id']) && empty($d['cliente_id'])) {
                throw ValidationException::withMessages(['cliente_id' => 'Escolha o cliente: o nº dele no Gestor, o cadastro no Matriz, ou os dois.']);
            }
            // Uma memória por cliente: duas notas dele fariam o Pedro ler versões diferentes da mesma história.
            $outra = SuporteNota::where('tipo', 'cliente')->where('estado', 'ativa')->when($atual, fn ($q) => $q->whereKeyNot($atual->id))
                ->where(fn ($q) => $q->when($d['gestor_cliente_id'] ?? null, fn ($q, $g) => $q->orWhere('gestor_cliente_id', $g))
                    ->when($d['cliente_id'] ?? null, fn ($q, $c) => $q->orWhere('cliente_id', $c)))->first();
            if ($outra) {
                throw ValidationException::withMessages(['cliente_id' => "Este cliente já tem a nota \"{$outra->titulo}\" (nº {$outra->id}): edite aquela."]);
            }
        }

        return $d;
    }

    /** @return array<int, string> o nome de cada cliente do Gestor que aparece nas notas */
    private function nomesNoGestor($notas): array
    {
        $diretorio = app(DiretorioGestor::class);

        return $notas->pluck('gestor_cliente_id')->filter()->unique()
            ->mapWithKeys(fn ($id) => [$id => ($g = $diretorio->cliente((int) $id)) ? ($g['fantasia'] ?: $g['nome']) : 'cliente nº '.$id])->all();
    }
}
