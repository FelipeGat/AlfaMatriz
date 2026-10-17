<?php

namespace App\Http\Controllers;

use App\Models\SecretarioGoogleAgenda;
use App\Models\SecretarioGoogleConta;
use App\Models\SecretarioGoogleEvento;
use App\Services\CentralIa\Eventos;
use App\Services\Secretario\AgendaConsolidada;
use App\Services\Secretario\GoogleAgenda;
use App\Services\Secretario\SincronizadorGoogle;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * A conta Google do Secretário (docs/specs/secretario-agenda). Sem id de
 * usuário em rota: é sempre a conta logada. O `state` do OAuth é de uso único,
 * guardado na sessão junto com o id da conta — a volta de outra sessão ou de
 * outra conta é recusada (FR-002).
 */
class SecretarioGoogleController extends Controller
{
    private const SESSAO = 'secretario.google.state';

    public function conectar(Request $request, GoogleAgenda $google): RedirectResponse
    {
        if (! $google->configurado()) {
            return redirect()->route('secretario.configuracoes')->withErrors(['google' => 'A integração com o Google ainda não está configurada no servidor.']);
        }
        $state = Str::random(48);
        $request->session()->put(self::SESSAO, ['state' => hash('sha256', $state), 'user_id' => $request->user()->id, 'expira' => now()->addMinutes(10)->getTimestamp()]);

        return redirect()->away($google->urlDeAutorizacao($state));
    }

    public function retorno(Request $request, GoogleAgenda $google, SincronizadorGoogle $sincronizador): RedirectResponse
    {
        $guardado = $request->session()->pull(self::SESSAO);
        $state = (string) $request->query('state', '');
        $valido = is_array($guardado) && $state !== '' && hash_equals($guardado['state'] ?? '', hash('sha256', $state))
            && ($guardado['user_id'] ?? null) === $request->user()->id && ($guardado['expira'] ?? 0) >= now()->getTimestamp();
        if (! $valido) {
            Eventos::registrar($request->user(), 'secretario_google_recusado', 'Volta do Google recusada (estado inválido).', ['origem' => 'google'], 'aviso');

            return redirect()->route('secretario.configuracoes')->withErrors(['google' => 'A autorização não pôde ser conferida. Tente conectar de novo.']);
        }
        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect()->route('secretario.configuracoes')->withErrors(['google' => 'A autorização foi cancelada no Google.']);
        }

        $r = $google->trocarCodigo((string) $request->query('code'));
        if (! $r['ok']) {
            return redirect()->route('secretario.configuracoes')->withErrors(['google' => 'O Google não confirmou a autorização ('.$r['erro'].'). Tente de novo.']);
        }
        $t = $r['tokens'];
        $existente = SecretarioGoogleConta::where('user_id', $request->user()->id)->first();
        $conta = SecretarioGoogleConta::updateOrCreate(['user_id' => $request->user()->id], [
            'access_token' => $t['access_token'],
            // Reconexão sem refresh novo mantém o anterior.
            'refresh_token' => $t['refresh_token'] ?? $existente?->refresh_token,
            'expira_em' => now()->addSeconds((int) ($t['expires_in'] ?? 3600)),
            'escopos' => mb_substr((string) ($t['scope'] ?? ''), 0, 500),
            'situacao' => SecretarioGoogleConta::CONECTADA, 'erro' => null, 'aviso_reconectar_em' => null,
        ]);
        Eventos::registrar($request->user(), 'secretario_google_conectado', 'Google Agenda conectado ao Secretário.', ['origem' => 'google']);
        $s = $sincronizador->sincronizar($conta);

