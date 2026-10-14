<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaTelegramBot;
use App\Models\CentralIaTelegramVinculo;
use App\Models\User;
use App\Services\CentralIa\TelegramCanal;
use Illuminate\Support\Facades\DB;

/**
 * Cada pessoa fala com a Central pelo PRÓPRIO bot (T-374). Nos testes do
 * canal, quem tem o chat vinculado ganha um bot sozinho, e o update chega ao
 * bot de quem ele é — o que o processo do bot faz de verdade.
 */
trait BotProprioNosTestes
{
    protected function setUpBotProprioNosTestes(): void
    {
        CentralIaTelegramVinculo::created(fn (CentralIaTelegramVinculo $v) => $this->botNoTeste($v->user_id));
    }

    protected function botNoTeste(User|int $user): CentralIaTelegramBot
    {
        $id = $user instanceof User ? $user->id : $user;

        return CentralIaTelegramBot::firstOrCreate(['user_id' => $id], [
            'telegram_bot_id' => 900000 + $id, 'usuario' => 'bot_de_teste_'.$id.'_bot',
            'token' => (900000 + $id).':'.str_repeat('T', 35), 'servidor' => CentralIaTelegramBot::servidorAtual(),
        ]);
    }

    /** Entrega o update ao bot do dono do chat (ou de quem gerou o código do /start). */
    protected function entregarAoBot(TelegramCanal $canal, array $update): void
    {
        $canal->receber($update, $this->botDoUpdate($update));
    }

    protected function botDoUpdate(array $update): CentralIaTelegramBot
    {
        $texto = $update['message']['text'] ?? '';
        if (str_starts_with($texto, '/start ')) {
            $dono = DB::table('central_ia_telegram_codigos')->where('codigo_hash', hash('sha256', trim(substr($texto, 7))))->value('user_id');
            if ($dono) {
                return $this->botNoTeste($dono);
            }
        }
        $dono = CentralIaTelegramVinculo::where('chat_id', $update['message']['chat']['id'] ?? 0)->value('user_id');

        return $dono ? $this->botNoTeste($dono) : (CentralIaTelegramBot::first() ?? $this->botNoTeste(User::factory()->create()));
    }
}
