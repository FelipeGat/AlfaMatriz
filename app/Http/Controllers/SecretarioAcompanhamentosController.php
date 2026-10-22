<?php

namespace App\Http\Controllers;

use App\Models\SecretarioAcompanhamento;
use App\Models\SecretarioItem;
use App\Models\SecretarioPreferencia;
use App\Services\Secretario\Acompanhamento\Politica;
use App\Services\Secretario\Acompanhamento\Regras;
use App\Services\Secretario\CentralDeGestao;
use App\Services\Secretario\Despachante;
use App\Services\Secretario\Itens;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Secretário → Acompanhamentos (docs/specs/secretario-acompanhamento, US3):
 * a lista, as duas ações, o histórico de envios e as regras. Tudo por
 * `de($request->user())`: de outra conta, administrador incluído, é 404.
 */
class SecretarioAcompanhamentosController extends Controller
{
    public function index(Request $request, Politica $politica, CentralDeGestao $central): View
    {
        $user = $request->user();
        $config = Regras::de($user);
        $agora = CarbonImmutable::now();

        return view('secretario.acompanhamentos', [
            // Etapa 8: a Central de gestão no topo (números, Grupo Soluções, VixCard).
            'numeros' => $central->numeros($user, $agora),
            'gestor' => $central->grupoSolucoes($user),
            'linhas' => $central->linhas($user, $agora),
            'atividade' => $central->atividade($user),
            'dispensados' => SecretarioAcompanhamento::de($user)->where('situacao', SecretarioAcompanhamento::DISPENSADO)->count(),
            'config' => $config,
            'silencio' => $politica->emSilencio($config, CarbonImmutable::now()),
            // Os itens do aviso do sino que trouxe a pessoa até aqui, para resolver ali mesmo.
            'doAviso' => $request->filled('avisos')
                ? SecretarioAcompanhamento::de($user)->whereIn('id', array_slice(array_filter(array_map('intval', explode(',', (string) $request->query('avisos')))), 0, 50))
                    ->orderByRaw("prioridade = 'alta' desc")->orderBy('referencia_em')->get()
                : collect(),
        ]);
    }

    public function resolver(Request $request, int $acompanhamento): RedirectResponse
    {
        SecretarioAcompanhamento::de($request->user())->findOrFail($acompanhamento)->update(['situacao' => SecretarioAcompanhamento::DISPENSADO]);

        return back()->with('status', 'Marcado como resolvido. Só volta se a situação deixar de valer e acontecer de novo.');
    }

    public function adiar(Request $request, int $acompanhamento): RedirectResponse
    {
        $a = SecretarioAcompanhamento::de($request->user())->findOrFail($acompanhamento);
        $a->update(['adiado_ate' => now()->addDay()]);

        return back()->with('status', 'Adiado até amanhã às '.$a->adiado_ate->format('H:i').'.');
    }

    public function salvarRegras(Request $request): RedirectResponse
    {
        $dados = $request->validate(Regras::validacao());
        SecretarioPreferencia::updateOrCreate(['user_id' => $request->user()->id], ['acompanhamento' => Regras::normalizar($dados)]);

        // As regras moram em Configurações (Etapa 8).
        return redirect()->to(route('secretario.configuracoes').'#avisos')->with('status', 'Regras dos avisos salvas. Valem a partir da próxima conferência (até 5 min).');
    }

    /** Ponto de gestão lançado à mão (a VixCard ainda não tem sistema): vira tarefa do Secretário, com lembrete no prazo. */
    public function lancarPonto(Request $request, Despachante $despachante): RedirectResponse
    {
        $d = $request->validate([
            'empresa' => ['required', Rule::in(array_keys(CentralDeGestao::EMPRESAS))],
            'titulo' => ['required', 'string', 'max:190'],
            'prazo' => ['nullable', 'date_format:Y-m-d'],
            'prioridade' => ['required', Rule::in(['baixa', 'normal', 'alta'])],
        ]);
        $item = SecretarioItem::create(['user_id' => $request->user()->id, 'tipo' => 'tarefa', 'titulo' => $d['titulo'], 'empresa' => $d['empresa'],
            'quando' => $d['prazo'] ? $d['prazo'].' 00:00:00' : null, 'so_data' => (bool) $d['prazo'], 'prioridade' => $d['prioridade'], 'origem' => 'matriz']);
        $despachante->programar($item);

        return redirect()->to(route('secretario.acompanhamentos').'#'.$d['empresa'])->with('status', 'Ponto lançado em '.CentralDeGestao::EMPRESAS[$d['empresa']].'.');
    }

    public function concluirPonto(Request $request, int $item, Itens $itens): RedirectResponse
    {
        $i = SecretarioItem::de($request->user())->whereNotNull('empresa')->findOrFail($item);
        $itens->agir($request->user(), $i, 'concluir');

        return back()->with('status', 'Ponto concluído.');
    }
}
