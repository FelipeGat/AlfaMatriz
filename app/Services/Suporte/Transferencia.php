<?php

namespace App\Services\Suporte;

use App\Models\Notificacao;
use App\Models\SuporteConversa;
use App\Models\User;
use App\Services\AvisoNoTelegram;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A conversa que sai do Pedro para uma pessoa (Felipe, 21/10/2026: "os atendimentos não
 * podem ficar agarrados sempre com o Pedro; quando ele não conseguir resolver algo ou o
 * cliente informar que deseja falar com um atendente específico, ele tem que direcionar
 * para uma fila ou comunicar o usuário").
 *
 * Com um nome que bate com alguém da equipe, a conversa vai para essa pessoa; sem nome, para
 * quem responde pelos chamados do Pedro (Felipe, 07/11/2026: "se o Pedro informar que irá
 * passar para outro atendente da equipe, não pode morrer ali, ele tem que passar para o
 * Alexandre até ele perceber que ele deu seguimento"). A fila geral só sobra sem ele.
 * Quem atende é avisado no sino e no Telegram, e o cliente sem resposta é cobrado
 * (lembrarFila) até alguém responder, encerrar ou devolver ao Pedro.
 */
class Transferencia
{
    /** Sem resposta de uma pessoa nesse tempo, a equipe é lembrada de novo. */
    public const LEMBRAR_MINUTOS = 15;

    /**
     * Fechado o prazo do WhatsApp há mais que isto, o último aviso não sai mais: cobre da sexta às
     * 18:00 à segunda às 08:00 (62 h). Mais velha que isso é conversa de antes desta cobrança,
     * que só sai da fila — ninguém precisa saber hoje de um prazo que acabou na semana passada.
     */
    public const ULTIMO_AVISO_HORAS = 72;

    /** [PROPOSTA, 07/11/2026] O lembrete a quem está com a conversa (encerramento.md, FR-F7): nome, motivo, espera, prazo e link. */
    public const ESPERANDO = 'Cliente esperando resposta: %s — %s. Esperando há %s. O WhatsApp aceita resposta até as %s. Responda pelo Matriz: %s';

    /** [PROPOSTA, 07/11/2026] O último aviso, quando o WhatsApp deixa de aceitar resposta: nome, hora e link. */
    public const PRAZO_ACABOU = 'O prazo do WhatsApp para responder %s acabou às %s: agora só por ligação. %s';

    /** O que se acrescenta fora do horário, onde quer que a conversa passe a uma pessoa (o horário vem do ajuste, Horario::faixa). */
    public static function foraDoHorario(): string
    {
        return ' Como já passou do nosso horário ('.Horario::faixa().', de segunda a sexta), o retorno é no próximo horário de atendimento.';
    }

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
     * Quem responde pelos chamados do Pedro (`services.suporte_pedro.responsavel_chamados`, o mesmo do
     * Atendimento), se for da equipe: conta desativada ou sem `suporte` › editar não recebe conversa,
     * e aí ela fica na fila geral, como antes.
     */
    public function responsavel(): ?User
    {
        $email = mb_strtolower(trim((string) config('services.suporte_pedro.responsavel_chamados')));

        return $email === '' ? null : $this->atendentes()->first(fn (User $u) => mb_strtolower($u->email) === $email);
    }

    /**
     * Passa a conversa a uma pessoa: a nomeada, se for da equipe; senão, o responsável; sem ele, a fila.
     *
     * @return array{atendente: ?User, texto: string} o que o cliente ouve no fim da resposta
     */
    public function transferir(SuporteConversa $c, ?string $nome, string $motivo): array
    {
        $chamado = $this->atendentePorNome($nome);
        $alvo = $chamado ?? $this->responsavel();
        $c->update(['situacao' => 'humano', 'humano_id' => $alvo?->id, 'humano_ate' => now()->addHours(SuporteConversa::HUMANO_HORAS),
            'fila_desde' => now(), 'fila_motivo' => Str::limit(trim($motivo) ?: 'cliente pediu um atendente', 250, '…'), 'fila_lembrada_em' => now()]);
        app(CanalWhatsApp::class)->marcarSuporte($c);
        $this->avisarPassagem($c, $alvo, $chamado !== null);
        $primeiro = $chamado ? Str::before($chamado->name, ' ') : null;
        // Como uma pessoa da equipe chamaria um colega (T-478): sem "fila" nem "transferência". O cliente
        // ouve o mesmo de antes quando a conversa vai ao responsável: quem ele é não muda nada para o cliente.
        $texto = $chamado
            ? "Já chamei o {$primeiro}, ele continua com você por aqui."
            : ($nome ? "Já avisei a equipe que você deseja falar com {$nome}; em breve alguém continua com você por aqui."
                : 'Já chamei um colega da equipe, que continua com você por aqui.');

        return ['atendente' => $alvo, 'texto' => $texto.(Horario::aberto() ? '' : self::foraDoHorario())];
    }

    /**
     * Cliente que espera uma pessoa e não teve resposta: cobra quem está com a conversa (o responsável, se
     * ninguém está) a cada LEMBRAR_MINUTOS na primeira hora e depois de hora em hora, só no horário. Todas
     * as conversas dele saem juntas, numa rodada: no sino, uma por conversa; no Telegram e no WhatsApp
     * do responsável, uma mensagem só. A espera sai das mensagens, e não do prazo da conversa com a
     * pessoa (07/11/2026: depois da primeira resposta, ou passadas as 24 h, ninguém era mais cobrado):
     * para quando alguém responde, encerra ou devolve ao Pedro, e volta quando o cliente escreve de
     * novo. Acabado o prazo do WhatsApp, sai um último aviso, e a conversa sai da cobrança.
     */
    public function lembrarFila(): int
    {
        $aberto = Horario::aberto();
        $atendentes = $this->atendentes();
        $responsavel = $this->responsavel();
        /** @var array<string, array{alvo: ?User, vence: bool, itens: list<array{0: SuporteConversa, 1: Carbon, 2: bool}>}> $rodada */
        $rodada = [];
        foreach (SuporteConversa::where('situacao', 'humano')
            ->where(fn ($q) => $q->whereNotNull('fila_desde')->orWhere('ultima_entrada_em', '>=', now()->subHours(SuporteConversa::JANELA_HORAS)))->get() as $c) {
            // Fora do horário também, para a tela não mostrar "Na fila" o que já foi respondido à noite.
            $desde = $this->esperaDesde($c);
            if (! $desde || ! $aberto) {
                continue;
            }
            $acabou = ! $c->janelaAberta();
            if ($acabou) {
                $c->update(['fila_desde' => null, 'fila_lembrada_em' => null]);
                if (! $c->ultima_entrada_em || $c->ultima_entrada_em->copy()->addHours(SuporteConversa::JANELA_HORAS)->lt(now()->subHours(self::ULTIMO_AVISO_HORAS))) {
                    continue;
                }
            }
            $alvo = ($c->humano_id ? $atendentes->firstWhere('id', $c->humano_id) : null) ?? $responsavel;
            $chave = (string) ($alvo?->id ?? '');
            $rodada[$chave] ??= ['alvo' => $alvo, 'vence' => false, 'itens' => []];
            $rodada[$chave]['vence'] = $rodada[$chave]['vence'] || $acabou || $this->vence($c, $desde);
            $rodada[$chave]['itens'][] = [$c, $desde, $acabou];
        }
        $n = 0;
        foreach ($rodada as ['alvo' => $alvo, 'vence' => $vence, 'itens' => $itens]) {
            if ($vence) {
                $this->lembrar($alvo, $itens, $alvo !== null && $alvo->is($responsavel));
                $n += count($itens);
            }
        }

        return $n;
    }

    /**
     * Desde quando o cliente espera uma pessoa, ou null se alguém da equipe respondeu depois da última
     * mensagem dele. Acerta a marca da conversa (fila_desde) pelo caminho: apaga quando foi respondido,
     * e arma de novo quando ele voltou a escrever depois de uma resposta, ou numa conversa assumida
     * sem resposta. Resposta que não saiu (recusada pelo Gestor ou pela Meta) não respondeu ninguém.
     */
    private function esperaDesde(SuporteConversa $c): ?Carbon
    {
        $saiu = fn () => $c->mensagens()->reorder()->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'falhou'));
        $pessoa = $saiu()->whereIn('direcao', ['humano_app', 'humano_matriz'])->orderByDesc('id')->first();
        $cliente = (int) $c->mensagens()->reorder()->where('direcao', 'entrada')->max('id');
        if (! $cliente || ($pessoa && $pessoa->id > $cliente)) {
            return $this->semEspera($c);
        }
        // A espera que já corria (desde a passagem), sem ninguém ter respondido no meio.
        if ($c->fila_desde && (! $pessoa || $pessoa->created_at->lt($c->fila_desde))) {
            return $c->fila_desde;
        }
        // O cliente voltou a escrever: a espera começa na primeira mensagem dele depois da última de outra pessoa.
        $antes = (int) $saiu()->where('direcao', '!=', 'entrada')->max('id');
        $primeira = $c->mensagens()->reorder()->where('direcao', 'entrada')->where('id', '>', $antes)->orderBy('id')->first();
        // Quem é da casa consulta o Pedro, não é atendimento (Pedro::reserva): só a passagem explícita o põe na fila.
        if (! $primeira || in_array(CanalWhatsApp::chave((string) $c->telefone), app(AcessoInterno::class)->chaves(), true)) {
            return $this->semEspera($c);
        }
        // Já armada por esta mesma mensagem (a resposta e ela no mesmo segundo): sem refazer, o lembrete voltaria a cada minuto.
        if ($c->fila_desde?->eq($primeira->created_at)) {
            return $c->fila_desde;
        }
        $c->update(['fila_desde' => $primeira->created_at, 'fila_lembrada_em' => $primeira->created_at,
            'fila_motivo' => Str::limit('escreveu de novo: '.trim((string) ($primeira->transcricao ?: $primeira->texto)), 250, '…')]);

        return $c->fila_desde;
    }

    private function semEspera(SuporteConversa $c): null
    {
        if ($c->fila_desde) {
            $c->update(['fila_desde' => null, 'fila_lembrada_em' => null]);
        }

        return null;
    }

    /** Vence o lembrete: a cada LEMBRAR_MINUTOS na primeira hora de espera, depois de hora em hora (22/10/2026: os lembretes empilhavam no sino). */
    private function vence(SuporteConversa $c, Carbon $desde): bool
    {
        return ! $c->fila_lembrada_em || $c->fila_lembrada_em->lte(now()->subMinutes($desde->gt(now()->subHour()) ? self::LEMBRAR_MINUTOS : 60));
    }

    /** O aviso de quando a conversa passa a uma pessoa: no sino de quem ficou com ela (ou da equipe) e no Telegram. */
    private function avisarPassagem(SuporteConversa $c, ?User $alvo, bool $chamado): void
    {
        $quem = $c->nome_perfil ?: $c->telefoneLegivel();
        $this->noSino($c, $alvo, ($chamado ? 'Chamaram você no WhatsApp: ' : 'Na fila do WhatsApp: ').$quem.' — '.$c->fila_motivo, 'atencao');
        if ($this->telegram->configurado()) {
            $this->telegram->enviar('🙋 <b>'.e($alvo ? 'Para '.$alvo->name : 'Fila do WhatsApp').'</b>: '.e($quem)."\n".e((string) $c->fila_motivo)."\n".e(route('suporte.conversas', $c->id)));
        }
    }

    /**
     * Uma rodada de lembretes de quem está com as conversas.
     *
     * @param  list<array{0: SuporteConversa, 1: Carbon, 2: bool}>  $itens  a conversa, desde quando espera e se o prazo do WhatsApp acabou
     */
    private function lembrar(?User $alvo, array $itens, bool $noWhatsApp): void
    {
        $linhas = [];
        foreach ($itens as [$c, $desde, $acabou]) {
            $quem = $c->nome_perfil ?: $c->telefoneLegivel();
            $prazo = self::hora($c->ultima_entrada_em->copy()->addHours(SuporteConversa::JANELA_HORAS));
            $rota = route('suporte.conversas', $c->id);
            if ($acabou) {
                $linhas[] = sprintf(self::PRAZO_ACABOU, $quem, $prazo, $rota);
                $this->noSino($c, $alvo, 'Prazo do WhatsApp acabou: '.$quem.' — só por ligação', 'critico');

                continue;
            }
            $motivo = rtrim((string) ($c->fila_motivo ?: 'cliente pediu um atendente'), ' .!?;:');
            $linhas[] = sprintf(self::ESPERANDO, $quem, $motivo, self::tempo($desde), $prazo, $rota);
            $this->noSino($c, $alvo, 'Ainda sem resposta: '.$quem.' — '.$c->fila_motivo.' (esperando há '.self::tempo($desde).')', 'critico');
            $c->update(['fila_lembrada_em' => now()]);
        }
        if ($this->telegram->configurado()) {
            $this->telegram->enviar('⏰ <b>'.e($alvo ? 'Para '.$alvo->name : 'Fila do WhatsApp').'</b>'."\n".implode("\n\n", array_map('e', $linhas)));
        }
        if ($noWhatsApp) {
            $this->noWhatsApp($alvo, $linhas);
        }
    }

    /** Um aviso por conversa e pessoa: o novo substitui o anterior da mesma conversa, em vez de empilhar. */
    private function noSino(SuporteConversa $c, ?User $alvo, string $titulo, string $nivel): void
    {
        $rota = route('suporte.conversas', $c->id);
        foreach ($alvo ? collect([$alvo]) : $this->atendentes() as $u) {
            Notificacao::where('destinatario_id', $u->id)->where('rota', $rota)->where('meta', 'Suporte WhatsApp')
                ->where(fn ($q) => $q->where('titulo', 'like', 'Ainda sem resposta:%')->orWhere('titulo', 'like', 'Na fila do WhatsApp:%')
                    ->orWhere('titulo', 'like', 'Chamaram você no WhatsApp:%')->orWhere('titulo', 'like', 'Prazo do WhatsApp acabou:%'))
                ->delete();
            Notificacao::create(['destinatario_id' => $u->id, 'tipo' => 'direcionamento', 'nivel' => $nivel, 'icone' => 'balao',
                'titulo' => Notificacao::caber($titulo), 'meta' => 'Suporte WhatsApp', 'rota' => $rota]);
        }
    }

    /**
     * O lembrete também no WhatsApp do responsável (Felipe, 07/11/2026), pela conversa dele com o Pedro: é o
     * único jeito de chegar, porque a conta da Meta não inicia conversa, e o Gestor só entrega com ela aberta,
     * com o Pedro e dentro das 24 h da última mensagem dele. Sai como do Pedro, para não contar como primeira
     * resposta da equipe no Gestor. Espera o Pedro responder o que ele mandou, para não cruzar as duas
     * mensagens. O que não sai fica só registrado: o sino e o Telegram já avisaram.
     *
     * @param  list<string>  $linhas
     */
    private function noWhatsApp(User $alvo, array $linhas): void
    {
        $c = app(AcessoInterno::class)->conversaDe((string) config('services.suporte_pedro.responsavel_chamados'));
        $ultima = $c?->mensagens()->reorder()->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'falhou'))->orderByDesc('id')->first();
        $motivo = match (true) {
            ! $c => 'sem linha na lista da casa ou sem conversa com o Pedro',
            ! $c->janelaAberta() => 'ele não escreveu ao Pedro nas últimas 24 h',
            $ultima?->direcao === 'entrada' => 'o Pedro ainda vai responder a ele',
            default => null,
        };
        if ($motivo) {
            Log::info('[Pedro] o lembrete não saiu pelo WhatsApp do responsável', ['motivo' => $motivo, 'responsavel' => $alvo->id]);

            return;
        }
        try {
            app(CanalWhatsApp::class)->enviar($c, implode("\n\n", $linhas), 'pedro');
        } catch (ValidationException|ConnectionException $e) {
            // Recusado pelo Gestor ou pela Meta: a mensagem ficou na conversa dele, marcada como falha.
            Log::info('[Pedro] o lembrete não saiu pelo WhatsApp do responsável', ['conversa' => $c->id, 'erro' => $e::class]);
        }
    }

    /** "14:51", ou "14:51 de 08/11" quando não é hoje: o prazo cai quase sempre no dia seguinte. */
    private static function hora(Carbon $quando): string
    {
        $quando = $quando->copy()->setTimezone(config('app.timezone'));

        return $quando->format('H:i').($quando->isSameDay(now()->setTimezone(config('app.timezone'))) ? '' : ' de '.$quando->format('d/m'));
    }

    /** "15 min", "2 h 10 min": sem depender do idioma do servidor, que muda o diffForHumans. */
    private static function tempo(Carbon $desde): string
    {
        $min = max(1, (int) floor($desde->diffInMinutes(now(), true)));
        if ($min < 60) {
            return "{$min} min";
        }
        $h = intdiv($min, 60);
        if ($h >= 24) {
            return intdiv($h, 24) === 1 ? '1 dia' : intdiv($h, 24).' dias';
        }

        return $h.' h'.($min % 60 ? ' '.($min % 60).' min' : '');
    }
}
