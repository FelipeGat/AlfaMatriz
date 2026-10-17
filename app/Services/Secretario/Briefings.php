<?php

namespace App\Services\Secretario;

use App\Models\Perfil;
use App\Models\SecretarioBriefing;
use App\Models\SecretarioGoogleConta;
use App\Models\SecretarioPreferencia;
use App\Models\User;
use App\Services\CentralIa\Eventos;
use App\Services\CentralIa\ProtecaoDeSegredos;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Quando gerar, gerar e entregar o briefing (docs/specs/secretario-briefing).
 * Um automático por conta e dia: a chave única da tabela é quem segura a
 * repetição; parado no horário, o do dia sai na volta até o meio-dia (FR-003).
 */
class Briefings
{
    public const LIMITE_ATRASO = '12:00';
    public const SINCRONIZAR_SE_MAIS_VELHO_MIN = 10;

    public function __construct(private MontadorDeBriefing $montador, private TelegramDoSecretario $telegram, private SincronizadorGoogle $sincronizador) {}

    /** As contas cujo briefing automático de hoje é devido agora. */
    public function devidos(CarbonImmutable $agora): \Illuminate\Support\Collection
    {
        if ($agora->format('H:i') >= self::LIMITE_ATRASO) {
            return collect();
        }

        return Contas::comSecretario()
            ->filter(function (User $u) use ($agora) {
                $p = SecretarioPreferencia::de($u);

                return $p->briefing
                    && in_array($agora->dayOfWeekIso, array_map('intval', (array) $p->briefing_dias), true)
                    && $agora->format('H:i') >= $p->briefing_hora
                    && ! SecretarioBriefing::de($u)->whereDate('data', $agora->toDateString())->where('origem', SecretarioBriefing::AUTOMATICO)->exists();
            })->values();
    }

    /** Gera (e grava) o briefing; null se o automático do dia já existia. */
    public function gerar(User $user, CarbonImmutable $agora, string $origem = SecretarioBriefing::AUTOMATICO): ?SecretarioBriefing
    {
        $this->sincronizarSeVelho($user);
        $m = $this->montador->montar($user, $agora, $origem === SecretarioBriefing::PEDIDO);
        $telegram = SecretarioPreferencia::de($user)->telegram && $this->telegram->destino($user) ? 'pendente' : 'sem';
        try {
            $b = DB::transaction(function () use ($user, $agora, $origem, $m, $telegram) {
                $atributos = ['texto' => $m['texto'], 'fontes' => $m['fontes'], 'gerado_em' => now()];
                if ($origem === SecretarioBriefing::PEDIDO) {
                    // Sob demanda: atualiza o do dia; a resposta vai pela conversa, não pelo despacho.
                    return SecretarioBriefing::updateOrCreate(['user_id' => $user->id, 'data' => $agora->toDateString(), 'origem' => $origem], $atributos + ['telegram' => 'sem']);
                }

                return SecretarioBriefing::create(['user_id' => $user->id, 'data' => $agora->toDateString(), 'origem' => $origem, 'telegram' => $telegram] + $atributos);
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
        if ($origem === SecretarioBriefing::AUTOMATICO) {
            Eventos::registrar($user, 'secretario_briefing', "Briefing do dia {$agora->format('d/m')} gerado.", ['origem' => 'secretario']);
        }

        return $b;
    }

    /** Entrega no Telegram o que está pendente (com tentativas até +30 min). */
    public function entregarPendentes(): int
    {
        SecretarioBriefing::where('telegram', 'enviando')->where('updated_at', '<', now()->subMinutes(2))->update(['telegram' => 'falhou']);
        $entregues = 0;
        $ids = SecretarioBriefing::where('telegram', 'pendente')
            ->where(fn ($q) => $q->whereNull('proxima_tentativa_em')->orWhere('proxima_tentativa_em', '<=', now()))
            ->orderBy('id')->limit(50)->pluck('id');
        foreach ($ids as $id) {
            $b = DB::transaction(function () use ($id) {
                $b = SecretarioBriefing::whereKey($id)->lockForUpdate()->first();
                if (! $b || $b->telegram !== 'pendente') {
                    return null;
                }
                $b->update(['telegram' => 'enviando', 'tentativas' => $b->tentativas + 1]);

                return $b;
            });
            if (! $b) {
                continue;
            }
            $d = $this->telegram->destino($b->user_id);
            $r = $d ? $d[0]->chamar('sendMessage', ['chat_id' => $d[1], 'text' => ProtecaoDeSegredos::texto($b->texto), 'link_preview_options' => ['is_disabled' => true]])
                : ['ok' => false, 'erro' => 'sem_bot'];
            if ($r['ok']) {
                $b->update(['telegram' => 'entregue', 'proxima_tentativa_em' => null]);
                $entregues++;

                continue;
            }
            $espera = Despachante::ESPERAS[min($b->tentativas - 1, count(Despachante::ESPERAS) - 1)];
            if (in_array($r['erro'], ['nao_enviada', 'limite'], true) && now()->addMinutes($espera)->lessThanOrEqualTo($b->gerado_em->copy()->addMinutes(Despachante::JANELA_TENTATIVAS_MIN))) {
                $b->update(['telegram' => 'pendente', 'proxima_tentativa_em' => now()->addMinutes($espera)]);

                continue;
            }
            $b->update(['telegram' => 'falhou']);
            Eventos::registrar($b->user_id, 'secretario_briefing_falhou', "Briefing de {$b->data->format('d/m')} não chegou ao Telegram ({$r['erro']}).", ['origem' => 'telegram'], 'aviso');
        }

        return $entregues;
    }

    private function sincronizarSeVelho(User $user): void
    {
        $conta = SecretarioGoogleConta::where('user_id', $user->id)->where('situacao', SecretarioGoogleConta::CONECTADA)->first();
        if ($conta && (! $conta->sincronizado_em || $conta->sincronizado_em->lessThan(now()->subMinutes(self::SINCRONIZAR_SE_MAIS_VELHO_MIN)))) {
            try {
                $this->sincronizador->sincronizar($conta);
            } catch (\Throwable) {
                // A falha não impede o briefing (FR-007): o rodapé diz que o Google está desatualizado.
            }
        }
    }

    /** "Resume meu dia": o briefing de agora, sem IA. */
    public function frase(string $texto): bool
    {
        $t = trim(strtr(mb_strtolower($texto), ['á' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c']), " ?!.");
        $t = preg_replace('/^(matriz|secretario)[,:!]?\s+/u', '', $t);

        return (bool) preg_match('/^(\/?briefing|resum[eao]\s+(o\s+)?(meu\s+)?dia|resumo\s+do\s+(meu\s+)?dia|como\s+(esta|ta|vai)\s+(o\s+)?meu\s+dia|meu\s+dia|o\s+que\s+tenho\s+(pra|para)?\s*hoje)$/u', $t);
    }
}
