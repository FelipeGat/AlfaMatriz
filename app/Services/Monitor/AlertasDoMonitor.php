<?php

namespace App\Services\Monitor;

use App\Models\Cliente;
use App\Models\MonitorAlerta;
use App\Models\MonitorEvento;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * O que o Matriz faz com cada alerta que o AlfaMonitor empurra (#394).
 *
 * SÓ GUARDA. Não abre tarefa, não toca o sino, não manda Telegram: quem avisa
 * é o monitor, e um segundo aviso aqui seria o mesmo alerta duas vezes no
 * celular de quem está de plantão (decisão do dono, 17/10/2026).
 *
 * O contrato (combinado com a Central AlfaMonitor) diz o que se pode esperar:
 * - a MESMA notificação pode chegar mais de uma vez (nova tentativa por até
 *   ~8h40) — o `event_id` é a trava;
 * - a ordem NÃO é garantida — um "voltou" pode chegar antes da queda que
 *   ficou na fila. Quem decide é o `occurred_at` (e o `event_id` no empate);
 * - `derived` é lembrete ("ainda fora há 4h") ou escalonamento, e não queda
 *   nova.
 */
class AlertasDoMonitor
{
    public const GRAVADO = 'gravado';

    public const REPETIDO = 'repetido';

    /**
     * O que um evento precisa ter para entrar. Valem igual para o webhook
     * (`MonitorController`) e para a reconciliação (`ReconciliacaoDoMonitor`,
     * #399): o mesmo evento não pode passar por uma porta e ser recusado na
     * outra.
     *
     * @return array<string, string>
     */
    public static function regras(): array
    {
        return [
            'event_id' => 'required|integer|min:1',
            'incident_id' => 'nullable|integer|min:1',
            'kind' => 'required|string',
            'entity' => 'required|string|max:191',
            'entity_name' => 'nullable|string|max:255',
            'severity' => 'required|in:'.implode(',', array_keys(MonitorAlerta::SEVERIDADES)),
            'cliente' => 'nullable|string|max:255',
            'title' => 'required|string|max:255',
            'body' => 'nullable|string|max:10000',
            'resolved' => 'required|boolean',
            'derived' => 'nullable|boolean',
            'link' => 'nullable|string|max:500',
            'at' => 'nullable|integer|min:0',
            'occurred_at' => 'required|integer|min:0',
        ];
    }

    /**
     * @param  array{event_id: int, incident_id?: ?int, kind: string, entity: string, entity_name?: ?string,
     *               severity: string, cliente?: ?string, title: string, body?: ?string, resolved: bool,
     *               derived?: bool, link?: ?string, at?: ?int, occurred_at: int}  $e
     */
    public function receber(array $e): string
    {
        try {
            return DB::transaction(fn () => $this->aplicar($e));
        } catch (UniqueConstraintViolationException) {
            // Duas tentativas do mesmo evento ao mesmo tempo: a segunda bate no
            // UNIQUE do event_id (ou do par kind+entity, na primeira vez da
            // entidade). Para o monitor é a mesma coisa — já chegou.
            return MonitorEvento::where('event_id', $e['event_id'])->exists()
                ? self::REPETIDO
                : DB::transaction(fn () => $this->aplicar($e));
        }
    }

    private function aplicar(array $e): string
    {
        if (MonitorEvento::where('event_id', $e['event_id'])->exists()) {
            return self::REPETIDO;
        }

        $ocorreu = Carbon::createFromTimestamp($e['occurred_at'], config('app.timezone'));
        $derivado = (bool) ($e['derived'] ?? false);
        $resolvido = (bool) $e['resolved'];

        $alerta = MonitorAlerta::where('kind', $e['kind'])->where('entity', $e['entity'])->lockForUpdate()->first()
            ?? new MonitorAlerta(['kind' => $e['kind'], 'entity' => $e['entity']]);

        $novo = ! $alerta->exists;
        $emOrdem = $novo || $this->depoisDoEstado($alerta, $ocorreu, $e['event_id']);
        $aplicado = ! $derivado && $emOrdem;

        if ($aplicado) {
            $this->mudarEstado($alerta, $e, $ocorreu, $resolvido);
        } elseif (! $derivado && ! $resolvido) {
            $this->encaixarQuedaAtrasada($alerta, $e, $ocorreu);
        }

        if ($novo && ! $aplicado) {
            // Primeiro evento da entidade e é lembrete: a linha precisa existir
            // para o histórico ter onde pendurar, e o lembrete diz que está fora.
            $this->mudarEstado($alerta, $e, $ocorreu, $resolvido);
        }

        if (! $alerta->ultimo_aviso_em || $ocorreu->gt($alerta->ultimo_aviso_em)) {
            $alerta->ultimo_aviso_em = $ocorreu;
        }

        $alerta->save();

        MonitorEvento::create([
            'monitor_alerta_id' => $alerta->id,
            'event_id' => $e['event_id'],
            'incident_id' => $e['incident_id'] ?? null,
            'severity' => $e['severity'],
            'resolved' => $resolvido,
            'derived' => $derivado,
            'title' => $e['title'],
            'body' => $e['body'] ?? null,
            'ocorreu_em' => $ocorreu,
            'enviado_em' => isset($e['at']) ? Carbon::createFromTimestamp($e['at'], config('app.timezone')) : null,
            'aplicado' => $aplicado,
        ]);

        return self::GRAVADO;
    }

    private function depoisDoEstado(MonitorAlerta $alerta, Carbon $ocorreu, int $eventId): bool
    {
        if (! $alerta->ultimo_evento_em) {
            return true;
        }

        return $ocorreu->gt($alerta->ultimo_evento_em)
            || ($ocorreu->eq($alerta->ultimo_evento_em) && $eventId > (int) $alerta->ultimo_event_id);
    }

    private function mudarEstado(MonitorAlerta $alerta, array $e, Carbon $ocorreu, bool $resolvido): void
    {
        $this->atualizarDescricao($alerta, $e);

        if ($resolvido) {
            // A volta vem como "info": a gravidade que fica é a da queda, que é
            // o que o histórico precisa lembrar. Sem queda conhecida, fica a da
            // própria volta.
            $alerta->voltou_sem_queda = ! $alerta->aberto;
            $alerta->aberto = false;
            $alerta->voltou_em = $ocorreu;
            $alerta->severity = $alerta->severity ?: $e['severity'];
        } else {
            if (! $alerta->aberto) {
                $alerta->quedas++;
                $alerta->caiu_em = $ocorreu;
                $alerta->voltou_em = null;
                $alerta->voltou_sem_queda = false;
                $alerta->incident_id = $e['incident_id'] ?? $e['event_id'];
            }

            $alerta->aberto = true;
            $alerta->severity = $e['severity'];
        }

        $alerta->ultimo_evento_em = $ocorreu;
        $alerta->ultimo_event_id = $e['event_id'];
    }

    /**
     * A queda que chegou DEPOIS da volta (estava na fila de novas tentativas).
     * O estado não muda — a coisa já voltou —, mas a linha deixa de dizer
     * "voltou sem queda registrada": agora se sabe desde quando ficou fora.
     */
    private function encaixarQuedaAtrasada(MonitorAlerta $alerta, array $e, Carbon $ocorreu): void
    {
        if ($alerta->aberto || ! $alerta->voltou_sem_queda || ! $alerta->voltou_em || $ocorreu->gt($alerta->voltou_em)) {
            return;
        }

        $alerta->voltou_sem_queda = false;
        $alerta->caiu_em = $ocorreu;
        $alerta->quedas++;
        $alerta->severity = $e['severity'];
        $alerta->incident_id = $e['incident_id'] ?? $e['event_id'];
    }

    /** Nome, cliente, título e detalhe: o retrato mais novo da coisa. */
    private function atualizarDescricao(MonitorAlerta $alerta, array $e): void
    {
        $alerta->title = $e['title'];
        $alerta->body = $e['body'] ?? null;
        $alerta->link = $e['link'] ?? null;
        $alerta->entity_name = ($e['entity_name'] ?? null) ?: $alerta->entity_name;

        $cliente = ($e['cliente'] ?? null) ?: null;

        if ($cliente !== $alerta->cliente || ($cliente && ! $alerta->cliente_id)) {
            $alerta->cliente = $cliente;
            $alerta->cliente_id = $cliente ? $this->clienteDoMatriz($cliente)?->id : null;
        }
    }

    /**
     * O cliente do Matriz com esse nome, se houver UM só. O monitor manda só
     * o nome (o do cadastro do AlfaControl ou do AlfaGym), então a comparação
     * é sem acento, sem caixa e sem espaço repetido, contra nome, fantasia e
     * razão social. Dois candidatos: nenhum — ligar ao cliente errado é pior
     * que não ligar.
     */
    private function clienteDoMatriz(string $nome): ?Cliente
    {
        $alvo = self::normalizar($nome);

        $candidatos = Cliente::query()
            ->get(['id', 'nome', 'nome_fantasia', 'razao_social'])
            ->filter(fn (Cliente $c) => collect([$c->nome, $c->nome_fantasia, $c->razao_social])
                ->filter()
                ->contains(fn ($n) => self::normalizar($n) === $alvo));

        return $candidatos->count() === 1 ? $candidatos->first() : null;
    }

    public static function normalizar(string $texto): string
    {
        return Str::of($texto)->ascii()->lower()->squish()->toString();
    }
}
