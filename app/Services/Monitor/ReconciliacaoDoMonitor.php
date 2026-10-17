<?php

namespace App\Services\Monitor;

use App\Models\MonitorAlerta;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Busca na API de leitura do AlfaMonitor os alertas que o webhook não
 * entregou (#399).
 *
 * O monitor insiste por ~8h40 no que o Matriz não recebeu e depois desiste.
 * Com o Matriz fora mais que isso, ou com a porta do webhook quebrada, o que
 * passou se perdia. A cada hora, então, o Matriz pede ao monitor as
 * notificações DEPOIS do último `event_id` que já conferiu
 * (`GET /api/v1/notificacoes?depois_de=`) e passa cada uma pelo mesmo
 * `AlertasDoMonitor::receber` do webhook.
 *
 * - Não precisa "detectar" que o Matriz ficou fora: o cursor só anda sobre o
 *   que foi conferido, e o que o webhook já trouxe volta REPETIDO pelo
 *   UNIQUE do `event_id`. Ficar fora 1 h ou 3 dias é o mesmo caminho — e a
 *   porta quebrada com o Matriz no ar também cai aqui, o que uma marca de
 *   "o agendador rodou" não pegaria.
 * - O cursor é o `event_id` do monitor (o id da notificação lá, que só
 *   cresce e não se reaproveita), e não uma hora: a lista que cresce no meio
 *   da busca não pula nem repete. Mora no cache, para sempre; se o cache se
 *   perder, volta a zero e a primeira rodada confere os 7 dias que o monitor
 *   guarda, sem duplicar nada.
 * - Mais de 7 dias fora: o monitor já apagou, e não há o que recuperar.
 * - O contrato da rota (combinado com a Central AlfaMonitor em 17/10/2026):
 *   `notificacoes` em ordem crescente de `event_id`, cada uma com o corpo
 *   v1 do webhook sem o `at`, mais `entrega`; `proximo` e `tem_mais` no
 *   envelope; as suprimidas não vêm. O filtro `entrega=falhou` existe lá, mas não é
 *   usado: uma entrega que falha DEPOIS de o cursor passar não voltaria, e
 *   o UNIQUE do `event_id` já descarta o que chegou.
 */
class ReconciliacaoDoMonitor
{
    public const CURSOR = 'alfamonitor-reconciliacao:cursor';

    /** Trava por rodada: 50 × 200 = 10 mil notificações; o resto fica para a próxima hora. */
    public const PAGINAS_POR_RODADA = 50;

    public function __construct(
        private readonly ApiDoMonitor $api,
        private readonly AlertasDoMonitor $alertas,
    ) {}

    public function cursor(): int
    {
        return (int) Cache::get(self::CURSOR, 0);
    }

    public function zerarCursor(): void
    {
        Cache::forget(self::CURSOR);
    }

    /**
     * @return array{gravados: int, repetidos: int, ignorados: int, cursor: int, paginas: int, erro: ?string, sem_rota: bool}
     */
    public function reconciliar(): array
    {
        $r = ['gravados' => 0, 'repetidos' => 0, 'ignorados' => 0, 'cursor' => $this->cursor(), 'paginas' => 0, 'erro' => null, 'sem_rota' => false];

        // Sem o monitor ligado (ambiente local, staging sem token) não há
        // o que conferir — e não é erro, para não sujar o log toda hora.
        if (! $this->api->configurada()) {
            return $r;
        }

        while ($r['paginas'] < self::PAGINAS_POR_RODADA) {
            $resposta = $this->api->notificacoes($r['cursor']);

            if ($resposta->naoEncontrado) {
                // O monitor ainda sem a rota: não é erro do Matriz, e o
                // webhook segue entregando o que pode.
                $r['sem_rota'] = true;

                break;
            }

            if (! $resposta->temDados()) {
                $r['erro'] = $resposta->erro;

                break;
            }

            $r['paginas']++;
            $antes = $r['cursor'];
            $itens = array_values(array_filter((array) $resposta->get('notificacoes', []), 'is_array'));
            $maior = $r['cursor'];

            foreach ($itens as $item) {
                $maior = max($maior, (int) ($item['event_id'] ?? 0));
                $r[$this->aplicar($item)]++;
            }

            $proximo = max($maior, (int) $resposta->get('proximo', 0));

            // O cursor anda a cada página conferida: uma falha na página 5 não
            // faz a próxima rodada repassar as 4 primeiras.
            if ($proximo > $r['cursor']) {
                $r['cursor'] = $proximo;
                Cache::forever(self::CURSOR, $proximo);
            }

            $temMais = (bool) $resposta->get('tem_mais', count($itens) >= ApiDoMonitor::POR_PAGINA);

            // Sem avanço não há "mais": pedir de novo daria a mesma página.
            if (! $temMais || $itens === [] || $r['cursor'] <= $antes) {
                break;
            }
        }

        if ($r['gravados'] > 0) {
            $this->api->invalidar();

            Log::info('AlfaMonitor: a reconciliação recuperou alertas que o webhook não entregou.', [
                'gravados' => $r['gravados'],
                'cursor' => $r['cursor'],
            ]);
        }

        if ($r['erro']) {
            Log::warning('AlfaMonitor: a reconciliação parou.', ['erro' => $r['erro'], 'cursor' => $r['cursor']]);
        }

        return $r;
    }

    /**
     * Cada notificação passa pela mesma porta do webhook. O que nunca vai
     * entrar (kind fora do escopo, corpo quebrado) conta como ignorado e o
     * cursor passa por cima — senão travaria a reconciliação para sempre.
     *
     * @param  array<string, mixed>  $item
     * @return 'gravados'|'repetidos'|'ignorados'
     */
    private function aplicar(array $item): string
    {
        // A suprimida (silenciada, agrupada, regra sem canal) o webhook nunca
        // manda, e a rota não a devolve (combinado). Se um dia vier marcada,
        // continua de fora.
        if (! empty($item['suprimida']) || ! array_key_exists($item['kind'] ?? null, MonitorAlerta::KINDS)) {
            return 'ignorados';
        }

        $validacao = Validator::make($item, AlertasDoMonitor::regras());

        if ($validacao->fails()) {
            Log::warning('AlfaMonitor: notificação da reconciliação recusada.', [
                'event_id' => $item['event_id'] ?? null,
                'erros' => $validacao->errors()->all(),
            ]);

            return 'ignorados';
        }

        return $this->alertas->receber($validacao->validated()) === AlertasDoMonitor::GRAVADO
            ? 'gravados'
            : 'repetidos';
    }
}