        return redirect()->route('secretario.configuracoes')->with('status', $s['ok']
            ? 'Google Agenda conectado. Escolha abaixo quais agendas entram e qual é a padrão.'
            : 'Google Agenda conectado, mas a primeira leitura falhou ('.$s['erro'].'). Ela será tentada de novo em até 10 minutos.');
    }

    public function desconectar(Request $request, GoogleAgenda $google): RedirectResponse
    {
        $conta = SecretarioGoogleConta::where('user_id', $request->user()->id)->first();
        if ($conta) {
            // Pela máquina não há token aqui: só apaga o que veio dela.
            if (! $conta->pelaMaquina()) {
                $google->revogar($conta);
            }
            DB::transaction(function () use ($request) {
                SecretarioGoogleEvento::where('user_id', $request->user()->id)->delete();
                SecretarioGoogleAgenda::where('user_id', $request->user()->id)->delete();
                SecretarioGoogleConta::where('user_id', $request->user()->id)->delete();
            });
            Eventos::registrar($request->user(), 'secretario_google_desconectado', 'Google Agenda desconectado; tokens e eventos apagados.', ['origem' => 'web']);
        }

        return redirect()->route('secretario.configuracoes')->with('status', 'Google Agenda desconectado. A autorização foi revogada e os dados do Google foram apagados daqui.');
    }

    public function sincronizar(Request $request, SincronizadorGoogle $sincronizador): JsonResponse
    {
        $conta = SecretarioGoogleConta::where('user_id', $request->user()->id)->firstOrFail();
        $r = $sincronizador->sincronizar($conta);

        return response()->json($r + ['sincronizado_em' => $conta->fresh()->sincronizado_em?->format('H:i')], $r['ok'] ? 200 : 502);
    }

    public function agenda(Request $request, int $agenda): JsonResponse
    {
        $dados = $request->validate([
            'incluida' => ['required', 'boolean'], 'padrao' => ['required', 'boolean'], 'palavras' => ['nullable', 'string', 'max:255'],
        ]);
        $a = SecretarioGoogleAgenda::where('user_id', $request->user()->id)->findOrFail($agenda);
        DB::transaction(function () use ($a, $dados, $request) {
            if ($dados['padrao']) {
                SecretarioGoogleAgenda::where('user_id', $request->user()->id)->update(['padrao' => false]);
            }
            $a->update($dados);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Eventos do calendário (Etapa 8, "igual ao Gestor"): lista plana de um
     * intervalo de até 42 dias — a tela monta Dia, Semana e Mês com ela.
     */
    public function eventos(Request $request, AgendaConsolidada $agenda): JsonResponse
    {
        $d = $request->validate(['de' => ['required', 'date_format:Y-m-d'], 'ate' => ['required', 'date_format:Y-m-d', 'after_or_equal:de']]);
        $de = CarbonImmutable::parse($d['de'])->startOfDay();
        $ate = CarbonImmutable::parse($d['ate'])->endOfDay();
        abort_if($de->diffInDays($ate) > 42, 422, 'Intervalo grande demais.');
        $situacao = $agenda->situacaoGoogle($request->user());

        return response()->json([
            'eventos' => $agenda->periodo($request->user(), $de, $ate)->map(fn ($x) => [
                'titulo' => $x['titulo'], 'origem' => $x['origem'], 'origem_rotulo' => $x['origem_rotulo'],
                'inicio' => $x['inicio']->format('Y-m-d\TH:i'), 'fim' => $x['fim']?->format('Y-m-d\TH:i'),
                'dia_inteiro' => (bool) $x['dia_inteiro'], 'conflito' => (bool) $x['conflito'], 'link' => $x['link'],
                // O calendário de cada evento (filtro "Meus calendários"): a empresa do ponto/compromisso, senão a origem.
                'calendario' => match (true) {
                    // Agenda do Google com o nome da empresa (ex.: "VixCard", "Comercial Grupo") ganha a cor dela.
                    $x['origem'] === 'google' && str_contains(mb_strtolower($x['origem_rotulo']), 'vixcard') => 'vixcard',
                    $x['origem'] === 'google' && str_contains(mb_strtolower($x['origem_rotulo']), 'grupo') => 'grupo_solucoes',
                    ($x['empresa'] ?? null) === null => $x['origem'],
                    in_array($x['empresa'], \App\Services\Secretario\CentralDeGestao::COM_CALENDARIO, true) => $x['empresa'],
                    default => 'outros',
                },
            ])->values(),
            'google' => ['estado' => $situacao['estado'], 'aviso' => $agenda->aviso($situacao)],
        ])->header('Cache-Control', 'no-store, private');
    }

    /** "Novo evento" do calendário: um compromisso do Secretário (com lembrete), nunca um evento no Google sem confirmação. */
    public function novoEvento(Request $request, \App\Services\Secretario\Despachante $despachante): JsonResponse
    {
        $d = $request->validate([
            'titulo' => ['required', 'string', 'max:190'], 'data' => ['required', 'date_format:Y-m-d'], 'hora' => ['nullable', 'date_format:H:i'],
            'empresa' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(\App\Services\Secretario\CentralDeGestao::EMPRESAS))],
        ]);
        $item = \App\Models\SecretarioItem::create(['user_id' => $request->user()->id, 'tipo' => $d['hora'] ? 'compromisso' : 'tarefa', 'titulo' => $d['titulo'],
            'quando' => $d['data'].' '.($d['hora'] ?? '00:00').':00', 'so_data' => ! $d['hora'], 'empresa' => $d['empresa'] ?? null,
            'antecedencia_min' => $d['hora'] ? \App\Models\SecretarioPreferencia::de($request->user())->antecedencia_padrao : null, 'origem' => 'matriz']);
        $despachante->programar($item);

        return response()->json(['ok' => true, 'mensagem' => 'Evento criado: '.\App\Services\Secretario\Datas::porExtenso($item->quando, $item->so_data).'.']);
    }

    /** Secretário → Agenda: a visão consolidada (spec US2). `?formato=json` para a tela. */
    public function visao(Request $request, AgendaConsolidada $agenda): View|JsonResponse
    {
        $dados = $request->validate(['de' => ['nullable', 'date_format:Y-m-d'], 'dias' => ['nullable', 'integer', 'min:1', 'max:31']]);
        $de = isset($dados['de']) ? CarbonImmutable::parse($dados['de'])->startOfDay() : CarbonImmutable::now()->startOfDay();
        $dias = (int) ($dados['dias'] ?? 7);
        $itens = $agenda->periodo($request->user(), $de, $de->addDays($dias)->subSecond());
        $situacao = $agenda->situacaoGoogle($request->user());
        $porDia = [];
        for ($i = 0; $i < $dias; $i++) {
            $dia = $de->addDays($i);
            $doDia = $itens->filter(fn ($x) => $x['inicio']->isSameDay($dia) || ($x['fim'] && $x['inicio']->lessThan($dia) && $x['fim']->greaterThan($dia)))->values();
            $porDia[] = [
                'data' => $dia->format('Y-m-d'),
                'titulo' => ucfirst(\App\Services\Secretario\Datas::porExtenso($dia, true)),
                'itens' => $doDia->map(fn ($x) => [
                    'titulo' => $x['titulo'], 'origem' => $x['origem'], 'origem_rotulo' => $x['origem_rotulo'],
                    'hora' => $x['dia_inteiro'] ? 'dia inteiro' : $x['inicio']->format('H:i').($x['fim'] ? '–'.$x['fim']->format('H:i') : ''),
                    'conflito' => $x['conflito'], 'link' => $x['link'],
                ])->all(),
                'livres' => $dia->isSameDay(now()) || $dia->greaterThan(now())
                    ? array_map(fn ($l) => $l[0]->format('H:i').'–'.$l[1]->format('H:i'), $agenda->livres($doDia, $dia)) : [],
            ];
        }
        $payload = ['dias' => $porDia, 'google' => ['estado' => $situacao['estado'], 'sincronizado_em' => $situacao['sincronizado_em']?->format('d/m H:i'),
            'aviso' => $agenda->aviso($situacao)]];

        return $request->query('formato') === 'json'
            ? response()->json($payload)->header('Cache-Control', 'no-store, private')
            : view('secretario.agenda', ['visao' => $payload]);
    }
}
