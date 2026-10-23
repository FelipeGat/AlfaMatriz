<?php

namespace App\Services\Suporte;

use App\Models\Notificacao;
use App\Models\SuporteConversa;
use App\Models\SuporteGestorAbertura;
use App\Models\User;
use App\Services\AvisoNoTelegram;
use Illuminate\Support\Str;

/**
 * O atendimento que o Pedro abre (docs/specs/suporte-pedro-whatsapp): sempre NO Gestor,
 * gravado pela máquina, pela ponte. O quadro do Matriz não recebe pedido de cliente
 * (T-488, dono em 22/10/2026): o suporte é da Invest (N1), que fala com o cliente final e
 * abre chamado só no Gestor; a Alfa é N2/N3 dos sistemas dela, e o quadro é do ciclo de
 * desenvolvimento. Quando o Gestor não dá (cliente sem ligação lá, gravação desligada,
 * recusa, máquina parada), a conversa vai a um atendente com a nota do que lançar lá —
 * nunca uma tarefa, e o cliente nunca ouve um número T-N.
 */
class Atendimento
{
    public const CONTA = User::EMAIL_DO_PEDRO;

    public function __construct(private AvisoNoTelegram $telegram) {}

    /**
     * Sem chamado no Gestor (T-488): a conversa vai a um atendente, com o motivo e a nota do
     * que lançar lá. A nota vem primeiro porque o motivo da fila cabe em 250 caracteres.
     *
     * @return array{atendente: ?User, texto: string} o que o cliente ouve
     */
    public function paraAtendente(SuporteConversa $c, string $porque, string $resumo, ?int $assuntoId = null): array
    {
        return app(Transferencia::class)->transferir($c, null, $this->notaDoGestor($c, $resumo, $assuntoId).' ('.$porque.')');
    }

    /** O que lançar no Gestor: o cliente de lá (ou o do Matriz, para procurar), o assunto sugerido e o pedido. */
    public function notaDoGestor(SuporteConversa $c, string $resumo, ?int $assuntoId = null, ?int $gestorId = null): string
    {
        $d = app(DiretorioGestor::class);
        $gestorId ??= $c->gestor_cliente_id ? (int) $c->gestor_cliente_id : null;
        $g = $gestorId ? $d->cliente($gestorId) : null;
        $assunto = $g && $assuntoId ? collect($d->assuntosPara($g))->firstWhere('id', $assuntoId) : null;
        $cliente = match (true) {
            $g !== null => 'cliente nº '.$g['id'].' ('.(trim((string) ($g['fantasia'] ?? '')) ?: $g['nome']).')',
            $gestorId !== null => 'cliente nº '.$gestorId,
            $c->cliente !== null => ($c->cliente->nome_fantasia ?: $c->cliente->nome).' (cadastro do Matriz sem ligação no Gestor: procure pelo nome)',
            default => 'cliente sem cadastro, '.$c->telefoneLegivel(),
        };
        // Quem pediu é a pessoa que se identificou (T-478), e não o nome do perfil do WhatsApp.
        $quem = filled($c->solicitante_nome) ? 'Solicitante: '.$c->solicitante().'. ' : '';

        return 'LANÇAR NO GESTOR: '.$cliente.($assunto ? ', assunto '.$assunto['id'].' '.$assunto['nome'] : '').'. '.$quem.(trim($resumo) ?: 'Cliente pediu atendimento pelo WhatsApp.');
    }

