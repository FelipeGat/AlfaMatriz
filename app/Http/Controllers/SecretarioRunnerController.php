<?php

namespace App\Http\Controllers;

use App\Models\CentralIaRunner;
use App\Models\User;
use App\Services\Secretario\Reunioes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * A transcrição de reuniões pelo runner da PRÓPRIA pessoa
 * (docs/specs/secretario-reunioes/contracts/runner-transcricao.md). Mesmo
 * token do runner da Central; a reunião é da conta do token e só a máquina
 * que reservou baixa o áudio e devolve o resultado.
 */
class SecretarioRunnerController extends Controller
{
    public function proxima(Request $request, Reunioes $reunioes): JsonResponse|Response
    {
        [$user, $maquina] = $this->conta($request);
        $r = $reunioes->reservar($user, $maquina);

        return $r ? response()->json(['id' => $r->id, 'sha256' => $r->sha256, 'extensao' => pathinfo((string) $r->arquivo, PATHINFO_EXTENSION), 'modo' => $r->modo])
            : response()->noContent();
    }

    public function audio(Request $request, int $reuniao, Reunioes $reunioes): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        [$user, $maquina] = $this->conta($request);
        $r = $reunioes->daMaquina($user, $maquina, $reuniao);
        abort_unless($r->arquivo && Storage::disk(Reunioes::DISCO)->exists($r->arquivo), 404);

        return Storage::disk(Reunioes::DISCO)->download($r->arquivo, 'audio.'.pathinfo($r->arquivo, PATHINFO_EXTENSION), [
            'Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function resultado(Request $request, int $reuniao, Reunioes $reunioes): JsonResponse
    {
        [$user, $maquina] = $this->conta($request);
        $dados = $request->validate([
            'status' => ['required', 'in:ok,falhou'],
            'erro' => ['nullable', 'string', 'max:200'],
            'duracao' => ['nullable', 'numeric', 'min:0', 'max:86400'],
            'trechos' => ['required_if:status,ok', 'array', 'max:5000'],
            'trechos.*.inicio' => ['required', 'numeric', 'min:0', 'max:86400'],
            'trechos.*.fim' => ['required', 'numeric', 'min:0', 'max:86400', 'gte:trechos.*.inicio'],
            'trechos.*.texto' => ['required', 'string', 'max:2000'],
        ]);
        $reunioes->resultado($user, $maquina, $reuniao, $dados);

        return response()->json(['ok' => true]);
    }

    /**
     * O retrato das pendências do Gestor, lido pela máquina pela ponte de
     * produção em modo só leitura (contracts/gestor-retrato.md). Inválido é
     * recusado inteiro e o anterior continua valendo (FR-012).
     */
    public function gestor(Request $request): JsonResponse
    {
        [$user, $maquina] = $this->conta($request);
        $blocos = array_keys(\App\Models\SecretarioGestorRetrato::BLOCOS);
        $dados = $request->validate([
            'lido_em' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String(), 'after:'.now()->subDay()->toIso8601String()],
            'blocos' => ['required', 'array:'.implode(',', $blocos)],
            'blocos.*.quantidade' => ['required', 'integer', 'min:0', 'max:100000'],
            'blocos.*.valor' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'blocos.*.itens' => ['present', 'array', 'max:10'],
            'blocos.*.itens.*.texto' => ['required', 'string', 'max:200'],
            'blocos.*.itens.*.data' => ['nullable', 'date_format:Y-m-d'],
            'blocos.*.itens.*.valor' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
        ]);
        \App\Models\SecretarioGestorRetrato::updateOrCreate(['user_id' => $user->id], [
            'maquina' => $maquina, 'lido_em' => \Carbon\CarbonImmutable::parse($dados['lido_em'])->setTimezone(config('app.timezone')), 'dados' => ['blocos' => $dados['blocos']],
        ]);
        \App\Services\CentralIa\Eventos::registrar($user, 'secretario_gestor_retrato', 'Retrato do Gestor recebido da máquina '.$maquina.'.', ['origem' => 'runner']);

        return response()->json(['ok' => true]);
    }

    /** @return array{0: User, 1: string} a conta do token e a máquina dela que chama */
    private function conta(Request $request): array
    {
        $user = $request->user();
        $user->esquecerPermissoes();
        abort_unless($user->ativo && ! $user->temEscopoDeRevenda() && $user->canPermissao('secretario', 'incluir'), 403, 'Esta conta não usa o Secretário.');
        $maquina = (string) $request->input('maquina', $request->query('maquina', ''));
        abort_unless(preg_match('/^[A-Za-z0-9._-]{1,64}$/', $maquina) && CentralIaRunner::where('user_id', $user->id)->where('maquina', $maquina)->exists(), 404);

        return [$user, $maquina];
    }
}
