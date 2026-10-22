<?php

namespace App\Services\Suporte;

use App\Models\Notificacao;
use App\Models\SuporteAtendimento;
use App\Models\SuporteConversa;
use App\Models\SuporteGestorAbertura;
use App\Models\SuporteRoteiro;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\AvisoNoTelegram;
use App\Services\TarefaService;
use Illuminate\Support\Str;

/**
 * O atendimento que o Pedro abre (docs/specs/suporte-pedro-whatsapp): uma tarefa
 * Operacional "Suporte: …" no quadro, no sistema do cliente, assinada pela conta
 * do Pedro, com o Rossini de responsável; o Rossini e o Alexandre avisados no
 * sino e o grupo de alertas no Telegram. Conversa com atendimento em curso não
 * abre outro.
 */
class Atendimento
{
    public const CONTA = User::EMAIL_DO_PEDRO;

    public function __construct(private TarefaService $tarefas, private AvisoNoTelegram $telegram) {}

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

    /** A máquina gravou (ou não) no Gestor: avisa o cliente e a equipe; se falhou, abre no quadro do Matriz. */
    public function concluirNoGestor(SuporteGestorAbertura $a, ?string $numero, ?string $erro, CanalWhatsApp $canal): void
    {
        $c = $a->conversa;
        if ($numero) {
            $a->update(['situacao' => 'feito', 'numero' => mb_substr($numero, 0, 30)]);
            if (! $a->pedido_por) {
                $c->update(['gestor_atendimento' => $a->numero]);
            }
            $g = app(DiretorioGestor::class)->cliente((int) $a->gestor_cliente_id);
            $nome = $g['fantasia'] ?? $g['nome'] ?? 'cliente do Gestor';
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
        if ($t = $this->abrir($c, Str::before($a->descricao, "\n\n").' (não foi possível abrir no Gestor: '.$a->erro.')', null)) {
            try {
                $canal->enviar($c, "Abri o seu chamado, de nº T-{$t->id}. A equipe técnica retornará o contato por aqui.", 'pedro');
            } catch (\Illuminate\Validation\ValidationException) {
            }
        }
    }

    /**
     * O registro do atendimento. Na tarefa já aberta, o roteiro mais urgente
     * prevalece (portão parado depois de uma dúvida vira crítico) e o destino
     * Gestor não se perde.
     */
    private function registrar(SuporteConversa $c, Tarefa $t, ?SuporteRoteiro $roteiro, ?string $destino): SuporteAtendimento
    {
        $r = SuporteAtendimento::firstOrNew(['tarefa_id' => $t->id], ['conversa_id' => $c->id]);
        $peso = array_flip(array_keys(SuporteRoteiro::PRIORIDADES));
        if ($roteiro && ($r->prioridade === null || ($roteiro->prioridade && ($peso[$roteiro->prioridade] ?? -1) > ($peso[$r->prioridade] ?? -1)))) {
            $r->roteiro_id = $roteiro->id;
            $r->prioridade = $roteiro->prioridade ?? $r->prioridade;
            $prazo = $roteiro->prazo_horas ? now()->addHours($roteiro->prazo_horas) : null;
            if ($prazo && (! $r->prazo_em || $prazo->lt($r->prazo_em))) {
                $r->prazo_em = $prazo;
            }
        }
        if ($destino === 'gestor' || $r->destino === 'gestor') {
            $r->destino = 'gestor';
            $r->gestor_cliente_id ??= $c->gestor_cliente_id;
        } else {
            $r->destino ??= $destino;
        }
        $r->save();
        // A tarefa mostra no quadro a prioridade e o dia do prazo; a hora fica no registro.
        $t->forceFill(array_filter(['prioridade' => $r->prioridade, 'prazo' => $r->prazo_em?->setTimezone(config('app.timezone'))->toDateString()]))->save();

        return $r;
    }

    /**
     * Atendimento pedido por quem é da casa (acesso-interno.md, FR-A7): para um cliente
     * do Gestor, em modo novo, vincular ou reabrir, com responsável e data. Novo, com a
     * gravação no Gestor ligada, vai pela máquina como o do cliente; o resto vira tarefa
     * no quadro com o que lançar no Gestor. A conversa da pessoa não fica presa a ela:
     * quem é da casa registra quantos precisar.
     *
     * @param  array{nome: string, perfil: string}  $acesso
     * @param  array  $cliente  o cliente do retrato do Gestor
     * @return string|null o que vai na resposta, ou null se nada foi registrado
     */
    public function registrarPorDentro(SuporteConversa $c, array $acesso, array $cliente, array $bloco): ?string
    {
        $d = app(DiretorioGestor::class);
        $nomeCliente = trim(($cliente['fantasia'] ?? '') ?: ($cliente['nome'] ?? 'cliente'));
        $atendimento = $bloco['atendimento'] ? $d->atendimentoPorNumero($bloco['atendimento']) : null;
        // Vincular ou reabrir só um atendimento DESTE cliente; fora disso, é um novo.
        $modo = in_array($bloco['modo'], ['vincular', 'reabrir'], true) && $atendimento && (int) $atendimento['cliente_id'] === (int) $cliente['id'] ? $bloco['modo'] : 'novo';
        $data = $bloco['data'] ? \Carbon\CarbonImmutable::parse($bloco['data']) : null;
        // Técnico só da equipe do retrato; agenda só com técnico, período conhecido e data de hoje em diante.
        $tecnico = $bloco['tecnico_id'] ? collect($d->retrato()['equipe'] ?? [])->firstWhere('id', $bloco['tecnico_id']) : null;
        $agendar = $bloco['agendar'] && $tecnico && $data && ! $data->isBefore(today()) && isset(self::PERIODOS[(string) $bloco['periodo']]);
        $empresas = app(AcessoInterno::class)->empresas();
        $empresaId = isset($empresas[$bloco['empresa_id']]) ? (int) $bloco['empresa_id'] : ($cliente['empresa_id'] ?? null);
        // O assunto tem de ser da empresa escolhida (cada uma tem os seus no Gestor).
        $assunto = collect($d->assuntosPara(['id' => (int) $cliente['id'], 'empresa_id' => $empresaId]))->firstWhere('id', $bloco['assunto_id']);
        $detalhes = array_filter([
            $bloco['responsavel'] ? 'Responsável: '.$bloco['responsavel'].'.' : null,
            $data ? 'Para: '.$data->format('d/m/Y').'.' : null,
        ]);
        $quem = "{$acesso['nome']} ({$c->telefoneLegivel()})";
        $resumo = trim((string) $bloco['resumo']) ?: 'Atendimento pedido pelo WhatsApp.';

        if (config('services.suporte_pedro.gestor_gravar')) {
            // Novo sem assunto válido fica com o que o cliente mais usa (como no pedido do cliente).
            $assunto ??= $d->assuntosPara(['id' => (int) $cliente['id'], 'empresa_id' => $empresaId])[0] ?? null;
            if ($modo === 'novo' && ! $assunto) {
                return null;
            }
            SuporteGestorAbertura::create(['conversa_id' => $c->id, 'gestor_cliente_id' => (int) $cliente['id'], 'assunto_id' => $assunto['id'] ?? (int) ($atendimento['assunto_id'] ?? 0), 'empresa_id' => $empresaId,
                'funcionario_id' => $tecnico['id'] ?? null, 'agendar_data' => $agendar ? $data->toDateString() : null, 'agendar_periodo' => $agendar ? $bloco['periodo'] : null,
                'modo' => $modo, 'atendimento_numero' => $modo === 'novo' ? null : (string) $atendimento['numero'], 'pedido_por' => Str::limit($acesso['nome'], 120, ''),
                'descricao' => Str::limit($resumo.($detalhes ? ' '.implode(' ', $detalhes) : ''), 1500)."\n\n(Registrado pelo Pedro a pedido de {$quem}.)",
                'solicitante' => Str::limit($acesso['nome'], 120, ''), 'telefone' => $c->telefone]);

            return 'Enviei para o Gestor; o número do atendimento chega em seguida.';
        }

        $pedro = User::where('email', self::CONTA)->first();
        if (! $pedro) {
            return null;
        }
        $acao = match ($modo) {
            'vincular' => 'VINCULAR ao atendimento nº '.$atendimento['numero'].' (em aberto)',
            'reabrir' => 'REABRIR o atendimento nº '.$atendimento['numero'],
            default => 'ABRIR atendimento novo',
        };
        $lancar = "LANÇAR NO GESTOR: {$acao} — cliente nº {$cliente['id']} ({$nomeCliente})".($assunto ? ", assunto {$assunto['id']} {$assunto['nome']}" : '').'.';
        $t = $this->tarefas->criar([
            'titulo' => Str::limit('Suporte: '.$nomeCliente.' — '.$resumo, 150),
            'resumo' => Str::limit($resumo.' '.implode(' ', $detalhes).' '.$lancar.' Pedido por '.$quem.' pelo WhatsApp. Conversa: '.route('suporte.conversas', $c->id), 500),
            'tipo' => 'operacional',
        ], $pedro);
        if (! $t) {
            return null;
        }
        $responsaveis = $this->responsaveis()->sortBy(fn ($u) => array_search($u->email, (array) config('services.suporte_pedro.responsaveis'), true))->values();
        $t->forceFill(array_filter(['responsavel_id' => $responsaveis->first()?->id, 'prazo' => $data?->toDateString()]))->save();
        Notificacao::where('tarefa_id', $t->id)->where('tipo', 'triagem')->delete();
        SuporteAtendimento::create(['tarefa_id' => $t->id, 'conversa_id' => $c->id, 'destino' => 'gestor', 'gestor_cliente_id' => (int) $cliente['id'],
            'gestor_numero' => $modo === 'vincular' ? (string) $atendimento['numero'] : null]); // reabrir: o nº volta a valer só quando a equipe confirmar
        foreach ($responsaveis as $u) {
            Notificacao::create(['destinatario_id' => $u->id, 'tipo' => 'direcionamento', 'nivel' => 'atencao', 'icone' => 'balao', 'tarefa_id' => $t->id,
                'titulo' => Notificacao::caber("Suporte T-{$t->id} ({$acesso['nome']}): {$nomeCliente} — {$resumo}"), 'meta' => 'Suporte WhatsApp', 'rota' => route('suporte.conversas', $c->id)]);
        }
        if ($this->telegram->configurado()) {
            $this->telegram->enviar('📞 <b>Suporte T-'.$t->id.'</b> (pedido de '.e($acesso['nome']).'): '.e($nomeCliente)."\n".e($resumo)."\n".e($lancar));
        }

        return "Registrado no quadro como T-{$t->id} ({$acao}). A equipe lança no Gestor.";
    }

    /** A agenda do atendimento, para quem é da casa: agendado, recusado pela agenda ou sem agendamento. */
    private function linhaDaAgenda(SuporteGestorAbertura $a): string
    {
        $tecnico = $a->funcionario_id ? (string) (collect(app(DiretorioGestor::class)->retrato()['equipe'] ?? [])->firstWhere('id', $a->funcionario_id)['nome'] ?? '') : '';
        if (! $a->agendar_data) {
            return ($tecnico !== '' ? "\nTécnico responsável: {$tecnico}." : '').' Sem agendamento.';
        }
        $quando = $a->agendar_data->format('d/m/Y').', '.(self::PERIODOS[$a->agendar_periodo] ?? $a->agendar_periodo);

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

    /**
     * @param  SuporteRoteiro|null  $roteiro  o roteiro que o Pedro seguiu: dá prioridade e prazo
     * @param  string|null  $destino  "gestor": quem atende lança no Gestor e informa o número aqui
     */
    public function abrir(SuporteConversa $c, string $resumo, ?string $sistema, ?SuporteRoteiro $roteiro = null, ?string $destino = null): ?Tarefa
    {
        $c->loadMissing(['cliente.sistemas', 'tarefa']);
        if ($c->tarefa && ! in_array($c->tarefa->status, Tarefa::STATUS_TERMINAIS, true)) {
            $this->registrar($c, $c->tarefa, $roteiro, $destino);

            return $c->tarefa;
        }
        $pedro = User::where('email', self::CONTA)->first();
        if (! $pedro) {
            return null;
        }
        $nomeCliente = $c->cliente ? ($c->cliente->nome_fantasia ?: $c->cliente->nome) : ($c->nome_perfil ?: $c->telefoneLegivel()).' (sem cadastro)';
        $sistemaId = $c->cliente && $sistema
            ? $c->cliente->sistemas->first(fn ($s) => mb_strtolower($s->nome) === mb_strtolower(trim($sistema)))?->id : null;
        $resumo = trim($resumo) ?: 'Cliente pediu atendimento pelo WhatsApp.';
        $quem = filled($c->solicitante_nome) ? ' Solicitante: '.$c->solicitante().'.' : '';

        $t = $this->tarefas->criar([
            'titulo' => Str::limit('Suporte: '.$nomeCliente.' — '.$resumo, 150),
            'resumo' => Str::limit($resumo.$quem.' WhatsApp '.$c->telefoneLegivel().'. Conversa: '.route('suporte.conversas', $c->id), 500),
            'tipo' => 'operacional',
            'sistema_id' => $sistemaId,
        ], $pedro);
        if (! $t) {
            return null;
        }
        $responsaveis = User::whereIn('email', (array) config('services.suporte_pedro.responsaveis'))->where('ativo', true)->get()
            ->sortBy(fn ($u) => array_search($u->email, (array) config('services.suporte_pedro.responsaveis'), true))->values();
        if ($responsaveis->isNotEmpty()) {
            $t->forceFill(['responsavel_id' => $responsaveis->first()->id])->save();
            // A conta do Pedro não triaga, então o quadro avisou a triagem; mas o atendimento
            // já nasce com dono, e o aviso iria para quem não cuida do suporte.
            Notificacao::where('tarefa_id', $t->id)->where('tipo', 'triagem')->delete();
        }
        $c->update(['tarefa_id' => $t->id]);
        $this->registrar($c, $t, $roteiro, $destino);

        foreach ($responsaveis as $u) {
            Notificacao::create(['destinatario_id' => $u->id, 'tipo' => 'direcionamento', 'nivel' => 'atencao', 'icone' => 'balao', 'tarefa_id' => $t->id,
                'titulo' => Notificacao::caber("Suporte T-{$t->id}: {$nomeCliente} — {$resumo}"), 'meta' => 'Suporte WhatsApp', 'rota' => route('suporte.conversas', $c->id)]);
        }
        if ($this->telegram->configurado()) {
            $this->telegram->enviar('📞 <b>Suporte T-'.$t->id.'</b>: '.e($nomeCliente)."\n".e($resumo)."\n".e(route('suporte.conversas', $c->id)));
        }

        return $t;
    }
}
