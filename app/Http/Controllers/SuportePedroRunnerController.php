<?php

namespace App\Http\Controllers;

use App\Models\CentralIaRunner;
use App\Models\SuporteGestorAbertura;
use App\Services\Suporte\Atendimento;
use App\Services\Suporte\CanalWhatsApp;
use App\Services\Suporte\DiretorioGestor;
use App\Services\Suporte\Pedro;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A porta do runner para o Pedro (docs/specs/suporte-pedro-whatsapp): a máquina
 * da conta configurada pega o próximo pedido e devolve o que a IA respondeu.
 */
class SuportePedroRunnerController extends Controller
{
    public function proxima(Request $request, Pedro $pedro): JsonResponse|Response
    {
        $maquina = $this->maquina($request);
        $job = $pedro->reservar($request->user(), $maquina);

        return $job ? response()->json($job) : response()->noContent();
    }

    public function resposta(Request $request, int $pedido, Pedro $pedro): JsonResponse
    {
        $this->maquina($request);
        $d = $request->validate(['texto' => ['nullable', 'string', 'max:40000'], 'erro' => ['nullable', 'string', 'max:500'],
            'transcricoes' => ['nullable', 'array', 'max:5'], 'transcricoes.*.id' => ['required', 'integer'], 'transcricoes.*.texto' => ['required', 'string', 'max:8000']]);
        $pedro->concluir($request->user(), $pedido, $d['texto'] ?? null, $d['erro'] ?? null, $d['transcricoes'] ?? []);

        return response()->json(['ok' => true]);
    }

    /** O retrato do Gestor (só leitura, a cada 10 min): clientes ativos com telefones e atendimentos recentes. */
    public function gestor(Request $request, DiretorioGestor $diretorio): JsonResponse
    {
        $maquina = $this->maquina($request);
        $this->exigirConta($request);
        $d = $request->validate([
            'lido_em' => ['required', 'date'],
            'clientes' => ['present', 'array', 'max:20000'],
            'clientes.*.id' => ['required', 'integer'],
            'clientes.*.nome' => ['nullable', 'string', 'max:255'], 'clientes.*.fantasia' => ['nullable', 'string', 'max:255'], 'clientes.*.razao' => ['nullable', 'string', 'max:255'],
            'clientes.*.documento' => ['nullable', 'string', 'max:30'], 'clientes.*.cidade' => ['nullable', 'string', 'max:120'], 'clientes.*.empresa' => ['nullable', 'string', 'max:120'],
            'clientes.*.telefones' => ['nullable', 'array', 'max:20'], 'clientes.*.telefones.*' => ['string', 'max:40'], 'clientes.*.empresa_id' => ['nullable', 'integer'],
            'clientes.*.portal' => ['nullable', 'array', 'max:5'], 'clientes.*.portal.*' => ['string', 'email', 'max:255'],
            'atendimentos' => ['present', 'array', 'max:20000'],
            'atendimentos.*.id' => ['required', 'integer'], 'atendimentos.*.cliente_id' => ['nullable', 'integer'], 'atendimentos.*.numero' => ['nullable', 'string', 'max:30'],
            'atendimentos.*.descricao' => ['nullable', 'string', 'max:500'], 'atendimentos.*.status' => ['nullable', 'string', 'max:40'], 'atendimentos.*.criado_em' => ['nullable', 'string', 'max:30'],
            'atendimentos.*.empresa_id' => ['nullable', 'integer'], 'atendimentos.*.assunto_id' => ['nullable', 'integer'],
            'atendimentos.*.funcionarios' => ['nullable', 'array', 'max:20'], 'atendimentos.*.funcionarios.*' => ['integer'],
            'equipe' => ['nullable', 'array', 'max:500'], 'equipe.*.id' => ['required', 'integer'], 'equipe.*.nome' => ['required', 'string', 'max:255'],
            'equipe.*.telefones' => ['nullable', 'array', 'max:5'], 'equipe.*.telefones.*' => ['string', 'max:40'],
            'assuntos' => ['nullable', 'array', 'max:2000'], 'assuntos.*.id' => ['required', 'integer'], 'assuntos.*.nome' => ['required', 'string', 'max:255'],
            'assuntos.*.tipo' => ['nullable', 'string', 'max:60'], 'assuntos.*.categoria' => ['nullable', 'string', 'max:120'], 'assuntos.*.empresa_id' => ['nullable', 'integer'],
        ]);
        $diretorio->gravar($maquina, \Illuminate\Support\Carbon::parse($d['lido_em'])->setTimezone(config('app.timezone'))->toDateTimeString(), $d['clientes'], $d['atendimentos'], $d['assuntos'] ?? [], $d['equipe'] ?? []);

        return response()->json(['ok' => true, 'clientes' => count($d['clientes'])]);
    }

    /** Os atendimentos que o Pedro pediu para abrir no Gestor (a máquina grava pela ponte). */
    public function aberturas(Request $request): JsonResponse
    {
        $maquina = $this->maquina($request);
        $this->exigirConta($request);
        $lista = DB::transaction(function () use ($maquina) {
            // Reservado há mais de 10 min (máquina caiu no meio): volta a ser oferecido? Não — pode ter gravado; fica para a equipe conferir.
            $p = SuporteGestorAbertura::where('situacao', 'pendente')->orderBy('id')->lockForUpdate()->limit(5)->get();
            foreach ($p as $a) {
                $a->update(['situacao' => 'reservado', 'reservado_em' => now()]);
            }

            return $p;
        });

        return response()->json(['aberturas' => $lista->map(fn ($a) => ['id' => $a->id, 'cliente_id' => $a->gestor_cliente_id, 'assunto_id' => $a->assunto_id, 'empresa_id' => $a->empresa_id, 'descricao' => $a->descricao,
            'solicitante' => $a->solicitante, 'telefone' => $a->telefone, 'prioridade' => $a->prioridade])->values()]);
    }

    public function aberturaFeita(Request $request, int $abertura, Atendimento $atendimento, CanalWhatsApp $canal): JsonResponse
    {
        $this->maquina($request);
        $this->exigirConta($request);
        $d = $request->validate(['numero' => ['nullable', 'string', 'max:30'], 'erro' => ['nullable', 'string', 'max:300']]);
        $a = SuporteGestorAbertura::with('conversa')->whereKey($abertura)->where('situacao', 'reservado')->firstOrFail();
        $atendimento->concluirNoGestor($a, $d['numero'] ?? null, $d['erro'] ?? null, $canal);

        return response()->json(['ok' => true]);
    }

    private function exigirConta(Request $request): void
    {
        abort_unless(mb_strtolower($request->user()->email) === mb_strtolower((string) config('services.suporte_pedro.conta')), 403);
    }

    private function maquina(Request $request): string
    {
        $user = $request->user();
        abort_unless($user->ativo && ! $user->temEscopoDeRevenda(), 403);
        $maquina = (string) $request->input('maquina', '');
        abort_unless(preg_match('/^[A-Za-z0-9._-]{1,64}$/', $maquina) && CentralIaRunner::where('user_id', $user->id)->where('maquina', $maquina)->exists(), 404);

        return $maquina;
    }
}