    /**
     * Antes de abrir, conferir (Felipe, 21/10/2026: "sempre conferir se não tem chamado em
     * aberto, se não tem chamado anterior com o mesmo assunto para, em vez de abrir um novo,
     * reabrir — garantia"):
     *   - o nº que a conversa citou, se é deste cliente: aberto → vincular; concluído → reabrir;
     *   - aberto do mesmo assunto → vincular;
     *   - mesmo assunto concluído dentro da garantia → reabrir (o mais recente);
     *   - senão, novo.
     * O Gestor confere de novo na hora de gravar (o retrato pode ter 10 min).
     *
     * @return array{modo: string, numero: ?string}
     */
    public function modoNoGestor(int $clienteId, ?int $assuntoId, ?string $numeroCitado = null): array
    {
        $doCliente = collect(app(DiretorioGestor::class)->retrato()['atendimentos'] ?? [])->filter(fn ($a) => (int) ($a['cliente_id'] ?? 0) === $clienteId)
            ->reject(fn ($a) => ($a['status'] ?? '') === 'cancelado')->sortByDesc(fn ($a) => (string) ($a['criado_em'] ?? ''));
        $aberto = fn ($a) => ($a['status'] ?? '') !== 'concluido';
        if ($numeroCitado && ($a = $doCliente->first(fn ($x) => (string) ($x['numero'] ?? '') === $numeroCitado))) {
            return ['modo' => $aberto($a) ? 'vincular' : 'reabrir', 'numero' => $numeroCitado];
        }
        if (! $assuntoId) {
            return ['modo' => 'novo', 'numero' => null];
        }
        $mesmo = $doCliente->filter(fn ($a) => (int) ($a['assunto_id'] ?? 0) === $assuntoId);
        if ($a = $mesmo->first($aberto)) {
            return ['modo' => 'vincular', 'numero' => (string) $a['numero']];
        }
        $limite = now()->subDays(max(0, (int) config('services.suporte_pedro.garantia_dias', 90)))->toDateTimeString();
        if ($a = $mesmo->first(fn ($x) => ! $aberto($x) && (string) ($x['criado_em'] ?? '') >= $limite)) {
            return ['modo' => 'reabrir', 'numero' => (string) $a['numero']];
        }

        return ['modo' => 'novo', 'numero' => null];
    }

    /**
     * Cliente do Gestor (Felipe, 21/10/2026: "sempre ir para o Gestor"): o atendimento é
     * gravado NO Gestor pela máquina, pela ponte — novo, vinculado ou reaberto, conforme
     * modoNoGestor. Aqui só se anota o pedido (um por conversa em aberto).
     */
    public function pedirNoGestor(SuporteConversa $c, string $resumo, ?int $assuntoId = null, ?string $numeroCitado = null, ?string $prioridade = null): ?SuporteGestorAbertura
    {
        $aberta = SuporteGestorAbertura::where('conversa_id', $c->id)->whereIn('situacao', ['pendente', 'reservado'])->first();
        if ($aberta) {
            return $aberta;
        }
        $g = app(DiretorioGestor::class)->cliente((int) $c->gestor_cliente_id);
        $assuntos = $g ? app(DiretorioGestor::class)->assuntosPara($g) : [];
        // Só assunto que já existe no Gestor para a empresa do cliente; o escolhido fora da lista dá lugar ao que ele mais usa.
        $assunto = collect($assuntos)->firstWhere('id', $assuntoId) ?? ($assuntos[0] ?? null);
        if (! $g || ! $assunto) {
            return null;
        }
        // Quem pediu é a pessoa que se identificou (T-478), e não o nome do perfil do WhatsApp.
        $solicitante = $c->solicitante();
        $modo = $this->modoNoGestor((int) $c->gestor_cliente_id, (int) $assunto['id'], $numeroCitado);

        return SuporteGestorAbertura::create(['conversa_id' => $c->id, 'gestor_cliente_id' => $c->gestor_cliente_id, 'assunto_id' => $assunto['id'], 'empresa_id' => $g['empresa_id'] ?? null,
            'modo' => $modo['modo'], 'atendimento_numero' => $modo['numero'], 'prioridade' => $prioridade ?? 'media',
            'descricao' => Str::limit(trim($resumo) ?: 'Cliente pediu atendimento pelo WhatsApp.', 1500)."\n\n(Aberto pelo Pedro, suporte no WhatsApp — {$solicitante}, {$c->telefoneLegivel()}.)",
            'solicitante' => Str::limit($solicitante, 120, ''), 'telefone' => $c->telefone]);
    }

