<?php

namespace App\Services\Secretario;

use App\Models\Notificacao;
use App\Models\SecretarioAviso;
use App\Models\SecretarioItem;
use App\Models\SecretarioPreferencia;
use App\Models\User;
use App\Services\CentralIa\Eventos;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Entrega os avisos do Secretário: sino sempre, Telegram quando pareado
 * (research D3–D6). Roda pelo `schedule:run` a cada minuto, sem fila e sem
 * IA — o lembrete chega com a máquina do dono desligada.
 *
 * Exatamente uma vez: a chave única (item, para, canal) impede o segundo
 * aviso; `enviando` é gravado ANTES da chamada ao Telegram, que fica fora da
 * transação; preso em `enviando` vira `incerta` e não sai de novo — o mesmo
 * desenho de `TelegramCanal::entregarInterno`.
 */
class Despachante
{
    /** Espera entre tentativas, em minutos; depois de `para + 30 min`, falhou. */
    public const ESPERAS = [1, 2, 4, 8, 15];
    public const JANELA_TENTATIVAS_MIN = 30;
    public const HORA_DO_PRAZO = '08:00';

    public function __construct(private TelegramDoSecretario $telegram) {}

    /** Quando avisar este item, ou null se ele não tem aviso (tarefa sem data). */
    public function horaDoAviso(SecretarioItem $item): ?CarbonInterface
    {
        if (! $item->quando || $item->encerrado()) {
            return null;
        }
        if ($item->so_data) {
            return $item->quando->copy()->setTimeFromTimeString(self::HORA_DO_PRAZO);
        }
        $antes = $item->antecedencia_min ?? 0;

        return $item->quando->copy()->subMinutes($antes);
    }

    /**
     * (Re)programa os avisos do item. `$cancelarAnteriores`: o item mudou de
     * hora (adiar, reagendar, concluir) e nada pendente dele vale mais; a
     * recorrência que avança NÃO cancela a tentativa em curso da anterior.
     */
    public function programar(SecretarioItem $item, bool $cancelarAnteriores = true): void
    {
        DB::transaction(function () use ($item, $cancelarAnteriores) {
            if ($cancelarAnteriores) {
                SecretarioAviso::where('item_id', $item->id)->where('status', SecretarioAviso::PENDENTE)
                    ->update(['status' => SecretarioAviso::CANCELADO]);
            }
            $para = $this->horaDoAviso($item);
            if (! $para) {
                return;
            }
            // Aviso que já deveria ter saído (compromisso daqui a 10 min com
            // antecedência de 30) sai agora, e não nunca.
            if ($para->lessThan(now()->startOfMinute())) {
                $para = now()->startOfMinute();
            }
            foreach ($this->canais($item->user_id) as $canal) {
                $this->criar($item, $para, $canal);
            }
        });
    }

    /** Um aviso a mais do mesmo item (adiar uma ocorrência de item recorrente). */
    public function avisoExtra(SecretarioItem $item, CarbonInterface $para): void
    {
        foreach ($this->canais($item->user_id) as $canal) {
            $this->criar($item, $para->copy()->startOfMinute(), $canal);
        }
    }

    private function criar(SecretarioItem $item, CarbonInterface $para, string $canal): void
    {
        $existente = SecretarioAviso::where('item_id', $item->id)->where('para', $para)->where('canal', $canal)->first();
        if ($existente) {
            // Mesmo horário reprogramado depois de cancelado: volta a valer.
            if ($existente->status === SecretarioAviso::CANCELADO) {
                $existente->update(['status' => SecretarioAviso::PENDENTE, 'tentativas' => 0, 'proxima_tentativa_em' => null, 'erro' => null]);
            }

            return;
        }
        try {
            SecretarioAviso::create(['item_id' => $item->id, 'user_id' => $item->user_id, 'para' => $para, 'canal' => $canal]);
        } catch (UniqueConstraintViolationException) {
            // Corrida com outro programar do mesmo item: o aviso já existe.
        }
    }

    /** @return list<string> */
    private function canais(int $userId): array
    {
        $user = User::find($userId);
        $canais = [SecretarioAviso::SINO];
        if ($user && SecretarioPreferencia::de($user)->telegram && $this->telegram->destino($userId)) {
            $canais[] = SecretarioAviso::TELEGRAM;
        }

        return $canais;
    }

    /** Uma rodada: entrega o que venceu e avança as recorrências. Devolve quantos saíram. */
    public function despachar(int $limite = 100): int
    {
        SecretarioAviso::where('status', SecretarioAviso::ENVIANDO)->where('updated_at', '<', now()->subMinutes(2))
            ->update(['status' => SecretarioAviso::INCERTA, 'erro' => 'interrompida']);

        $ids = SecretarioAviso::where('status', SecretarioAviso::PENDENTE)->where('para', '<=', now())
            ->where(fn ($q) => $q->whereNull('proxima_tentativa_em')->orWhere('proxima_tentativa_em', '<=', now()))
            ->orderBy('para')->orderBy('id')->limit($limite)->pluck('id');

        $entregues = 0;
        foreach ($ids as $id) {
            $entregues += $this->entregar($id) ? 1 : 0;
        }
        $this->avancarRecorrentes();

        return $entregues;
    }

