<?php

namespace App\Services\Suporte;

use App\Models\Notificacao;
use App\Models\SuporteConversa;
use App\Models\User;
use App\Services\AvisoNoTelegram;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A conversa que sai do Pedro para uma pessoa (Felipe, 21/10/2026: "os atendimentos não
 * podem ficar agarrados sempre com o Pedro; quando ele não conseguir resolver algo ou o
 * cliente informar que deseja falar com um atendente específico, ele tem que direcionar
 * para uma fila ou comunicar o usuário").
 *
 * A fila é a tela Suporte › Conversas: situação "humano" sem atendente. Com um nome que
 * bate com alguém da equipe, a conversa já nasce com essa pessoa. Quem atende é avisado
 * no sino e no Telegram, e a fila parada é lembrada (lembrarFila) até alguém responder.
 */
class Transferencia
{
    /** Sem resposta de uma pessoa nesse tempo, a equipe é lembrada de novo. */
    public const LEMBRAR_MINUTOS = 15;

    /** O que se acrescenta fora do horário, onde quer que a conversa passe a uma pessoa. */
    public const FORA_DO_HORARIO = ' Como já passou do nosso horário (das 7h às 18h, de segunda a sexta), o retorno é no próximo horário de atendimento.';

    public function __construct(private AvisoNoTelegram $telegram) {}

    /** Quem atende o suporte: contas ativas com a permissão de responder conversas. @return Collection<int, User> */
    public function atendentes(): Collection
    {
        return User::where('ativo', true)->whereNotIn('email', [User::EMAIL_DO_PEDRO, User::EMAIL_DO_VIGIA])->orderBy('name')->get()
            ->filter(fn (User $u) => $u->canPermissao('suporte', 'editar'))->values();
    }

    /** O atendente pelo nome que o cliente disse ("o Alexandre", "Rossini"), ou null. */
    public function atendentePorNome(?string $nome): ?User
    {
        $nome = mb_strtolower(Str::ascii(trim((string) $nome)));
        if ($nome === '') {
            return null;
        }
        $achados = $this->atendentes()->filter(function (User $u) use ($nome) {
            $palavras = preg_split('/\s+/', mb_strtolower(Str::ascii($u->name))) ?: [];

            return in_array($nome, $palavras, true) || str_starts_with(mb_strtolower(Str::ascii($u->name)), $nome);
        });

        return $achados->count() === 1 ? $achados->first() : null;
    }

    /**
     * Passa a conversa a uma pessoa: a nomeada, se for da equipe; senão, a fila.
     *
     * @return array{atendente: ?User, texto: string} o que o cliente ouve no fim da resposta
     */
    public function transferir(SuporteConversa $c, ?string $nome, string $motivo): array
    {
        $alvo = $this->atendentePorNome($nome);
        $c->update(['situacao' => 'humano', 'humano_id' => $alvo?->id, 'humano_ate' => now()->addHours(SuporteConversa::HUMANO_HORAS),
            'fila_desde' => now(), 'fila_motivo' => Str::limit(trim($motivo) ?: 'cliente pediu um atendente', 250, '…'), 'fila_lembrada_em' => now()]);
        $this->avisar($c, $alvo, false);
        $primeiro = $alvo ? Str::before($alvo->name, ' ') : null;
        // Como uma pessoa da equipe chamaria um colega (T-478): sem "fila" nem "transferência".
        $texto = $alvo
            ? "Já chamei o {$primeiro}, ele continua com você por aqui."
            : ($nome ? "Já avisei a equipe que você quer falar com {$nome}; alguém continua com você por aqui."
                : 'Já chamei um colega da equipe, ele continua com você por aqui.');

        return ['atendente' => $alvo, 'texto' => $texto.(Horario::aberto() ? '' : self::FORA_DO_HORARIO)];
    }

    /**
     * Conversa na fila (ou com alguém) sem resposta de uma pessoa: lembra a equipe a cada
     * LEMBRAR_MINUTOS, só no horário. Parar de lembrar é responder ao cliente.
     */
    public function lembrarFila(): int
    {
        if (! Horario::aberto()) {
            return 0;
        }
        $n = 0;
        $limite = now()->subMinutes(self::LEMBRAR_MINUTOS);
        foreach (SuporteConversa::where('situacao', 'humano')->whereNotNull('fila_desde')->where('humano_ate', '>', now())
            ->where(fn ($q) => $q->whereNull('fila_lembrada_em')->orWhere('fila_lembrada_em', '<', $limite))->get() as $c) {
            // Espaçado (22/10/2026: os lembretes empilhavam no sino): a cada 15 min na primeira hora,
            // depois de hora em hora.
            if ($c->fila_desde->lt(now()->subHour()) && $c->fila_lembrada_em && $c->fila_lembrada_em->gt(now()->subHour())) {
                continue;
            }
            $ultimaPessoa = $c->mensagens()->reorder()->whereIn('direcao', ['humano_app', 'humano_matriz'])->max('created_at');
            $ultimaDoCliente = $c->mensagens()->reorder()->where('direcao', 'entrada')->max('created_at');
            // Alguém já respondeu depois da última mensagem do cliente: não está esperando.
            if ($ultimaPessoa && (! $ultimaDoCliente || $ultimaPessoa >= $ultimaDoCliente)) {
                $c->update(['fila_desde' => null, 'fila_lembrada_em' => null]);

                continue;
            }
            $c->update(['fila_lembrada_em' => now()]);
            $this->avisar($c, $c->humano_id ? User::find($c->humano_id) : null, true);
            $n++;
        }

        return $n;
    }

    private function avisar(SuporteConversa $c, ?User $alvo, bool $lembrete): void
    {
        $quem = $c->nome_perfil ?: $c->telefoneLegivel();
        $espera = $c->fila_desde ? ' (esperando há '.$c->fila_desde->diffForHumans(null, true).')' : '';
        $titulo = ($lembrete ? 'Ainda sem resposta: ' : ($alvo ? 'Chamaram você no WhatsApp: ' : 'Na fila do WhatsApp: ')).$quem.' — '.$c->fila_motivo.($lembrete ? $espera : '');
        $rota = route('suporte.conversas', $c->id);
        foreach ($alvo ? collect([$alvo]) : $this->atendentes() as $u) {
            // Um aviso por conversa: o novo substitui o anterior da mesma fila, em vez de empilhar.
            Notificacao::where('destinatario_id', $u->id)->where('rota', $rota)->where('meta', 'Suporte WhatsApp')
                ->where(fn ($q) => $q->where('titulo', 'like', 'Ainda sem resposta:%')->orWhere('titulo', 'like', 'Na fila do WhatsApp:%')->orWhere('titulo', 'like', 'Chamaram você no WhatsApp:%'))
                ->delete();
            Notificacao::create(['destinatario_id' => $u->id, 'tipo' => 'direcionamento', 'nivel' => $lembrete ? 'critico' : 'atencao', 'icone' => 'balao',
                'titulo' => Notificacao::caber($titulo), 'meta' => 'Suporte WhatsApp', 'rota' => $rota]);
        }
        if ($this->telegram->configurado()) {
            $this->telegram->enviar(($lembrete ? '⏰ ' : '🙋 ').'<b>'.e($alvo ? 'Para '.$alvo->name : 'Fila do WhatsApp').'</b>: '.e($quem)."\n".e((string) $c->fila_motivo).e($espera)."\n".e(route('suporte.conversas', $c->id)));
        }
    }
}