    /** A máquina gravou (ou não) no Gestor: avisa o cliente e a equipe; se falhou, a conversa vai a um atendente (T-488). */
    public function concluirNoGestor(SuporteGestorAbertura $a, ?string $numero, ?string $erro, CanalWhatsApp $canal): void
    {
        $c = $a->conversa;
        if ($numero) {
            $a->update(['situacao' => 'feito', 'numero' => mb_substr($numero, 0, 30)]);
            if (! $a->pedido_por) {
                $c->update(['gestor_atendimento' => $a->numero]);
            }
            $g = app(DiretorioGestor::class)->cliente((int) $a->gestor_cliente_id);
            $nome = $g['fantasia'] ?? $g['nome'] ?? (($a->novo_pre['nome'] ?? null) ? $a->novo_pre['nome'].' (pré-cliente criado agora)' : 'cliente do Gestor');
            foreach ($this->responsaveis() as $u) {
                Notificacao::create(['destinatario_id' => $u->id, 'tipo' => 'direcionamento', 'nivel' => 'atencao', 'icone' => 'balao',
                    'titulo' => Notificacao::caber("Gestor nº {$a->numero}: {$nome} — ".Str::before($a->descricao, "\n\n")), 'meta' => 'Suporte WhatsApp', 'rota' => route('suporte.conversas', $c->id)]);
            }
            if ($this->telegram->configurado()) {
                $this->telegram->enviar('📞 <b>Gestor nº '.e($a->numero).'</b>: '.e($nome)."\n".e(Str::before($a->descricao, "\n\n"))."\n".e(route('suporte.conversas', $c->id)));
            }
            // O número sempre volta para quem pediu (Felipe, 21/10/2026), dizendo o que foi feito.
            $texto = match ($a->modo) {
                'vincular' => "Verifiquei que já existe um chamado aberto sobre esse assunto, o nº {$a->numero}, e acrescentei a ele as informações que você me passou.",
                'reabrir' => "Esse problema já tinha um chamado, o nº {$a->numero}, e eu o reabri para que a equipe analise novamente.",
                default => "Abri o seu chamado, de nº {$a->numero}.",
            };
            // Quem é da casa recebe o texto de registro, seco de propósito; o cliente, o de uma pessoa da equipe (T-478).
            $texto = $a->pedido_por ? match ($a->modo) {
                'vincular' => "Registrei no atendimento nº {$a->numero}, que já estava aberto para isso.",
                'reabrir' => "Reabri o atendimento nº {$a->numero}, do mesmo assunto.",
                default => "O atendimento é o nº {$a->numero}.",
            }." Cliente: {$nome}, no Gestor.".$this->linhaDaAgenda($a) : $texto.' A equipe técnica retornará o contato por aqui.';
            try {
                $canal->enviar($c, $texto, 'pedro');
            } catch (\Illuminate\Validation\ValidationException) {
                // Fora da janela: a equipe já foi avisada.
            }

            return;
        }
        $a->update(['situacao' => 'falhou', 'erro' => mb_substr($erro ?: 'a ponte não devolveu o número', 0, 255)]);
        $pedido = Str::before($a->descricao, "\n\n");
        $nota = $this->notaDoGestor($c, $pedido, $a->assunto_id, $a->gestor_cliente_id ? (int) $a->gestor_cliente_id : null);
        // Quem é da casa pediu: a resposta é para ele mesmo lançar, e a conversa dele não vai à fila.
        $texto = $a->pedido_por
            ? "Não consegui gravar no Gestor ({$a->erro}). Lance à mão, por favor: ".Str::after($nota, 'LANÇAR NO GESTOR: ')
            : $this->paraAtendente($c, 'o Gestor não gravou: '.$a->erro, $pedido, $a->assunto_id)['texto'];
        try {
            $canal->enviar($c, $texto, 'pedro');
        } catch (\Illuminate\Validation\ValidationException) {
            // Fora da janela: a conversa já está na fila da equipe.
        }
    }

