<?php

namespace App\Http\Controllers;

use App\Models\SecretarioAcompanhamento;
use App\Models\SecretarioAcompanhamentoEnvio;
use App\Models\SecretarioPreferencia;
use App\Services\Secretario\Acompanhamento\Politica;
use App\Services\Secretario\Acompanhamento\Regras;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Secretário → Acompanhamentos (docs/specs/secretario-acompanhamento, US3):
 * a lista, as duas ações, o histórico de envios e as regras. Tudo por
 * `de($request->user())`: de outra conta, administrador incluído, é 404.
 */
class SecretarioAcompanhamentosController extends Controller
{
    public function index(Request $request, Politica $politica): View
    {
        $user = $request->user();
        $config = Regras::de($user);

        return view('secretario.acompanhamentos', [
            'ativos' => SecretarioAcompanhamento::de($user)->ativos()->get()->groupBy('prioridade'),
            'dispensados' => SecretarioAcompanhamento::de($user)->where('situacao', SecretarioAcompanhamento::DISPENSADO)->count(),
            'envios' => SecretarioAcompanhamentoEnvio::de($user)->where('created_at', '>=', now()->subDays(7))->latest('id')->limit(60)->get(),
            'config' => $config,
            'silencio' => $politica->emSilencio($config, CarbonImmutable::now()),
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

        return redirect()->route('secretario.acompanhamentos')->with('status', 'Regras salvas. Valem a partir da próxima conferência (até 5 min).');
    }
}
