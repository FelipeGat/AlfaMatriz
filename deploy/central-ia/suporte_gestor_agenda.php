<?php
/**
 * Uma mudança na agenda dos técnicos, pedida ao Pedro e confirmada com o "sim"
 * (docs/specs/pedro-dia-do-tecnico, contrato §3). Roda DENTRO do Gestor de produção pela
 * ponte da máquina do dono, como o suporte_gestor_gravar.php; sem --apply, faz e desfaz.
 *
 * A regra é a do Gestor (AtendimentoVisitaService: conflito, cancelada não reagenda,
 * finalizada volta a agendada, cancelar finalizada exige motivo). Aqui só:
 *   - criar_visita: no atendimento pelo número, com início e fim exatos; se a mesma visita
 *     (técnico, atendimento, início e fim) já existe, devolve ela — o "sim" repetido não duplica;
 *   - reagendar_visita / cancelar_visita: só se a visita ainda está como a prévia mostrou
 *     ("esperado"); se mudou, recusa e o Pedro remonta a prévia. Já no estado pedido: ok.
 *
 * Saída: UMA linha "SUPORTE_AGENDA:{json}".
 */
require '/var/www/vendor/autoload.php';
$app = require_once '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Atendimento;
use App\Models\AtendimentoVisita;
use App\Models\User;
use App\Services\AtendimentoVisitaService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

$d = json_decode(base64_decode('__DADOS__'), true);
$aplicar = getenv('APPLY') === '1';
$saida = fn (array $r) => print('SUPORTE_AGENDA:'.json_encode($r + ['ensaio' => ! $aplicar], JSON_UNESCAPED_UNICODE)."\n");
$hora = fn (?string $t) => $t ? Carbon::createFromFormat('Y-m-d H:i', $t, config('app.timezone')) : null;
$nota = fn (?string $atual, ?string $nova) => trim(implode("\n", array_filter([trim((string) $atual), $nova ? 'Pedro ('.($d['pedido_por'] ?? 'equipe').'): '.$nova : null])));

try {
    if (! class_exists(AtendimentoVisitaService::class) || ! method_exists(AtendimentoVisitaService::class, 'criar')) {
        throw new RuntimeException('o Gestor ainda não tem o serviço de visitas — publicar o Gestor antes');
    }
    $servico = app(AtendimentoVisitaService::class);
    $autor = User::where('email', (string) ($d['autor_email'] ?? ''))->value('id');
    [$inicio, $fim] = [$hora($d['inicio'] ?? null), $hora($d['fim'] ?? null)];
    $tecnico = (int) ($d['tecnico_id'] ?? 0);

    DB::beginTransaction();
    if (($d['tipo'] ?? '') === 'criar_visita') {
        $at = Atendimento::where('numero_atendimento', (string) ($d['atendimento'] ?? ''))->first();
        if (! $at) {
            throw new RuntimeException('atendimento nº '.($d['atendimento'] ?? '?').' não encontrado');
        }
        $mesma = AtendimentoVisita::where('atendimento_id', $at->id)->where('funcionario_id', $tecnico)->where('status', '!=', AtendimentoVisita::STATUS_CANCELADO)
            ->where('data_inicio', $inicio)->where('data_fim', $fim)->first();
        $v = $mesma ?? $servico->criar($at, $tecnico, $inicio, $fim, $autor);
        if (! $mesma && ! empty($d['observacao'])) {
            $v->observacoes = $nota($v->observacoes, $d['observacao']);
            $v->save();
        }
        $resultado = ['ok' => true, 'visita_id' => $v->id, 'numero' => (string) $at->numero_atendimento, 'ja_estava' => (bool) $mesma];
    } elseif (in_array($d['tipo'] ?? '', ['reagendar_visita', 'cancelar_visita'], true)) {
        $v = AtendimentoVisita::with('atendimento')->find((int) ($d['visita_id'] ?? 0));
        if (! $v) {
            throw new RuntimeException('visita '.($d['visita_id'] ?? '?').' não encontrada');
        }
        $agora = ['inicio' => $v->data_inicio?->format('Y-m-d H:i'), 'fim' => $v->data_fim?->format('Y-m-d H:i'), 'situacao' => (string) $v->status];
        $cancelar = $d['tipo'] === 'cancelar_visita';
        $jaEsta = $cancelar ? $v->status === AtendimentoVisita::STATUS_CANCELADO
            : $agora['inicio'] === ($d['inicio'] ?? null) && $agora['fim'] === ($d['fim'] ?? null) && (int) $v->funcionario_id === $tecnico && $v->status !== AtendimentoVisita::STATUS_CANCELADO;
        $esperado = (array) ($d['esperado'] ?? []);
        if (! $jaEsta && ($agora['inicio'] !== ($esperado['inicio'] ?? null) || $agora['fim'] !== ($esperado['fim'] ?? null) || $agora['situacao'] !== ($esperado['situacao'] ?? null))) {
            throw new RuntimeException('a visita mudou desde a prévia (agora '.substr((string) $agora['inicio'], 11).' às '.substr((string) $agora['fim'], 11).', '.$agora['situacao'].')');
        }
        if (! $jaEsta) {
            $v = $cancelar ? $servico->cancelar($v, $autor, (string) ($d['motivo'] ?? ''))
                : $servico->reagendar($v, $tecnico, $inicio, $fim, 'personalizado');
            if (! empty($d['observacao'])) {
                $v->observacoes = $nota($v->observacoes, $d['observacao']);
                $v->save();
            }
        }
        $resultado = ['ok' => true, 'visita_id' => $v->id, 'numero' => (string) $v->atendimento?->numero_atendimento, 'ja_estava' => $jaEsta];
    } else {
        throw new RuntimeException('tipo de mudança desconhecido');
    }
    $aplicar ? DB::commit() : DB::rollBack();
    $saida($resultado);
} catch (Throwable $e) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    // Recusa de regra (DomainException, ValidationException) vai com a frase do Gestor; o resto, com a classe.
    $msg = $e instanceof DomainException || $e instanceof RuntimeException ? $e->getMessage()
        : ($e instanceof Illuminate\Validation\ValidationException ? collect($e->errors())->flatten()->first() : $e::class.' em '.basename($e->getFile()).':'.$e->getLine());
    $saida(['ok' => false, 'erro' => mb_substr((string) $msg, 0, 280)]);
}