    /**
     * Atendimento pedido por quem é da casa (acesso-interno.md, FR-A7): para um cliente
     * do Gestor, em modo novo, vincular ou reabrir, com responsável e data. Vai pela máquina
     * como o do cliente; com a gravação desligada, a resposta diz o que lançar no Gestor —
     * e nada vai ao quadro (T-488). A conversa da pessoa não fica presa a ela:
     * quem é da casa registra quantos precisar.
     *
     * @param  array{nome: string, perfil: string}  $acesso
     * @param  array  $cliente  o cliente do retrato do Gestor
     * @return string|null o que vai na resposta, ou null se nada foi registrado
     */
    public function registrarPorDentro(SuporteConversa $c, array $acesso, ?array $cliente, array $bloco): ?string
    {
        $d = app(DiretorioGestor::class);
        // Sem cliente da lista: só com pré-cliente NOVO pedido (nome e cidade), que nasce no Gestor junto do atendimento.
        $novoPre = $cliente ? null : ($bloco['novo_pre'] ?? null);
        if (! $cliente && ! $novoPre) {
            return null;
        }
        $nomeCliente = $cliente ? trim(($cliente['fantasia'] ?? '') ?: ($cliente['nome'] ?? 'cliente')) : $novoPre['nome'].' (pré-cliente novo)';
        $atendimento = $cliente && $bloco['atendimento'] ? $d->atendimentoPorNumero($bloco['atendimento']) : null;
        // Vincular ou reabrir só um atendimento DESTE cliente; fora disso, é um novo.
        $modo = in_array($bloco['modo'], ['vincular', 'reabrir'], true) && $atendimento && (int) $atendimento['cliente_id'] === (int) $cliente['id'] ? $bloco['modo'] : 'novo';
        $data = $bloco['data'] ? \Carbon\CarbonImmutable::parse($bloco['data']) : null;
        $tecnico = $bloco['tecnico_id'] ? collect($d->retrato()['equipe'] ?? [])->firstWhere('id', $bloco['tecnico_id']) : null;
        // Toda agenda precisa de técnico e de horário de início e de fim (Felipe, 22/10/2026): sem eles, não agenda —
        // e não registra, para o Pedro perguntar de novo em vez de abrir pela metade.
        $inicio = $bloco['hora_inicio'] ?? null;
        $fim = $bloco['hora_fim'] ?? null;
        if ($bloco['agendar']) {
            // Dia que já passou também vale, até 30 dias (23/10/2026: "lance na agenda de ontem" era recusado
            // como se faltasse a data); a visita entra agendada e o Gestor a finaliza às 23:55.
            $falta = array_filter([! $tecnico ? 'do técnico' : null, ! $data || $data->isBefore(today()->subDays(30)) ? 'da data (até 30 dias para trás)' : null,
                ! $inicio ? 'do horário de início' : null, ! $fim || ($inicio && $fim <= $inicio) ? 'do horário de término' : null]);
            if ($falta) {
                return 'Ainda não registrei: para agendar preciso '.implode(', ', $falta).'. Me diga e eu mando o resumo de novo.';
            }
        }
        $agendar = (bool) $bloco['agendar'];
        $empresas = app(AcessoInterno::class)->empresas();
        $empresaId = isset($empresas[$bloco['empresa_id']]) ? (int) $bloco['empresa_id'] : ($cliente['empresa_id'] ?? null);
        // O assunto tem de ser da empresa escolhida (cada uma tem os seus no Gestor).
        $assunto = collect($d->assuntosPara(['id' => (int) ($cliente['id'] ?? 0), 'empresa_id' => $empresaId]))->firstWhere('id', $bloco['assunto_id']);
        $detalhes = array_filter([
            $tecnico ? 'Técnico: '.$tecnico['nome'].'.' : ($bloco['responsavel'] ? 'Responsável: '.$bloco['responsavel'].'.' : null),
            $agendar ? 'Agendado para '.$data->format('d/m/Y')." das {$inicio} às {$fim}." : ($data ? 'Para: '.$data->format('d/m/Y').'.' : null),
        ]);
        $quem = "{$acesso['nome']} ({$c->telefoneLegivel()})";
        $resumo = trim((string) $bloco['resumo']) ?: 'Atendimento pedido pelo WhatsApp.';
        $titulo = $bloco['titulo'] ?? null;
        $texto = ($titulo ? $titulo."\n\n" : '').$resumo;

        if (config('services.suporte_pedro.gestor_gravar')) {
            // Novo sem assunto válido fica com o que a empresa mais usa (como no pedido do cliente).
            $assunto ??= $d->assuntosPara(['id' => (int) ($cliente['id'] ?? 0), 'empresa_id' => $empresaId])[0] ?? null;
            if ($modo === 'novo' && ! $assunto) {
                return null;
            }
            SuporteGestorAbertura::create(['conversa_id' => $c->id, 'gestor_cliente_id' => $cliente ? (int) $cliente['id'] : null, 'novo_pre' => $novoPre,
                'assunto_id' => $assunto['id'] ?? (int) ($atendimento['assunto_id'] ?? 0), 'empresa_id' => $empresaId, 'titulo' => $titulo,
                'funcionario_id' => $tecnico['id'] ?? null, 'agendar_data' => $agendar ? $data->toDateString() : null,
                'hora_inicio' => $agendar ? $inicio : null, 'hora_fim' => $agendar ? $fim : null,
                'modo' => $modo, 'atendimento_numero' => $modo === 'novo' ? null : (string) $atendimento['numero'], 'pedido_por' => Str::limit($acesso['nome'], 120, ''),
                'descricao' => Str::limit($texto.($detalhes ? "\n\n".implode(' ', $detalhes) : ''), 1500)."\n\n(Registrado pelo Pedro a pedido de {$quem}.)",
                'solicitante' => Str::limit($acesso['nome'], 120, ''), 'telefone' => $c->telefone]);

            return 'Enviei para o Gestor; o número do atendimento chega em seguida.';
        }
        if (! $cliente) {
            return null;
        }
        $acao = match ($modo) {
            'vincular' => 'vincular ao atendimento nº '.$atendimento['numero'].' (em aberto)',
            'reabrir' => 'reabrir o atendimento nº '.$atendimento['numero'],
            default => 'abrir atendimento novo',
        };

        return "Não registrei: a gravação no Gestor está desligada. Lance lá, por favor: {$acao} — cliente nº {$cliente['id']} ({$nomeCliente})"
            .($assunto ? ", assunto {$assunto['id']} {$assunto['nome']}" : '').'. '.$resumo.($detalhes ? ' '.implode(' ', $detalhes) : '');
    }

