<?php

namespace App\Services\Secretario;

use App\Models\SecretarioGoogleAgenda;
use App\Models\SecretarioGoogleConta;
use App\Models\SecretarioGoogleEvento;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Traz as agendas e os eventos da janela (ontem até +30 dias) para o cache
 * mínimo (spec FR-006, FR-007). O que saiu da janela, foi cancelado ou é de
 * agenda desmarcada sai do cache.
 */
class SincronizadorGoogle
{
    public const DIAS_ATRAS = 1;
    public const DIAS_FRENTE = 30;
    public const MAX_POR_AGENDA = 2500;

    public function __construct(private GoogleAgenda $google) {}

    /** @return array{ok: bool, erro?: string, eventos?: int} */
    public function sincronizar(SecretarioGoogleConta $conta): array
    {
        if (! GoogleAgenda::contaAtiva($conta) || ! $conta->conectada()) {
            return ['ok' => false, 'erro' => 'inativa'];
        }
        $agendas = $this->agendas($conta);
        if (! $agendas['ok']) {
            return $agendas;
        }

        $de = CarbonImmutable::now()->subDays(self::DIAS_ATRAS)->startOfDay();
        $ate = CarbonImmutable::now()->addDays(self::DIAS_FRENTE)->endOfDay();
        $total = 0;
        foreach (SecretarioGoogleAgenda::where('user_id', $conta->user_id)->get() as $agenda) {
            if (! $agenda->incluida) {
                SecretarioGoogleEvento::where('agenda_id', $agenda->id)->delete();

                continue;
            }
            $vistos = [];
            $pagina = null;
            $lidos = 0;
            do {
                $r = $this->google->get($conta, '/calendars/'.rawurlencode($agenda->google_id).'/events', array_filter([
                    'timeMin' => $de->toRfc3339String(), 'timeMax' => $ate->toRfc3339String(),
                    'singleEvents' => 'true', 'orderBy' => 'startTime', 'maxResults' => 250, 'pageToken' => $pagina,
                ]));
                if (! $r['ok']) {
                    $conta->update(['erro' => $r['erro']]);

                    return ['ok' => false, 'erro' => $r['erro']];
                }
                foreach ($r['dados']['items'] ?? [] as $e) {
                    if (($e['status'] ?? '') === 'cancelled' || ! is_string($e['id'] ?? null)) {
                        continue;
                    }
                    $tempo = $this->tempo($e);
                    if (! $tempo) {
                        continue;
                    }
                    SecretarioGoogleEvento::updateOrCreate(['agenda_id' => $agenda->id, 'google_id' => mb_substr($e['id'], 0, 255)], [
                        'user_id' => $conta->user_id,
                        'titulo' => mb_substr(trim((string) ($e['summary'] ?? '')) ?: '(sem título)', 0, 255),
                        'inicio' => $tempo[0], 'fim' => $tempo[1], 'dia_inteiro' => $tempo[2],
                        'link' => is_string($e['htmlLink'] ?? null) && str_starts_with($e['htmlLink'], 'https://') ? mb_substr($e['htmlLink'], 0, 500) : null,
                    ]);
                    $vistos[] = mb_substr($e['id'], 0, 255);
                    $lidos++;
                }
                $pagina = $r['dados']['nextPageToken'] ?? null;
            } while ($pagina && $lidos < self::MAX_POR_AGENDA);

            // Sumiu do Google (cancelado, apagado, fora da janela): sai do cache.
            SecretarioGoogleEvento::where('agenda_id', $agenda->id)->whereNotIn('google_id', $vistos ?: [''])->delete();
            $total += $lidos;
        }
        $conta->update(['sincronizado_em' => now(), 'erro' => null]);

        return ['ok' => true, 'eventos' => $total];
    }

    /** As agendas da conta: novas entram incluídas; as que sumiram saem. */
    public function agendas(SecretarioGoogleConta $conta): array
    {
        $itens = [];
        $pagina = null;
        do {
            $r = $this->google->get($conta, '/users/me/calendarList', array_filter(['maxResults' => 250, 'pageToken' => $pagina]));
            if (! $r['ok']) {
                $conta->update(['erro' => $r['erro']]);

                return ['ok' => false, 'erro' => $r['erro']];
            }
            $itens = array_merge($itens, $r['dados']['items'] ?? []);
            $pagina = $r['dados']['nextPageToken'] ?? null;
        } while ($pagina && count($itens) < 100);

        DB::transaction(function () use ($conta, $itens) {
            $ids = [];
            foreach ($itens as $a) {
                if (! is_string($a['id'] ?? null)) {
                    continue;
                }
                $ids[] = $a['id'];
                $agenda = SecretarioGoogleAgenda::firstOrNew(['user_id' => $conta->user_id, 'google_id' => mb_substr($a['id'], 0, 255)]);
                $agenda->fill(['nome' => mb_substr((string) ($a['summaryOverride'] ?? $a['summary'] ?? $a['id']), 0, 255), 'primaria' => (bool) ($a['primary'] ?? false)]);
                $agenda->save();
                if ($agenda->primaria && ! $conta->conta) {
                    $conta->update(['conta' => mb_substr($a['id'], 0, 255)]);
                }
            }
            SecretarioGoogleAgenda::where('user_id', $conta->user_id)->whereNotIn('google_id', $ids ?: [''])->delete();
            // Sem padrão escolhida, a primária é a padrão.
            if (! SecretarioGoogleAgenda::where('user_id', $conta->user_id)->where('padrao', true)->exists()) {
                SecretarioGoogleAgenda::where('user_id', $conta->user_id)->where('primaria', true)->limit(1)->update(['padrao' => true]);
            }
        });

        return ['ok' => true];
    }

    /** [início, fim, dia inteiro] no fuso da aplicação, ou null se o evento não tem horário legível. */
    private function tempo(array $e): ?array
    {
        $tz = config('app.timezone');
        try {
            if (isset($e['start']['date'], $e['end']['date'])) {
                return [CarbonImmutable::parse($e['start']['date'], $tz)->startOfDay(), CarbonImmutable::parse($e['end']['date'], $tz)->startOfDay(), true];
            }
            if (isset($e['start']['dateTime'], $e['end']['dateTime'])) {
                return [CarbonImmutable::parse($e['start']['dateTime'])->setTimezone($tz), CarbonImmutable::parse($e['end']['dateTime'])->setTimezone($tz), false];
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
