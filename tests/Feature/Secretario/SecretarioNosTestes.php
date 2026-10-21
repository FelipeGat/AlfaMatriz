<?php

namespace Tests\Feature\Secretario;

use App\Models\CentralIaTelegramVinculo;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Feature\CentralIa\BotProprioNosTestes;

trait SecretarioNosTestes
{
    use BotProprioNosTestes;

    protected const CHAT = 5550777;

    protected function dono(bool $telegram = true, int $chat = self::CHAT): User
    {
        $u = User::factory()->create();
        $u->perfis()->syncWithoutDetaching(Perfil::whereIn('slug', ['central_ia', 'secretario'])->pluck('id'));
        if ($telegram) {
            CentralIaTelegramVinculo::create(['user_id' => $u->id, 'chat_id' => $chat]);
        }

        return $u;
    }

    protected function admin(): User
    {
        $u = User::factory()->create();
        $u->perfis()->syncWithoutDetaching([Perfil::where('slug', 'admin')->value('id')]);

        return $u;
    }

    protected function fakeTelegram(array $extra = []): void
    {
        Http::fake($extra + [
            'api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 4242]]),
            'api.telegram.org/*/answerCallbackQuery' => Http::response(['ok' => true, 'result' => true]),
            'api.telegram.org/*/editMessageText' => Http::response(['ok' => true, 'result' => ['message_id' => 4242]]),
        ]);
    }

    /** O que saiu para o Telegram, por método. */
    protected function enviados(string $metodo = 'sendMessage'): array
    {
        return collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/'.$metodo))->map(fn ($p) => $p[0]->data())->values()->all();
    }

    protected function mensagem(int $id, string $texto, int $chat = self::CHAT, ?int $de = null): array
    {
        return ['update_id' => $id, 'message' => ['message_id' => $id, 'text' => $texto,
            'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $de ?? $chat, 'is_bot' => false]]];
    }

    protected function toque(string $id, string $data, int $chat = self::CHAT, ?int $de = null, string $tipo = 'private'): array
    {
        return ['update_id' => random_int(1, 9_000_000), 'callback_query' => ['id' => $id, 'data' => $data,
            'from' => ['id' => $de ?? $chat, 'is_bot' => false],
            'message' => ['message_id' => 4242, 'chat' => ['id' => $chat, 'type' => $tipo]]]];
    }
}
