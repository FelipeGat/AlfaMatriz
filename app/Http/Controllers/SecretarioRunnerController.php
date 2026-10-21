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

    /** Quanto a máquina já transcreveu (a cada ~15 s), para a barra da tela. */
    public function progresso(Request $request, int $reuniao, Reunioes $reunioes): JsonResponse
    {
        [$user, $maquina] = $this->conta($request);
        $dados = $request->validate([
            'percentual' => ['required', 'integer', 'min:0', 'max:100'],
            'duracao' => ['nullable', 'numeric', 'min:0', 'max:86400'],
        ]);
        $reunioes->progresso($user, $maquina, $reuniao, (int) $dados['percentual'], isset($dados['duracao']) ? (float) $dados['duracao'] : null);

        return response()->json(['ok' => true]);
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

    /**
     * A agenda do Google lida pela máquina (docs/specs/secretario-agenda-pela-maquina):
     * a ponte pessoal que a pessoa já autorizou lê, a máquina manda. Substitui
     * agendas e eventos da janela; conta OAuth conectada aqui tem precedência.
     */
    public function agenda(Request $request): JsonResponse
    {
        [$user, $maquina] = $this->conta($request);
        $d = $request->validate([
            'lido_em' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String(), 'after:'.now()->subDay()->toIso8601String()],
            'agendas' => ['present', 'array', 'max:100'],
            'agendas.*.id' => ['required', 'string', 'max:255'],
            'agendas.*.nome' => ['required', 'string', 'max:255'],
            'agendas.*.primaria' => ['boolean'],
            'eventos' => ['present', 'array', 'max:5000'],
            'eventos.*.agenda' => ['required', 'string', 'max:255'],
            'eventos.*.id' => ['required', 'string', 'max:255'],
            'eventos.*.titulo' => ['nullable', 'string', 'max:255'],
            'eventos.*.inicio' => ['required', 'string', 'max:40'],
            'eventos.*.fim' => ['required', 'string', 'max:40'],
            'eventos.*.dia_inteiro' => ['required', 'boolean'],
            'eventos.*.link' => ['nullable', 'string', 'max:500'],
        ]);
        $conta = \App\Models\SecretarioGoogleConta::where('user_id', $user->id)->first();
        if ($conta && $conta->conectada()) {
            return response()->json(['ok' => true, 'ignorado' => 'a conta OAuth do Matriz já lê o Google']);
        }
        $tz = config('app.timezone');
        \Illuminate\Support\Facades\DB::transaction(function () use ($user, $maquina, $d, $tz) {
            \App\Models\SecretarioGoogleConta::updateOrCreate(['user_id' => $user->id], [
                'conta' => 'máquina '.$maquina, 'access_token' => '', 'refresh_token' => null, 'expira_em' => now(),
                'situacao' => \App\Models\SecretarioGoogleConta::MAQUINA, 'erro' => null,
                'sincronizado_em' => \Carbon\CarbonImmutable::parse($d['lido_em'])->setTimezone($tz),
            ]);
            $ids = [];
            foreach ($d['agendas'] as $a) {
                $agenda = \App\Models\SecretarioGoogleAgenda::firstOrNew(['user_id' => $user->id, 'google_id' => $a['id']]);
                $nova = ! $agenda->exists;
                $agenda->fill(['nome' => $a['nome'], 'primaria' => (bool) ($a['primaria'] ?? false)] + ($nova ? ['incluida' => true] : []))->save();
                $ids[$a['id']] = $agenda;
            }
            \App\Models\SecretarioGoogleAgenda::where('user_id', $user->id)->whereNotIn('google_id', array_keys($ids) ?: [''])->delete();
            if (! \App\Models\SecretarioGoogleAgenda::where('user_id', $user->id)->where('padrao', true)->exists()) {
                \App\Models\SecretarioGoogleAgenda::where('user_id', $user->id)->where('primaria', true)->limit(1)->update(['padrao' => true]);
            }
            // Eventos: a janela lida inteira substitui o que havia (cancelado ou apagado no Google some daqui).
            \App\Models\SecretarioGoogleEvento::where('user_id', $user->id)->delete();
            foreach ($d['eventos'] as $e) {
                $agenda = $ids[$e['agenda']] ?? null;
                if (! $agenda || ! $agenda->incluida) {
                    continue;
                }
                try {
                    [$ini, $fim] = $e['dia_inteiro']
                        ? [\Carbon\CarbonImmutable::parse($e['inicio'], $tz)->startOfDay(), \Carbon\CarbonImmutable::parse($e['fim'], $tz)->startOfDay()]
                        : [\Carbon\CarbonImmutable::parse($e['inicio'])->setTimezone($tz), \Carbon\CarbonImmutable::parse($e['fim'])->setTimezone($tz)];
                } catch (\Throwable) {
                    continue;
                }
                \App\Models\SecretarioGoogleEvento::updateOrCreate(['agenda_id' => $agenda->id, 'google_id' => $e['id']], ['user_id' => $user->id,
                    'titulo' => trim((string) ($e['titulo'] ?? '')) ?: '(sem título)', 'inicio' => $ini, 'fim' => $fim, 'dia_inteiro' => $e['dia_inteiro'],
                    'link' => is_string($e['link'] ?? null) && str_starts_with($e['link'], 'https://') ? $e['link'] : null]);
            }
        });
        \App\Services\CentralIa\Eventos::registrar($user, 'secretario_agenda_maquina', 'Agenda do Google recebida da máquina '.$maquina.' ('.count($d['eventos']).' eventos).', ['origem' => 'runner']);

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
