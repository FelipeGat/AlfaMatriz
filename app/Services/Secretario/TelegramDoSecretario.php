<?php

namespace App\Services\Secretario;

use App\Models\CentralIaTelegramBot;
use App\Models\CentralIaTelegramVinculo;
use App\Models\SecretarioAviso;
use App\Models\SecretarioItem;
use App\Models\SecretarioProposta;
use App\Models\SecretarioToque;
use App\Models\User;
use App\Services\CentralIa\Eventos;
use App\Services\CentralIa\ProtecaoDeSegredos;
use App\Services\CentralIa\TelegramApi;
use App\Services\CentralIa\TelegramCanal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * O Secretário no Telegram da pessoa: o MESMO bot da Central (dono,
 * 17/10/2026: nada de bot novo). Manda proposta e lembrete com botões e trata
 * o toque neles (contracts/telegram.md).
 *
 * Mandar não é consumir: a regra de um leitor só por bot é do `getUpdates`,
 * que continua só no `central-ia:telegram`.
 */
class TelegramDoSecretario
{
    /** Ações aceitas no `callback_data` — lista fechada, nunca texto livre. */
    public const ACOES_PROPOSTA = ['pc', 'pd'];
    public const ACOES_AVISO = ['c', 'a15', 'a60', 'am', 'r', 'x', 'xs', 'xt'];

    public function __construct(private TelegramApi $api, private TelegramCanal $canal) {}

    /** A porta do bot da pessoa e o chat dela, ou null se não tem os dois. */
    public function destino(User|int $user): ?array
    {
        $id = $user instanceof User ? $user->id : $user;
        $vinculo = CentralIaTelegramVinculo::where('user_id', $id)->first();
        $bot = $vinculo ? $this->canal->botDe($id) : null;
        if (! $bot || $bot->situacao === CentralIaTelegramBot::TOKEN_INVALIDO) {
            return null;
        }

        return [$this->api->doBot($bot), (int) $vinculo->chat_id];
    }

    public function enviarProposta(SecretarioProposta $p, string $texto): ?int
    {
        if (! ($d = $this->destino($p->user_id))) {
            return null;
        }
        [$api, $chat] = $d;
        $teclado = $p->falta ? null : ['inline_keyboard' => [[
            ['text' => '✓ Confirmar', 'callback_data' => "s:pc:{$p->id}"],
            ['text' => '✎ Corrigir', 'callback_data' => "s:pd:{$p->id}"],
        ]]];
        $r = $api->chamar('sendMessage', array_filter([
            'chat_id' => $chat, 'text' => ProtecaoDeSegredos::texto($texto),
            'link_preview_options' => ['is_disabled' => true], 'reply_markup' => $teclado,
        ]));

        return $r['ok'] ? ($r['resultado']['message_id'] ?? null) : null;
    }

    /** Resposta simples no chat (sem teclado), para o que não é proposta. */
    public function responder(User|int $user, string $texto): void
    {
        if ($d = $this->destino($user)) {
            $d[0]->chamar('sendMessage', ['chat_id' => $d[1], 'text' => ProtecaoDeSegredos::texto($texto), 'link_preview_options' => ['is_disabled' => true]]);
        }
    }

    /** @return array{ok: bool, erro?: string, resultado?: mixed} o retorno da TelegramApi */
    public function enviarLembrete(SecretarioAviso $aviso, SecretarioItem $item): array
    {
        if (! ($d = $this->destino($aviso->user_id))) {
            return ['ok' => false, 'erro' => 'sem_bot'];
        }
        [$api, $chat] = $d;

        return $api->chamar('sendMessage', [
            'chat_id' => $chat,
            'text' => $this->textoDoLembrete($aviso, $item),
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => $this->tecladoDoLembrete($aviso, (bool) $item->recorrencia, false),
        ]);
    }

    public function textoDoLembrete(SecretarioAviso $aviso, SecretarioItem $item): string
    {
        $icone = $item->tipo === 'compromisso' ? '📅' : '⏰';
        $linha = $item->quando ? Datas::porExtenso($item->quando, $item->so_data) : '';
        if ($item->tipo === 'compromisso' && $item->quando && $aviso->para->lessThan($item->quando)) {
            $linha = 'Começa '.$linha;
        }
        if ($aviso->atrasado) {
            $linha .= ' (atrasado, era '.$aviso->para->format('H:i').')';
        }

        return ProtecaoDeSegredos::texto("{$icone} {$item->titulo}".($linha ? "\n{$linha}" : ''));
    }

    public function tecladoDoLembrete(SecretarioAviso $aviso, bool $recorrente, bool $cancelando): array
    {
        $b = fn (string $texto, string $acao) => ['text' => $texto, 'callback_data' => "s:{$acao}:{$aviso->id}"];
        if ($cancelando) {
            return ['inline_keyboard' => [[$b('Só esta', 'xs'), $b('Todas as próximas', 'xt')]]];
        }

        return ['inline_keyboard' => [
            [$b('✓ Concluir', 'c'), $b('+15 min', 'a15'), $b('+1 h', 'a60')],
            [$b('Amanhã', 'am'), $b('Reagendar', 'r'), $b($recorrente ? 'Cancelar…' : 'Cancelar', 'x')],
        ]];
    }

