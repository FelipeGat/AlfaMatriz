<?php

namespace App\Services\CentralIa;

use App\Models\CentralIaEntrega;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaTelegramVinculo;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * O Telegram como segundo canal da conversa: parear, receber e entregar.
 *
 * Quem fala pelo Telegram passa pelas MESMAS portas da tela, relidas a cada
 * mensagem: conta ativa e permissão `central_ia`. Perdeu o perfil, o chat
 * pareado para de falar com a Central na hora — o vínculo não é permissão.
 */
class TelegramCanal
{
    public function __construct(
        private readonly TelegramApi $api,
        private readonly ConversaService $conversas,
        private readonly AgentesService $agentes,
    ) {}

    public function vinculoDe(User $user): ?CentralIaTelegramVinculo
    {
        return CentralIaTelegramVinculo::where('user_id', $user->id)->first();
    }

    /**
     * Um código novo invalida o anterior. Só o hash vai para o banco: o
     * código aparece uma vez, na tela de quem pediu.
     */
    public function gerarCodigo(User $user): string
    {
        $codigo = bin2hex(random_bytes(16));

        DB::table('central_ia_telegram_codigos')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'codigo_hash' => hash('sha256', $codigo),
                'expira_em' => now()->addMinutes(config('central_ia.telegram.codigo_minutos')),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return $codigo;
    }

    public function desvincular(User $user): void
    {
        DB::transaction(function () use ($user) {
            CentralIaTelegramVinculo::where('user_id', $user->id)->delete();
            // O que ainda ia para aquele chat não vai mais.
            CentralIaEntrega::where('user_id', $user->id)->where('status', CentralIaEntrega::PENDENTE)
                ->update(['status' => CentralIaEntrega::FALHOU, 'erro' => 'desvinculado']);
        });
    }

    /**
     * Um update do getUpdates. Nada do payload bruto é guardado.
     */
    public function receber(array $update): void
    {
        $updateId = $update['update_id'] ?? null;
        $m = $update['message'] ?? null;

        // Só conversa privada, de gente, em texto. Em grupo o `from` não é o
        // dono do chat, e um vínculo por chat deixaria qualquer membro do
        // grupo escrever na conversa de quem pareou.
        if (! is_int($updateId) || ! is_array($m) || ($m['chat']['type'] ?? null) !== 'private'
            || ($m['from']['is_bot'] ?? true) || ! isset($m['from']['id'], $m['chat']['id'])
            || $m['from']['id'] !== $m['chat']['id'] || ! is_string($m['text'] ?? null)) {
            return;
        }

        $chatId = (int) $m['chat']['id'];
        $texto = $m['text'];

        if (RateLimiter::tooManyAttempts('central-ia:telegram:'.$chatId, 30)) {
            return;
        }
        RateLimiter::hit('central-ia:telegram:'.$chatId, 60);

        if (str_starts_with($texto, '/start')) {
            // O código nunca vira texto da conversa.
            $this->parear($chatId, trim(substr($texto, 6)), $m['from']['username'] ?? null);

            return;
        }

        $vinculo = CentralIaTelegramVinculo::where('chat_id', $chatId)->first();

        if (! $vinculo) {
            $this->responder($chatId, 'Este chat não está ligado a nenhuma conta. Gere o código na Central de IA do AlfaMatriz e toque no link.');

            return;
        }

        $user = User::find($vinculo->user_id);

        if (! $this->podeEscrever($user)) {
            $this->responder($chatId, 'Sua conta não tem acesso à Central de IA agora.');

            return;
        }

        if (trim($texto) === '' || mb_strlen($texto) > config('central_ia.max_texto')) {
            $this->responder($chatId, 'Mensagem vazia ou longa demais (até '.config('central_ia.max_texto').' caracteres).');

            return;
        }

        try {
            if ($pedido = $this->agentes->interpretar($texto)) {
                $this->agentes->pedir($user, $texto, $pedido, CentralIaMensagem::ORIGEM_TELEGRAM, telegramUpdateId: $updateId);
            } else {
                $this->conversas->registrarDoUsuario($user, $texto, CentralIaMensagem::ORIGEM_TELEGRAM, telegramUpdateId: $updateId);
            }
        } catch (ValidationException $e) {
            // Pedido recusado (projeto que a máquina não tem, limite do dia):
            // a resposta vai só para o chat, e o pedido não entra na conversa.
            $this->responder($chatId, collect($e->errors())->flatten()->first());
        } catch (UniqueConstraintViolationException) {
            // Reentrega de um update que já entrou: o cursor se perdeu, a
            // mensagem não.
        }
    }

