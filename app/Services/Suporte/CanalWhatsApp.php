<?php

namespace App\Services\Suporte;

use App\Models\ClienteTelefone;
use App\Models\SuporteConversa;
use App\Models\SuporteMensagem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * O número de suporte na WhatsApp Cloud API oficial (docs/specs/suporte-pedro-whatsapp).
 *
 * Só a API oficial: biblioteca que imita o WhatsApp Web é o que leva o número
 * a ser banido. Assinatura e envio seguem o que o Gestor já usa em produção
 * (`WhatsAppCloudService`); o eco do que se responde pelo app do celular
 * (coexistência) vem no campo `smb_message_echoes`, como a Meta documenta.
 */
class CanalWhatsApp
{
    public function configurado(): bool
    {
        return filled(config('services.whatsapp_suporte.phone_number_id')) && filled(config('services.whatsapp_suporte.token'));
    }

    /** X-Hub-Signature-256: HMAC SHA256 do corpo cru com o app secret. Sem segredo, nada passa. */
    public function assinaturaValida(string $corpo, ?string $cabecalho): bool
    {
        $segredo = (string) config('services.whatsapp_suporte.app_secret');

        return $segredo !== '' && $cabecalho !== null && hash_equals('sha256='.hash_hmac('sha256', $corpo, $segredo), $cabecalho);
    }

    /**
     * Um evento do webhook. Só o do NOSSO número entra (o mesmo app da Meta pode
     * ter outros números). Devolve as conversas que receberam mensagem do
     * cliente — é o que o Pedro vai olhar.
     *
     * @return list<int>
     */
    public function receber(array $evento): array
    {
        $conversas = [];
        foreach ((array) ($evento['entry'] ?? []) as $entrada) {
            foreach ((array) ($entrada['changes'] ?? []) as $mudanca) {
                $valor = (array) ($mudanca['value'] ?? []);
                if ((string) data_get($valor, 'metadata.phone_number_id') !== (string) config('services.whatsapp_suporte.phone_number_id')) {
                    continue;
                }
                $nomes = collect((array) ($valor['contacts'] ?? []))->mapWithKeys(fn ($c) => [(string) ($c['wa_id'] ?? '') => data_get($c, 'profile.name')]);
                foreach ((array) ($valor['messages'] ?? []) as $m) {
                    if ($id = $this->entrada($m, $nomes[(string) ($m['from'] ?? '')] ?? null)) {
                        $conversas[] = $id;
                    }
                }
                foreach ((array) ($valor['message_echoes'] ?? []) as $m) {
                    $this->eco($m);
                }
                foreach ((array) ($valor['statuses'] ?? []) as $st) {
                    SuporteMensagem::where('wamid', (string) ($st['id'] ?? ''))->update(['status' => mb_substr((string) ($st['status'] ?? ''), 0, 12),
                        'erro' => isset($st['errors'][0]) ? mb_substr((string) (data_get($st, 'errors.0.title') ?? data_get($st, 'errors.0.message')), 0, 255) : null]);
                }
            }
        }

        return array_values(array_unique($conversas));
    }

    /**
     * Envia texto ao cliente e grava na conversa. Fora da janela de 24 h a Meta
     * só aceita modelo aprovado — e o suporte nunca inicia conversa.
     */
    public function enviar(SuporteConversa $c, string $texto, string $direcao, ?User $autor = null): SuporteMensagem
    {
        if (! $c->janelaAberta()) {
            throw ValidationException::withMessages(['texto' => 'Passaram 24 h da última mensagem do cliente: o WhatsApp só deixa responder quando ele escrever de novo.']);
        }
        if (! $this->configurado()) {
            throw ValidationException::withMessages(['texto' => 'O número de suporte ainda não está ligado à API do WhatsApp (falta a configuração no servidor).']);
        }
        $cfg = config('services.whatsapp_suporte');
        $r = Http::withToken((string) $cfg['token'])->acceptJson()->timeout(20)
            ->post('https://graph.facebook.com/'.$cfg['graph_version'].'/'.$cfg['phone_number_id'].'/messages', [
                'messaging_product' => 'whatsapp', 'to' => $c->telefone, 'type' => 'text', 'text' => ['preview_url' => false, 'body' => $texto],
            ]);
        $wamid = $r->json('messages.0.id');
        $m = $c->mensagens()->create(['wamid' => $wamid, 'direcao' => $direcao, 'tipo' => 'text', 'texto' => $texto, 'autor_id' => $autor?->id,
            'status' => $r->successful() && $wamid ? 'enviada' : 'falhou', 'erro' => $r->successful() ? null : mb_substr((string) ($r->json('error.message') ?? 'HTTP '.$r->status()), 0, 255),
            'enviada_em' => now()]);
        $c->update(['ultima_mensagem_em' => now()]);
        if (! $r->successful() || ! $wamid) {
            throw ValidationException::withMessages(['texto' => 'O WhatsApp recusou a mensagem: '.$m->erro]);
        }

        return $m;
    }