    /**
     * Um toque num botão. As regras de contracts/telegram.md, NA ORDEM;
     * qualquer uma que falhe responde genérico e não altera nada.
     */
    public function toque(array $cq, CentralIaTelegramBot $bot): void
    {
        $api = $this->api->doBot($bot);
        $id = $cq['id'] ?? null;
        $responder = fn (string $texto) => is_string($id) ? $api->chamar('answerCallbackQuery', ['callback_query_id' => $id, 'text' => $texto]) : null;
        $recusar = function (string $motivo) use ($bot, $responder) {
            Eventos::registrar($bot->user_id, 'secretario_toque_recusado', "Toque recusado no Telegram ({$motivo}).", ['origem' => 'telegram'], 'aviso');
            $responder('Ação indisponível.');
        };

        $de = $cq['from']['id'] ?? null;
        $msg = $cq['message'] ?? null;
        $chat = $msg['chat']['id'] ?? null;
        if (! is_string($id) || ! is_int($de) || ($msg['chat']['type'] ?? null) !== 'private' || $chat !== $de || ($cq['from']['is_bot'] ?? true)) {
            $recusar('origem');

            return;
        }
        $vinculo = CentralIaTelegramVinculo::where('chat_id', $de)->where('user_id', $bot->user_id)->first();
        $user = $vinculo ? User::find($vinculo->user_id) : null;
        if (! $user || ! $user->ativo || $user->temEscopoDeRevenda() || ! $user->canPermissao('secretario', 'editar')) {
            $recusar('vinculo');

            return;
        }
        if (RateLimiter::tooManyAttempts('central-ia:telegram:'.$de, 30)) {
            $responder('Muitos toques seguidos. Espere um pouco.');

            return;
        }
        RateLimiter::hit('central-ia:telegram:'.$de, 60);

        try {
            SecretarioToque::create(['callback_id' => mb_substr($id, 0, 64), 'user_id' => $user->id]);
        } catch (UniqueConstraintViolationException) {
            $responder('');

            return;
        }

        if (! preg_match('/^s:([a-z0-9]{1,4}):(\d{1,18})$/', (string) ($cq['data'] ?? ''), $m)) {
            $recusar('dados');

            return;
        }
        [, $acao, $alvo] = $m;
        $mensagemId = $msg['message_id'] ?? null;
        $editar = fn (string $texto, ?array $teclado = null) => $mensagemId ? $api->chamar('editMessageText', array_filter([
            'chat_id' => $de, 'message_id' => $mensagemId, 'text' => ProtecaoDeSegredos::texto($texto),
            'link_preview_options' => ['is_disabled' => true], 'reply_markup' => $teclado,
        ])) : null;

        // Etapa 7: "Adiar estes até amanhã" no resumo de acompanhamentos.
        if ($acao === 'ac') {
            $envio = \App\Models\SecretarioAcompanhamentoEnvio::de($user)->find((int) $alvo);
            if (! $envio) {
                $recusar('alvo');

                return;
            }
            $ate = \Carbon\CarbonImmutable::now()->addDay();
            $n = app(\App\Services\Secretario\Acompanhamento\Envio::class)->adiar($user, (array) $envio->acompanhamentos, $ate);
            $responder($n ? 'Adiado.' : 'Nada a adiar.');
            $editar('⏸ '.$n.' acompanhamento(s) adiado(s) até amanhã às '.$ate->format('H:i').".\nVer todos: ".route('secretario.acompanhamentos'));

            return;
        }

        if (in_array($acao, self::ACOES_PROPOSTA, true)) {
            $proposta = SecretarioProposta::de($user)->find((int) $alvo);
            if (! $proposta) {
                $recusar('alvo');

                return;
            }
            $propostas = app(Propostas::class);
            if ($acao === 'pc') {
                $r = $propostas->confirmar($user, $proposta);
                $responder($r['item'] ? 'Registrado.' : $r['mensagem']);
                $editar($r['mensagem']);
            } else {
                $propostas->descartar($user, $proposta, pedirCorrecao: true);
                $responder('Ok.');
                $editar('✎ Diga como deve ficar (ex.: "amanhã às 10h de ligar para o Alexandre").');
            }

            return;
        }

        if (! in_array($acao, self::ACOES_AVISO, true)) {
            $recusar('acao');

            return;
        }
        $aviso = SecretarioAviso::where('user_id', $user->id)->find((int) $alvo);
        $item = $aviso ? \App\Models\SecretarioItem::de($user)->find($aviso->item_id) : null;
        if (! $item) {
            $recusar('alvo');

            return;
        }
        if ($acao === 'x' && $item->recorrencia && ! $item->encerrado()) {
            $responder('');
            $editar($this->textoDoLembrete($aviso, $item)."\n\nCancelar só esta ocorrência ou todas as próximas?", $this->tecladoDoLembrete($aviso, true, true));

            return;
        }
        if ($acao === 'r') {
            app(Propostas::class)->pedirNovoQuando($user, $item, 'telegram');
            $responder('');
            $editar($this->textoDoLembrete($aviso, $item)."\n\n🗓 Para quando? Responda aqui (ex.: \"quinta às 15h\").");

            return;
        }

        $nome = ['c' => 'concluir', 'a15' => 'adiar_15', 'a60' => 'adiar_60', 'am' => 'amanha', 'x' => 'cancelar', 'xs' => 'cancelar_esta', 'xt' => 'cancelar_todas'][$acao];
        $r = app(Itens::class)->agir($user, $item, $nome, $aviso);
        $responder($r['curto']);
        $editar(($item->tipo === 'compromisso' ? '📅 ' : '⏰ ').$item->titulo."\n".$r['mensagem']);
    }
}
