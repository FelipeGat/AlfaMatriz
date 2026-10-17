<?php

namespace App\Http\Controllers;

use App\Models\SecretarioItem;
use App\Models\SecretarioPreferencia;
use App\Models\SecretarioProposta;
use App\Services\CentralIa\TelegramCanal;
use App\Services\Secretario\Interpretador;
use App\Services\Secretario\Itens;
use App\Services\Secretario\Propostas;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Meu Dia e as ações do Secretário (contracts/rotas-web.md). Toda consulta
 * passa por `de($request->user())`: item, aviso ou proposta de outra conta,
 * administrador incluído, é 404 (constitution I).
 */
class SecretarioController extends Controller
{
    public function meuDia(Request $request): View
    {
        return view('secretario.meu-dia', [
            'telegramPareado' => app(TelegramCanal::class)->vinculoDe($request->user()) !== null,
        ]);
    }

    public function itens(Request $request): JsonResponse
    {
        $user = $request->user();
        $agora = now();
        $abertos = SecretarioItem::de($user)->where('status', SecretarioItem::ABERTO)
            ->orderByRaw('quando is null')->orderBy('quando')->orderBy('id')->limit(500)->get();

        $vencido = fn (SecretarioItem $i) => $i->quando && ($i->so_data ? $i->quando->copy()->endOfDay() : $i->quando)->lessThan($agora);
        $atrasados = $abertos->filter($vencido);
        $hoje = $abertos->reject($vencido)->filter(fn ($i) => $i->quando?->isSameDay($agora));
        $proximos = $abertos->reject($vencido)->filter(fn ($i) => $i->quando && $i->quando->greaterThan($agora->copy()->endOfDay()))->take(30);
        $semData = $abertos->filter(fn ($i) => ! $i->quando);
        $proximo = $abertos->reject($vencido)->first(fn ($i) => $i->quando && ! $i->so_data);

        return $this->semCache([
            'atrasados' => $atrasados->map->paraTela()->values(),
            'hoje' => $hoje->map->paraTela()->values(),
            'proximos' => $proximos->map->paraTela()->values(),
            'sem_data' => $semData->map->paraTela()->values(),
            'proximo' => $proximo?->paraTela(),
            'concluidos_hoje' => SecretarioItem::de($user)->where('status', SecretarioItem::CONCLUIDO)
                ->where('concluido_em', '>=', $agora->copy()->startOfDay())->count(),
            'telegram_pareado' => app(TelegramCanal::class)->vinculoDe($user) !== null,
        ]);
    }

    public function interpretar(Request $request, Propostas $propostas): JsonResponse
    {
        $dados = $request->validate(['texto' => ['required', 'string', 'max:'.config('central_ia.max_texto')]]);
        $user = $request->user();
        // O campo do Meu Dia é do Secretário: não precisa de "me lembra".
        $r = $propostas->responder($user, $dados['texto']) ?? $propostas->criar($user, $dados['texto'], 'matriz', explicito: true);
        if (! $r) {
            return $this->semCache(['nao_e_do_secretario' => true]);
        }
        if ($r['item']) {
            return $this->semCache(['item' => $r['item']->paraTela(), 'mensagem' => $r['mensagem']]);
        }
        $p = $r['proposta'];
        if (! $p) {
            return $this->semCache(['mensagem' => $r['mensagem']]);
        }
        if ($p->falta) {
            return $this->semCache(['falta' => $p->falta, 'pergunta' => $r['mensagem'], 'proposta' => ['id' => $p->id]]);
        }

        return $this->semCache(['proposta' => ['id' => $p->id, 'resumo' => preg_replace('/\nConfirma\?$/u', '', $r['mensagem']), 'dados' => array_diff_key($p->dados, ['avisos' => 1])]]);
    }

    public function confirmar(Request $request, int $proposta, Propostas $propostas): JsonResponse
    {
        $p = SecretarioProposta::de($request->user())->findOrFail($proposta);
        $r = $propostas->confirmar($request->user(), $p);
        abort_unless($r['item'], 422, $r['mensagem']);

        return $this->semCache(['item' => $r['item']->paraTela(), 'mensagem' => $r['mensagem']]);
    }

    public function descartar(Request $request, int $proposta, Propostas $propostas): JsonResponse
    {
        $p = SecretarioProposta::de($request->user())->findOrFail($proposta);
        $propostas->descartar($request->user(), $p);

        return $this->semCache(['ok' => true]);
    }

    public function acao(Request $request, int $item, Itens $itens): JsonResponse
    {
        $dados = $request->validate(['acao' => ['required', Rule::in(Itens::ACOES)]]);
        $i = SecretarioItem::de($request->user())->findOrFail($item);
        $r = $itens->agir($request->user(), $i, $dados['acao']);

        return $this->semCache(['item' => $r['item']->paraTela(), 'mensagem' => $r['mensagem'], 'encerrado' => $r['encerrado']], $r['encerrado'] ? 409 : 200);
    }

    public function reagendar(Request $request, int $item, Itens $itens, Interpretador $interpretador): JsonResponse
    {
        $dados = $request->validate(['texto' => ['required', 'string', 'max:200']]);
        $i = SecretarioItem::de($request->user())->findOrFail($item);
        $q = $interpretador->soQuando($dados['texto'], CarbonImmutable::now());
        abort_unless($q, 422, 'Não entendi quando. Ex.: quinta às 15h, amanhã às 9h, daqui a 2 horas.');
        $r = $itens->reagendar($request->user(), $i, $q);

        return $this->semCache(['item' => $r['item']->paraTela(), 'mensagem' => $r['mensagem'], 'encerrado' => $r['encerrado']], $r['encerrado'] ? 409 : 200);
    }

    public function configuracoes(Request $request): View
    {
        return view('secretario.configuracoes', [
            'preferencia' => SecretarioPreferencia::de($request->user()),
            'google' => \App\Models\SecretarioGoogleConta::where('user_id', $request->user()->id)->first(),
            'agendas' => \App\Models\SecretarioGoogleAgenda::where('user_id', $request->user()->id)->orderByDesc('primaria')->orderBy('nome')->get(),
            'googleConfigurado' => app(\App\Services\Secretario\GoogleAgenda::class)->configurado(),
        ]);
    }

    public function salvarConfiguracoes(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'auto_confirmar' => ['required', 'boolean'],
            'antecedencia_padrao' => ['required', 'integer', 'min:0', 'max:1440'],
            'telegram' => ['required', 'boolean'],
        ]);
        SecretarioPreferencia::updateOrCreate(['user_id' => $request->user()->id], $dados);

        return $this->semCache(['ok' => true]);
    }

    private function semCache(array $dados, int $status = 200): JsonResponse
    {
        return response()->json($dados, $status)->header('Cache-Control', 'no-store, private');
    }
}