    private function entregar(int $id): bool
    {
        $reserva = DB::transaction(function () use ($id) {
            $aviso = SecretarioAviso::whereKey($id)->lockForUpdate()->first();
            if (! $aviso || $aviso->status !== SecretarioAviso::PENDENTE) {
                return null;
            }
            $item = SecretarioItem::whereKey($aviso->item_id)->lockForUpdate()->first();
            $user = User::find($aviso->user_id);
            // Concluído, cancelado ou conta sem o Secretário: não avisa.
            if (! $item || $item->encerrado() || ! $user || ! $user->ativo || ! $user->canPermissao('secretario', 'ler')) {
                $aviso->update(['status' => SecretarioAviso::CANCELADO, 'erro' => 'sem_efeito']);

                return null;
            }
            $atrasado = now()->diffInSeconds($aviso->para, true) > 120;
            if ($aviso->canal === SecretarioAviso::SINO) {
                Notificacao::create([
                    'destinatario_id' => $user->id,
                    'tipo' => 'lembrete',
                    'nivel' => 'atencao',
                    'sonora' => true,
                    'icone' => 'clock',
                    'titulo' => ($item->tipo === 'compromisso' ? 'Compromisso: ' : 'Lembrete: ').$item->titulo,
                    'meta' => ($item->quando ? Datas::porExtenso($item->quando, $item->so_data) : '').($atrasado ? ' · atrasado' : ''),
                    'rota' => route('secretario.meu-dia'),
                ]);
                $aviso->update(['status' => SecretarioAviso::ENTREGUE, 'entregue_em' => now(), 'atrasado' => $atrasado, 'tentativas' => $aviso->tentativas + 1]);

                return null;
            }
            $aviso->update(['status' => SecretarioAviso::ENVIANDO, 'atrasado' => $atrasado, 'tentativas' => $aviso->tentativas + 1]);

            return [$aviso, $item];
        });

        if (! $reserva) {
            return SecretarioAviso::whereKey($id)->value('status') === SecretarioAviso::ENTREGUE;
        }
        [$aviso, $item] = $reserva;

        $r = $this->telegram->enviarLembrete($aviso, $item);
        if ($r['ok']) {
            $aviso->update(['status' => SecretarioAviso::ENTREGUE, 'entregue_em' => now(), 'erro' => null,
                'telegram_message_id' => $r['resultado']['message_id'] ?? null]);

            return true;
        }
        // Rede fora ou 429: nada saiu, tenta de novo até a janela fechar.
        $limite = $aviso->para->copy()->addMinutes(self::JANELA_TENTATIVAS_MIN);
        $espera = self::ESPERAS[min($aviso->tentativas - 1, count(self::ESPERAS) - 1)];
        if (in_array($r['erro'], ['nao_enviada', 'limite'], true) && now()->addMinutes($espera)->lessThanOrEqualTo($limite)) {
            $aviso->update(['status' => SecretarioAviso::PENDENTE, 'erro' => $r['erro'], 'proxima_tentativa_em' => now()->addMinutes($espera)]);

            return false;
        }
        $status = in_array($r['erro'], ['incerta'], true) ? SecretarioAviso::INCERTA : SecretarioAviso::FALHOU;
        $aviso->update(['status' => $status, 'erro' => mb_substr((string) $r['erro'], 0, 40)]);
        // Sem o título: o texto do item não vai para o rastro (FR-005).
        Eventos::registrar($aviso->user_id, 'secretario_aviso_falhou', "Aviso #{$aviso->id} do item #{$item->id} não chegou ao Telegram ({$r['erro']}).",
            ['origem' => 'telegram'], 'aviso');

        return false;
    }

    /**
     * Item recorrente cuja hora passou vai para a próxima ocorrência — mesmo
     * que ninguém toque em nada (spec US4). O aviso da anterior, se ainda
     * tentando, continua.
     */
    private function avancarRecorrentes(): void
    {
        $itens = SecretarioItem::where('status', SecretarioItem::ABERTO)->whereNotNull('recorrencia')
            ->where('quando', '<=', now()->subMinute())->limit(100)->get();
        foreach ($itens as $item) {
            DB::transaction(function () use ($item) {
                $i = SecretarioItem::whereKey($item->id)->lockForUpdate()->first();
                if (! $i || $i->encerrado() || ! $i->recorrencia || $i->quando->greaterThan(now()->subMinute())) {
                    return;
                }
                $proxima = Recorrencia::proxima($i->recorrencia, now(), $i->recorrencia['hora'] ?? $i->quando->format('H:i'));
                if (! $proxima) {
                    return;
                }
                $i->update(['quando' => $proxima]);
                $this->programar($i, cancelarAnteriores: false);
            });
        }
    }
}
