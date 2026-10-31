<?php

namespace App\Services\Suporte;

use App\Models\ClienteTelefone;
use App\Models\SuporteConversa;
use App\Models\SuporteMensagem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        return filled(config('services.whatsapp_suporte.phone_number_id'))
            && (filled(config('services.whatsapp_suporte.token')) || $this->viaGestor());
    }

    /**
     * O número é do Gestor (4042-4157; Gestor: docs/specs/whatsapp-pedro): ele
     * repassa os eventos e envia por nós. O token da Meta fica só lá.
     */
    public function viaGestor(): bool
    {
        return filled(config('services.suporte_gestor.url')) && filled(config('services.suporte_gestor.token'));
    }

    /** O repasse veio mesmo do Gestor: o segredo da integração, em tempo constante. */
    public function doGestor(?string $token): bool
    {
        $esperado = (string) config('services.suporte_gestor.token');

        return $esperado !== '' && filled($token) && hash_equals($esperado, (string) $token);
    }

    /**
     * Pede ao Gestor (entregar a um setor, marcar suporte). Devolve o JSON da
     * resposta; falha vira ValidationException com o motivo do contrato.
     */
    public function pedirAoGestor(SuporteConversa $c, string $acao, array $dados = []): array
    {
        if (! $this->viaGestor() || ! $c->gestor_conversa_id) {
            throw ValidationException::withMessages(['gestor' => 'Esta conversa não veio pelo Gestor.']);
        }
        $r = Http::withToken((string) config('services.suporte_gestor.token'))->acceptJson()->timeout(20)
            ->post(rtrim((string) config('services.suporte_gestor.url'), '/')."/api/integracao/matriz/whatsapp/conversas/{$c->gestor_conversa_id}/{$acao}", $dados);
        if (! $r->successful()) {
            throw ValidationException::withMessages(['gestor' => self::motivoDoGestor((string) ($r->json('motivo') ?? 'HTTP '.$r->status()))]);
        }

        return (array) $r->json();
    }

    /**
     * As chaves dos números da casa (acesso-interno.md, FR-A11): o Gestor decide entre o
     * Pedro e o menu ANTES de o repasse chegar aqui, então precisa saber quem é da casa
     * para não mandar o menu a ela fora do horário. Só a chave sai — nome e perfil, não.
     * Falha não derruba quem chamou: devolve false, e a rotina de 15 minutos tenta de novo.
     *
     * @param  list<string>  $chaves
     */
    public function numerosInternosAoGestor(array $chaves): bool
    {
        try {
            $r = Http::withToken((string) config('services.suporte_gestor.token'))->acceptJson()->timeout(20)
                ->post(rtrim((string) config('services.suporte_gestor.url'), '/').'/api/integracao/matriz/whatsapp/internos', ['chaves' => array_values($chaves)]);
        } catch (\Throwable $e) {
            Log::warning('[Pedro] a lista da casa não chegou ao Gestor', ['erro' => $e::class]);

            return false;
        }
        if (! $r->successful()) {
            Log::warning('[Pedro] o Gestor recusou a lista da casa', ['status' => $r->status(), 'motivo' => $r->json('motivo')]);
        }

        return $r->successful();
    }

    /** O motivo do contrato, em português para a tela. */
    public static function motivoDoGestor(string $motivo): string
    {
        return [
            'tempo_limite_passou' => 'O Pedro demorou e o Gestor já mandou o menu ao cliente.',
            'conversa_com_setor' => 'A conversa já está com um setor no Gestor (comercial ou financeiro).',
            'janela_fechada' => 'Passaram 24 h da última mensagem do cliente: o WhatsApp só deixa responder quando ele escrever de novo.',
            'setor_desligado' => 'Esse setor está desligado na configuração do WhatsApp do Gestor.',
            'conversa_inexistente' => 'O Gestor não achou a conversa (encerrada?).',
            'credencial' => 'O Gestor recusou a credencial da integração.',
            'meta_recusou' => 'O WhatsApp recusou a mensagem.',
            'resposta_superada' => 'O cliente escreveu de novo antes de a resposta sair; o Pedro responde à mensagem nova.',
            'em_andamento' => 'Essa mensagem já está sendo enviada.',
        ][$motivo] ?? 'O Gestor recusou: '.$motivo;
    }

    /**
     * O estado da conversa que o Gestor manda junto do repasse: a conversa de lá,
     * o setor e se é o Matriz quem responde. Comercial e financeiro ficam com os
     * atendentes do Gestor — o Pedro não fala nelas.
     */
    public function aplicarEstadoDoGestor(int $conversaId, array $estados): bool
    {
        $c = SuporteConversa::find($conversaId);
        $estado = $c ? ($estados[$c->telefone] ?? null) : null;
        if (! is_array($estado) || ! isset($estado['conversa_id'])) {
            return false;
        }
        $comPedro = (bool) ($estado['com_pedro'] ?? false);
        $c->update(['gestor_conversa_id' => (int) $estado['conversa_id'], 'gestor_setor' => mb_substr((string) ($estado['setor'] ?? ''), 0, 20) ?: null]
            + ($comPedro ? ($c->situacao === 'gestor' ? ['situacao' => 'pedro'] : []) : ['situacao' => 'gestor']));

        return $comPedro;
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
    public function enviar(SuporteConversa $c, string $texto, string $direcao, ?User $autor = null, ?string $emRespostaA = null): SuporteMensagem
    {
        if (! $c->janelaAberta()) {
            throw ValidationException::withMessages(['texto' => 'Passaram 24 h da última mensagem do cliente: o WhatsApp só deixa responder quando ele escrever de novo.']);
        }
        if (! $this->configurado()) {
            throw ValidationException::withMessages(['texto' => 'O número de suporte ainda não está ligado à API do WhatsApp (falta a configuração no servidor).']);
        }
        if ($this->viaGestor()) {
            return $this->enviarPeloGestor($c, $texto, $direcao, $autor, $emRespostaA);
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

    /** O Gestor envia e registra na conversa de lá; aqui fica a cópia com o wamid. */
    private function enviarPeloGestor(SuporteConversa $c, string $texto, string $direcao, ?User $autor, ?string $emRespostaA): SuporteMensagem
    {
        $chave = 'mx-'.\Illuminate\Support\Str::uuid();
        $nome = $direcao === 'pedro' ? 'Pedro (assistente)' : ($autor?->name ?: 'Suporte');
        try {
            // A resposta do Pedro diz a que mensagem responde: se o cliente já escreveu
            // de novo, o Gestor recusa ("resposta_superada") e o Pedro responde à nova.
            $r = $this->pedirAoGestor($c, 'enviar', array_filter(['texto' => $texto, 'autor' => mb_substr($nome, 0, 120), 'chave' => $chave, 'em_resposta_a' => $emRespostaA])
                // Gente da equipe (e não o Pedro): só ela conta como "primeira resposta" no Gestor.
                + ['humano' => $direcao !== 'pedro']);
            $erro = null;
        } catch (ValidationException $e) {
            $r = [];
            $erro = (string) collect($e->errors())->flatten()->first();
            // O Gestor já mandou o menu (o Pedro demorou): a conversa deixa de ser do Pedro.
            if ($erro === self::motivoDoGestor('tempo_limite_passou') || $erro === self::motivoDoGestor('conversa_com_setor')) {
                $c->update(['situacao' => 'gestor']);
            }
        }
        $m = $c->mensagens()->create(['wamid' => $r['wamid'] ?? null, 'direcao' => $direcao, 'tipo' => 'text', 'texto' => $texto, 'autor_id' => $autor?->id,
            'status' => $erro ? 'falhou' : 'enviada', 'erro' => $erro ? mb_substr($erro, 0, 255) : null, 'enviada_em' => now()]);
        $c->update(['ultima_mensagem_em' => now()]);
        if ($erro) {
            throw ValidationException::withMessages(['texto' => $erro]);
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
