<?php

namespace App\Services\Secretario\Acompanhamento;

use App\Models\Notificacao;
use App\Models\SecretarioAcompanhamento;
use App\Models\SecretarioAcompanhamentoEnvio;
use App\Models\SecretarioPreferencia;
use App\Models\User;
use App\Services\CentralIa\Eventos;
use App\Services\CentralIa\ProtecaoDeSegredos;
use App\Services\Secretario\Despachante;
use App\Services\Secretario\TelegramDoSecretario;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * O resumo agrupado (FR-004, FR-006): um por vez, no sino e no Telegram, com
 * registro de cada envio. O Telegram segue o desenho do briefing: `enviando`
 * antes da chamada, retentativa até +30 min, preso vira `falhou` — e nunca
 * duas vezes. O rastro de eventos leva só ids, nunca o texto das situações.
 */
class Envio
{
    public const LINHAS = 8;

    public function __construct(private Politica $politica, private TelegramDoSecretario $telegram) {}

    /** Decide e registra o resumo da conta; devolve quantos acompanhamentos entraram. */
    public function preparar(User $user, CarbonImmutable $agora): int
    {
        $lista = $this->politica->resumo($user, $agora);
        if ($lista->isEmpty()) {
            return 0;
        }
        $ids = $lista->pluck('id')->all();
        $telegram = SecretarioPreferencia::de($user)->telegram && $this->telegram->destino($user);

        DB::transaction(function () use ($user, $lista, $ids, $telegram, $agora) {
            $sino = SecretarioAcompanhamentoEnvio::create(['user_id' => $user->id, 'canal' => 'sino', 'status' => SecretarioAcompanhamentoEnvio::ENTREGUE,
                'acompanhamentos' => $ids, 'quantos' => count($ids), 'tentativas' => 1, 'entregue_em' => $agora]);
            $alta = $lista->contains('prioridade', 'alta');
            // Um aviso de acompanhamento por vez: o novo substitui os não lidos anteriores (22/10/2026:
            // três "Para acompanhar" empilhados no sino, todos do mesmo conflito).
            Notificacao::where('destinatario_id', $user->id)->where('tipo', 'acompanhamento')->whereNull('lida_em')->delete();
            Notificacao::create([
                'destinatario_id' => $user->id, 'tipo' => 'acompanhamento', 'nivel' => $alta ? 'atencao' : 'neutro', 'sonora' => $alta, 'icone' => 'bell',
                'titulo' => Notificacao::caber('Para acompanhar ('.count($ids).')'),
                'meta' => Notificacao::caber($lista->first()->texto.(count($ids) > 1 ? ' · e mais '.(count($ids) - 1) : '')),
                // Abre a tela já com OS itens deste aviso no topo (Felipe, 22/10/2026: "cliquei e não mostra o
                // que tenho que resolver em cima desse alerta").
                // Pelo envio, e não pela lista de ids: com muitos itens o endereço não caberia na coluna.
                'rota' => route('secretario.acompanhamentos', ['aviso' => $sino->id]).'#do-aviso',
            ]);
            if ($telegram) {
                SecretarioAcompanhamentoEnvio::create(['user_id' => $user->id, 'canal' => 'telegram', 'acompanhamentos' => $ids, 'quantos' => count($ids)]);
            }
            SecretarioAcompanhamento::whereIn('id', $ids)->update(['avisado_em' => $agora, 'avisos' => DB::raw('avisos + 1')]);
        });

        return count($ids);
    }

    public function texto(Collection $lista, int $total): string
    {
        $linhas = ['🔔 Para acompanhar ('.$total.')'];
        foreach ($lista->take(self::LINHAS) as $a) {
            $linhas[] = $a->marca().' '.Str::limit($a->texto, 160);
        }
        if ($total > self::LINHAS) {
            $linhas[] = '+'.($total - self::LINHAS).' na tela';
        }
        $linhas[] = 'Ver todos: '.route('secretario.acompanhamentos');

        return implode("\n", $linhas);
    }

