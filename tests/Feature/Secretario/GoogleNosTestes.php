<?php

namespace Tests\Feature\Secretario;

use App\Models\SecretarioGoogleAgenda;
use App\Models\SecretarioGoogleConta;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** O Google simulado: token, agendas e eventos — e o registro do que o Matriz mandou. */
trait GoogleNosTestes
{
    protected array $eventosGoogle = [];
    protected array $criados = [];
    protected bool $insertFalha = false;

    protected function configurarGoogle(): void
    {
        config(['services.google_secretario.client_id' => 'cliente-teste', 'services.google_secretario.client_secret' => 'segredo-teste']);
    }

    protected function fakeGoogle(array $extra = []): void
    {
        Http::fake($extra + [
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.ACESSO-SECRETO', 'refresh_token' => '1//RENOVA-SECRETO', 'expires_in' => 3600, 'scope' => 'calendar']),
            'oauth2.googleapis.com/revoke' => Http::response([], 200),
            'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response(['items' => [
                ['id' => 'felipe@gmail.com', 'summary' => 'Financeiro Grupo', 'primary' => true],
                ['id' => 'vixcard@group.calendar.google.com', 'summary' => 'VixCard'],
            ]]),
            'www.googleapis.com/calendar/v3/calendars/*' => function (Request $r) {
                if ($r->method() === 'POST') {
                    if ($this->insertFalha) {
                        return Http::response(['error' => ['code' => 500]], 500);
                    }
                    $this->criados[] = $r->data();

                    return Http::response(['id' => 'evt-novo-'.count($this->criados), 'htmlLink' => 'https://www.google.com/calendar/event?eid=x']);
                }
                $agenda = urldecode(explode('/', explode('/calendars/', $r->url())[1])[0]);

                return Http::response(['items' => $this->eventosGoogle[$agenda] ?? []]);
            },
            'api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 4242]]),
            'api.telegram.org/*/answerCallbackQuery' => Http::response(['ok' => true, 'result' => true]),
            'api.telegram.org/*/editMessageText' => Http::response(['ok' => true, 'result' => ['message_id' => 4242]]),
        ]);
    }

    protected function conectarGoogle(User $u, bool $sincronizar = true): SecretarioGoogleConta
    {
        $conta = SecretarioGoogleConta::create(['user_id' => $u->id, 'access_token' => 'ya29.ACESSO-SECRETO', 'refresh_token' => '1//RENOVA-SECRETO',
            'expira_em' => now()->addHour(), 'conta' => 'felipe@gmail.com']);
        if ($sincronizar) {
            app(\App\Services\Secretario\SincronizadorGoogle::class)->sincronizar($conta);
        }

        return $conta->fresh();
    }

    protected function evento(string $agenda, string $id, string $titulo, string $inicio, string $fim, bool $diaInteiro = false): void
    {
        $this->eventosGoogle[$agenda][] = ['id' => $id, 'status' => 'confirmed', 'summary' => $titulo, 'htmlLink' => 'https://www.google.com/calendar/event?eid='.$id,
            'start' => $diaInteiro ? ['date' => $inicio] : ['dateTime' => $inicio], 'end' => $diaInteiro ? ['date' => $fim] : ['dateTime' => $fim],
            'description' => 'DESCRICAO-PRIVADA', 'attendees' => [['email' => 'terceiro@x.com']]];
    }

    protected function agendaVix(User $u): SecretarioGoogleAgenda
    {
        return SecretarioGoogleAgenda::where('user_id', $u->id)->where('nome', 'VixCard')->firstOrFail();
    }
}