    /** A chave para achar o cliente pelo número: DDD + os 8 últimos dígitos (o 9º dígito some em alguns números do WhatsApp). */
    public static function chave(string $numero): ?string
    {
        $d = preg_replace('/\D/', '', $numero);
        if (str_starts_with($d, '55') && strlen($d) >= 12) {
            $d = substr($d, 2);
        }

        return strlen($d) >= 10 ? substr($d, 0, 2).substr($d, -8) : null;
    }

    /** O cliente dono deste número, se o cadastro tiver um (e só um). */
    public static function clienteDoNumero(string $numero): ?int
    {
        $chave = self::chave($numero);
        if (! $chave) {
            return null;
        }
        $ids = ClienteTelefone::with('cliente:id,ativo')->get()->filter(fn ($t) => self::chave((string) $t->telefone) === $chave && $t->cliente?->ativo)
            ->pluck('cliente_id')->unique()->values();

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    private function entrada(array $m, ?string $nome): ?int
    {
        $telefone = preg_replace('/\D/', '', (string) ($m['from'] ?? ''));
        if ($telefone === '' || empty($m['id'])) {
            return null;
        }

        return DB::transaction(function () use ($m, $nome, $telefone) {
            if (SuporteMensagem::where('wamid', $m['id'])->exists()) {
                return null;
            }
            $c = SuporteConversa::firstOrCreate(['telefone' => $telefone], ['cliente_id' => self::clienteDoNumero($telefone)]);
            $quando = $this->quando($m['timestamp'] ?? null);
            $c->mensagens()->create(['wamid' => $m['id'], 'direcao' => 'entrada', 'tipo' => mb_substr((string) ($m['type'] ?? 'text'), 0, 20),
                'texto' => $this->texto($m), 'enviada_em' => $quando]);
            $c->update(['nome_perfil' => $nome ? mb_substr($nome, 0, 120) : $c->nome_perfil, 'ultima_entrada_em' => $quando,
                'ultima_mensagem_em' => $quando, 'nao_lidas' => $c->nao_lidas + 1]);

            return $c->id;
        });
    }

    /** Respondido pelo app do celular (coexistência): entra na conversa e o Pedro sai dela. */
    private function eco(array $m): void
    {
        $telefone = preg_replace('/\D/', '', (string) ($m['to'] ?? ''));
        if ($telefone === '' || empty($m['id']) || SuporteMensagem::where('wamid', $m['id'])->exists()) {
            return;
        }
        $c = SuporteConversa::firstOrCreate(['telefone' => $telefone], ['cliente_id' => self::clienteDoNumero($telefone)]);
        $quando = $this->quando($m['timestamp'] ?? null);
        $c->mensagens()->create(['wamid' => $m['id'], 'direcao' => 'humano_app', 'tipo' => mb_substr((string) ($m['type'] ?? 'text'), 0, 20),
            'texto' => $this->texto($m), 'status' => 'enviada', 'enviada_em' => $quando]);
        $c->update(['situacao' => 'humano', 'humano_id' => null, 'humano_ate' => now()->addHours(SuporteConversa::HUMANO_HORAS), 'ultima_mensagem_em' => $quando]);
    }

    private function texto(array $m): string
    {
        $tipo = (string) ($m['type'] ?? 'text');

        return match ($tipo) {
            'text' => (string) data_get($m, 'text.body', ''),
            'image', 'video', 'document' => '['.['image' => 'imagem', 'video' => 'vídeo', 'document' => 'documento'][$tipo].']'.(data_get($m, $tipo.'.caption') ? ' '.data_get($m, $tipo.'.caption') : ''),
            'audio' => '[áudio]',
            'button' => (string) data_get($m, 'button.text', '[botão]'),
            'interactive' => (string) (data_get($m, 'interactive.button_reply.title') ?? data_get($m, 'interactive.list_reply.title') ?? '[resposta]'),
            default => '['.$tipo.']',
        };
    }

    private function quando(mixed $timestamp): Carbon
    {
        return is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp)->setTimezone(config('app.timezone')) : now();
    }
}