    /** A agenda do atendimento, para quem é da casa: agendado, recusado pela agenda ou sem agendamento. */
    private function linhaDaAgenda(SuporteGestorAbertura $a): string
    {
        $tecnico = $a->funcionario_id ? (string) (collect(app(DiretorioGestor::class)->retrato()['equipe'] ?? [])->firstWhere('id', $a->funcionario_id)['nome'] ?? '') : '';
        if (! $a->agendar_data) {
            return ($tecnico !== '' ? "\nTécnico responsável: {$tecnico}." : '').' Sem agendamento.';
        }
        $quando = $a->agendar_data->format('d/m/Y').', '.($a->hora_inicio ? "das {$a->hora_inicio} às {$a->hora_fim}" : (self::PERIODOS[$a->agendar_periodo] ?? $a->agendar_periodo));

        return $a->agendamento === 'agendado'
            ? "\nAgendado: {$tecnico}, {$quando}."
            : "\nNão consegui agendar ({$tecnico}, {$quando}): ".Str::after((string) $a->agendamento, 'não agendado: ').'. O atendimento ficou aberto sem agenda.';
    }

    /** Os períodos da agenda técnica do Gestor (AgendaTecnicaService::PERIODOS), como a pessoa diz. */
    public const PERIODOS = ['manha' => 'manhã (08h às 12h)', 'tarde' => 'tarde (13h às 18h)', 'dia_todo' => 'dia todo (08h às 18h)', 'noite' => 'noite (18h às 22h)'];

    /** @return \Illuminate\Support\Collection<int, User> */
    private function responsaveis()
    {
        return User::whereIn('email', (array) config('services.suporte_pedro.responsaveis'))->where('ativo', true)->get();
    }
}