    /** Entrega os resumos pendentes do Telegram. */
    public function entregarPendentes(): int
    {
        SecretarioAcompanhamentoEnvio::where('canal', 'telegram')->where('status', SecretarioAcompanhamentoEnvio::ENVIANDO)
            ->where('updated_at', '<', now()->subMinutes(2))->update(['status' => SecretarioAcompanhamentoEnvio::FALHOU, 'erro' => 'interrompida']);
        $entregues = 0;
        $ids = SecretarioAcompanhamentoEnvio::where('canal', 'telegram')->where('status', SecretarioAcompanhamentoEnvio::PENDENTE)
            ->where(fn ($q) => $q->whereNull('proxima_tentativa_em')->orWhere('proxima_tentativa_em', '<=', now()))
            ->orderBy('id')->limit(50)->pluck('id');
        foreach ($ids as $id) {
            $envio = DB::transaction(function () use ($id) {
                $e = SecretarioAcompanhamentoEnvio::whereKey($id)->lockForUpdate()->first();
                if (! $e || $e->status !== SecretarioAcompanhamentoEnvio::PENDENTE) {
                    return null;
                }
                $e->update(['status' => SecretarioAcompanhamentoEnvio::ENVIANDO, 'tentativas' => $e->tentativas + 1]);

                return $e;
            });
            if (! $envio) {
                continue;
            }
            // O texto é montado na hora da entrega, só com o que ainda está ativo.
            $lista = SecretarioAcompanhamento::where('user_id', $envio->user_id)->whereIn('id', $envio->acompanhamentos)->ativos()->get();
            if ($lista->isEmpty()) {
                $envio->update(['status' => SecretarioAcompanhamentoEnvio::FALHOU, 'erro' => 'nada_ativo']);

                continue;
            }
            $d = $this->telegram->destino($envio->user_id);
            $r = $d ? $d[0]->chamar('sendMessage', ['chat_id' => $d[1], 'text' => ProtecaoDeSegredos::texto($this->texto($lista, $lista->count())),
                'link_preview_options' => ['is_disabled' => true],
                'reply_markup' => ['inline_keyboard' => [[['text' => '⏸ Adiar estes até amanhã', 'callback_data' => 's:ac:'.$envio->id]]]]])
                : ['ok' => false, 'erro' => 'sem_bot'];
            if ($r['ok']) {
                $envio->update(['status' => SecretarioAcompanhamentoEnvio::ENTREGUE, 'entregue_em' => now(), 'erro' => null, 'proxima_tentativa_em' => null,
                    'telegram_message_id' => $r['resultado']['message_id'] ?? null]);
                $entregues++;

                continue;
            }
            $espera = Despachante::ESPERAS[min($envio->tentativas - 1, count(Despachante::ESPERAS) - 1)];
            if (in_array($r['erro'], ['nao_enviada', 'limite'], true) && now()->addMinutes($espera)->lessThanOrEqualTo($envio->created_at->copy()->addMinutes(Despachante::JANELA_TENTATIVAS_MIN))) {
                $envio->update(['status' => SecretarioAcompanhamentoEnvio::PENDENTE, 'erro' => $r['erro'], 'proxima_tentativa_em' => now()->addMinutes($espera)]);

                continue;
            }
            $envio->update(['status' => SecretarioAcompanhamentoEnvio::FALHOU, 'erro' => mb_substr((string) $r['erro'], 0, 40)]);
            Eventos::registrar($envio->user_id, 'secretario_acompanhamento_falhou', "Resumo de acompanhamento #{$envio->id} não chegou ao Telegram ({$r['erro']}).", ['origem' => 'telegram'], 'aviso');
        }

        return $entregues;
    }

    /** "Adiar estes até amanhã": o toque do Telegram e o botão da tela. */
    public function adiar(User $user, array $ids, CarbonImmutable $ate): int
    {
        return SecretarioAcompanhamento::de($user)->whereIn('id', $ids)->where('situacao', SecretarioAcompanhamento::ATIVO)->update(['adiado_ate' => $ate]);
    }
}