    private function parear(int $chatId, string $codigo, ?string $usuarioTelegram): void
    {
        $resposta = DB::transaction(function () use ($chatId, $codigo, $usuarioTelegram) {
            $registro = preg_match('/^[a-f0-9]{32}$/D', $codigo)
                ? DB::table('central_ia_telegram_codigos')->where('codigo_hash', hash('sha256', $codigo))->lockForUpdate()->first()
                : null;

            if (! $registro || now()->greaterThan($registro->expira_em)) {
                return 'Código inválido ou vencido. Gere outro na Central de IA do AlfaMatriz.';
            }

            DB::table('central_ia_telegram_codigos')->where('id', $registro->id)->delete();
            $user = User::find($registro->user_id);

            if (! $this->podeEscrever($user)) {
                return 'Sua conta não tem acesso à Central de IA agora.';
            }

            $outro = CentralIaTelegramVinculo::where('chat_id', $chatId)->where('user_id', '!=', $user->id)->exists();

            if ($outro) {
                return 'Este Telegram já está ligado a outra conta do AlfaMatriz. Desvincule por lá antes.';
            }

            CentralIaTelegramVinculo::updateOrCreate(
                ['user_id' => $user->id],
                ['chat_id' => $chatId, 'telegram_usuario' => $usuarioTelegram],
            );

            return "Pronto, {$user->name}: este chat agora é a sua conversa da Central de IA.";
        });

        $this->responder($chatId, $resposta);
    }

    /**
     * Leva ao Telegram as entregas pendentes, mais antigas primeiro.
     */
    public function entregarPendentes(int $limite = 20): void
    {
        // Presa em `enviando` é processo que morreu no meio da chamada: pode
        // ter chegado. Vira incerta, nunca pendente de novo.
        CentralIaEntrega::where('status', CentralIaEntrega::ENVIANDO)->where('updated_at', '<', now()->subMinutes(2))
            ->update(['status' => CentralIaEntrega::INCERTA, 'erro' => 'interrompida']);

        $ids = CentralIaEntrega::where('status', CentralIaEntrega::PENDENTE)->orderBy('id')->limit($limite)->pluck('id');
        // Prazo por rodada: o ciclo do bot precisa caber na trava dele. O que
        // sobrar sai na próxima volta, segundos depois.
        $ate = microtime(true) + 20;

        foreach ($ids as $id) {
            if (! $this->entregar($id) || microtime(true) > $ate) {
                return;
            }
        }
    }

    /**
     * @return bool falso quando o Telegram pediu para esperar — a fila para.
     */
    private function entregar(int $id): bool
    {
        $entrega = DB::transaction(function () use ($id) {
            $entrega = CentralIaEntrega::whereKey($id)->lockForUpdate()->first();

            if (! $entrega || $entrega->status !== CentralIaEntrega::PENDENTE) {
                return null;
            }

            $vinculo = CentralIaTelegramVinculo::where('user_id', $entrega->user_id)->first();

            if (! $vinculo || $vinculo->chat_id !== $entrega->chat_id || ! $this->podeLer(User::find($entrega->user_id))) {
                $entrega->update(['status' => CentralIaEntrega::FALHOU, 'erro' => 'sem_acesso']);

                return null;
            }

            $entrega->update(['status' => CentralIaEntrega::ENVIANDO, 'tentativas' => $entrega->tentativas + 1]);

            return $entrega;
        });

        if (! $entrega) {
            return true;
        }

        $mensagem = $entrega->mensagem;
        $texto = $mensagem->conteudo;

        // O Telegram recusa acima de 4096. Resposta de agente passa disso
        // fácil; no celular vai o começo, e a íntegra fica na tela.
        if (mb_strlen($texto) > 3800) {
            $texto = mb_substr($texto, 0, 3800)."\n\n… (continua na Central de IA do AlfaMatriz)";
        }

        $resultado = $this->api->chamar('sendMessage', [
            'chat_id' => $entrega->chat_id,
            'text' => $this->cabecalho($mensagem)."\n".$texto,
        ]);

        if ($resultado['ok']) {
            $entrega->update(['status' => CentralIaEntrega::ENTREGUE, 'telegram_message_id' => $resultado['resultado']['message_id'] ?? null, 'erro' => null]);

            return true;
        }

        if ($resultado['erro'] === 'limite') {
            // 429 é "não entreguei": volta para a fila sem contar como incerta.
            $entrega->update(['status' => CentralIaEntrega::PENDENTE, 'erro' => 'limite']);

            return false;
        }

        $entrega->update([
            'status' => $resultado['erro'] === 'recusada' ? CentralIaEntrega::FALHOU : CentralIaEntrega::INCERTA,
            'erro' => $resultado['erro'],
        ]);

        return true;
    }

    private function cabecalho(CentralIaMensagem $m): string
    {
        return match ($m->autor_tipo) {
            CentralIaMensagem::AUTOR_USUARIO => '🖥 Você, pelo AlfaMatriz:',
            CentralIaMensagem::AUTOR_AGENTE => '🤖 '.($m->autor_nome ?: 'Agente').':',
            default => 'ℹ️ Central:',
        };
    }

    private function responder(int $chatId, string $texto): void
    {
        // Aviso do próprio bot, fora da conversa. Perdido, a pessoa tenta de
        // novo; por isso não vira entrega rastreada.
        $this->api->chamar('sendMessage', ['chat_id' => $chatId, 'text' => $texto]);
    }

    private function podeEscrever(?User $user): bool
    {
        return $this->podeLer($user) && $user->canPermissao('central_ia', 'incluir');
    }

    private function podeLer(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $user->esquecerPermissoes();

        return $user->ativo && ! $user->primeiro_acesso && $user->revenda_id === null
            && $user->canPermissao('central_ia', 'ler');
    }
}
