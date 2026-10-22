<?php

namespace App\Services\Suporte;

use App\Models\SuporteConversa;
use App\Models\SuporteMensagem;
use App\Models\SuportePedido;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A fila do Pedro (docs/specs/suporte-pedro-whatsapp, etapa 2). Quem pensa é a IA
 * do runner da conta configurada (constitution II), numa vaga só do suporte e
 * sem ferramentas; o Matriz monta o contexto, confere a resposta e faz o resto.
 *
 * Um pedido aberto por conversa: o que o cliente escrever enquanto o Pedro pensa
 * vira o pedido seguinte. Sem resposta da IA a tempo (máquina desligada,
 * travada), o Matriz responde sozinho e abre o atendimento — o cliente nunca
 * fica sem resposta.
 */
class Pedro
{
    // Frases fixas no tom de uma pessoa da equipe (T-478, dono em 21/10/2026: "o mais natural possível").
    public const FALLBACK = 'Olá! Recebi a sua mensagem, e um colega da equipe responderá por aqui em instantes.';

    // Sem "esse assunto": a frase também sai quando a pessoa só cumprimentou (T-478, 22/10/2026).
    public const ESPECIALISTA = 'Para que você não fique esperando, já chamei um colega da equipe, que continua com você por aqui.';

    public function __construct(private CanalWhatsApp $canal, private Contexto $contexto, private Atendimento $atendimento) {}

    /** Põe a conversa na fila, se o Pedro deve responder agora. */
    public function enfileirar(int $conversaId): ?SuportePedido
    {
        if (! config('services.suporte_pedro.ligado')) {
            return null;
        }
        // Conversa encerrada esperando a nota: a nota é agradecida aqui; outra coisa é conversa nova.
        if (($em = SuporteConversa::find($conversaId)) && app(Encerramento::class)->receber($em)) {
            return null;
        }
        // Quem consulta por dentro é atendido a qualquer hora; cliente, só no horário (acesso-interno.md).
        if (! Horario::aberto() && ! app(AcessoInterno::class)->deQuem((string) SuporteConversa::whereKey($conversaId)->value('telefone'))) {
            return null;
        }

        $esgotado = null;
        $pedido = DB::transaction(function () use ($conversaId, &$esgotado) {
            $c = SuporteConversa::whereKey($conversaId)->lockForUpdate()->first();
            // A resposta que não saiu (recusada pelo Gestor ou pela Meta) não respondeu ninguém: o cliente
            // continua esperando. Contada como "última", ela travava a fila e o Gestor mandava o menu.
            $ultima = $c ? $c->mensagens()->reorder()->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'falhou'))->orderByDesc('id')->first() : null;
            // 'gestor': conversa entregue ao comercial ou ao financeiro do Gestor — lá quem responde é gente.
            if (! $c || $c->situacao === 'gestor' || $c->comHumano() || ! $c->janelaAberta() || ! $ultima || $ultima->direcao !== 'entrada'
                || SuportePedido::where('conversa_id', $c->id)->whereIn('situacao', ['pendente', 'reservado'])->exists()) {
                return null;
            }
            if ($c->situacao === 'humano') {
                $c->update(['situacao' => 'pedro', 'humano_id' => null, 'humano_ate' => null]);
            }
            // Tentou o bastante: a conversa vai para a equipe SEM passar pela IA. O
            // registro fica como pedido (acao "especialista"), que também zera a
            // contagem da próxima vez que a conversa voltar ao Pedro.
            if ($this->esgotou($c)) {
                $esgotado = SuportePedido::create(['conversa_id' => $c->id, 'ate_mensagem_id' => $ultima->id, 'situacao' => 'respondido', 'acao' => 'especialista']);
                $c->update(['situacao' => 'humano', 'humano_id' => null, 'humano_ate' => now()->addHours(SuporteConversa::HUMANO_HORAS)]);

                return null;
            }

            return SuportePedido::create(['conversa_id' => $c->id, 'ate_mensagem_id' => $ultima->id]);
        });
        if ($esgotado) {
            $this->encaminharAoEspecialista($esgotado);
        }

        return $pedido;
    }

    /**
     * O Pedro tenta por um tempo e um número de respostas; passou disso, quem
     * continua é um especialista (Felipe, 20/10/2026: "para não ficar gastando
     * tokens de IA"). Conta desde a última vez que a conversa saiu do Pedro
     * (atendimento aberto, setor, especialista). A equipe interna fica de fora:
     * ela consulta o Pedro, não é atendimento.
     */
    private function esgotou(SuporteConversa $c): bool
    {
        $respostas = max(1, (int) config('services.suporte_pedro.limite_respostas', 6));
        $minutos = max(1, (int) config('services.suporte_pedro.limite_minutos', 30));
        $desde = (int) SuportePedido::where('conversa_id', $c->id)->where('situacao', 'respondido')->where('acao', '!=', 'responder')->max('id');
        $tentativas = SuportePedido::where('conversa_id', $c->id)->where('id', '>', $desde)->where('situacao', 'respondido')->where('acao', 'responder')
            ->where('created_at', '>=', now()->subHours(SuporteConversa::JANELA_HORAS))->orderByDesc('id')->get(['created_at']);
        // Só conta a conversa que está acontecendo: a pessoa que sumiu por mais que o limite
        // e voltou recomeça a contagem (dono em 22/10/2026, T-478: um "oi" 40 min depois foi
        // direto à equipe, sem passar pela IA, como se o Pedro tivesse tentado esse tempo todo).
        $serie = [];
        $depois = now();
        foreach ($tentativas as $t) {
            if ($t->created_at->lt($depois->copy()->subMinutes($minutos))) {
                break;
            }
            $serie[] = $t->created_at;
            $depois = $t->created_at;
        }
        $n = count($serie);
        if ($n === 0 || ($n < $respostas && end($serie)->gt(now()->subMinutes($minutos)))) {
            return false;
        }

        // Só a lista de acesso interno fica de fora; telefone de funcionário no Gestor não basta (21/10/2026).
        return ! app(AcessoInterno::class)->deQuem((string) $c->telefone);
    }

    private function encaminharAoEspecialista(SuportePedido $p): void
    {
        $c = $p->conversa->fresh();
        $resumo = SuportePedido::where('conversa_id', $c->id)->where('id', '<', $p->id)->whereNotNull('resumo')->orderByDesc('id')->value('resumo');
        // Não resolveu: vai para a fila dos atendentes, e não para o quadro do Matriz (Felipe, 21/10/2026).
        app(Transferencia::class)->transferir($c, null, 'Pedro tentou e não resolveu'.($resumo ? ': '.$resumo : ''));
        $ultimas = $c->mensagens()->reorder()->where('direcao', 'entrada')->orderByDesc('id')->limit(3)->get()
            ->map(fn (SuporteMensagem $m) => $m->transcricao ?: $m->texto)->reverse()->implode(' / ');
        // Sem IA nesta volta: a proposta de registro sai do resumo que o Pedro já tinha anotado (T-476).
        // Sem identificação não há cliente a quem o registro pertença (T-478).
        if ($c->identificada()) {
            app(BaseDoSuporte::class)->proporRegistro($c, ['titulo' => $resumo ?: 'Atendimento pelo WhatsApp', 'relato' => $ultimas]);
        }
        try {
            // Uma frase só: "vou chamar alguém" seguido de "já chamei alguém" denunciaria a montagem (T-478).
            $this->canal->enviar($c, self::ESPECIALISTA.(Horario::aberto() ? '' : Transferencia::FORA_DO_HORARIO), 'pedro', null, SuporteMensagem::whereKey($p->ate_mensagem_id)->value('wamid'));
        } catch (ValidationException $e) {
            // Fora da janela ou canal desligado: a conversa já está na fila da equipe.
            $p->update(['erro' => mb_substr((string) collect($e->errors())->flatten()->first(), 0, 255)]);
        }
    }

    /** Na abertura (ou a cada minuto): quem escreveu e ficou sem resposta entra na fila. */
    public function aguardando(): int
    {
        if (! Horario::aberto()) {
            return 0;
        }
        $n = 0;
        foreach (SuporteConversa::where('ultima_entrada_em', '>=', now()->subHours(SuporteConversa::JANELA_HORAS))->pluck('id') as $id) {
            $n += $this->enfileirar($id) ? 1 : 0;
        }

        return $n;
    }

    /** O próximo pedido para o runner da conta do Pedro. @return array{id: int, agente: string, modelo: string, prompt: string}|null */
    public function reservar(User $conta, string $maquina): ?array
    {
        if (mb_strtolower($conta->email) !== mb_strtolower((string) config('services.suporte_pedro.conta'))) {
            throw ValidationException::withMessages(['conta' => 'Esta conta não atende o suporte.']);
        }
        $p = DB::transaction(function () use ($maquina) {
            $p = SuportePedido::where('situacao', 'pendente')->orderBy('id')->lockForUpdate()->first();
            // O prompt sai agora, com tudo o que o cliente já escreveu: a resposta é a ESSAS mensagens.
            // Com o número da hora em que o pedido nasceu, "Academia Mente Ativa" + "Me chamo Jucilene"
            // (4 s depois) fizeram o Gestor recusar a resposta que já falava das duas (22/10/2026).
            $ultima = $p ? (int) SuporteMensagem::where('conversa_id', $p->conversa_id)->where('direcao', 'entrada')->max('id') : 0;
            $p?->update(['situacao' => 'reservado', 'maquina' => $maquina, 'reservado_em' => now(), 'ate_mensagem_id' => max($ultima, (int) $p->ate_mensagem_id)]);

            return $p;
        });

        if (! $p) {
            return null;
        }
        // As fotos e os áudios ANTES do prompt: a busca no Gestor grava a mídia, e o
        // prompt marca o que foi anexado.
        $midias = app(Midias::class)->paraOPedido($p->conversa);

        return ['id' => $p->id, 'agente' => (string) config('services.suporte_pedro.agente'), 'modelo' => (string) config('services.suporte_pedro.modelo'),
            'prompt' => $this->contexto->prompt($p->conversa->fresh(), array_column($midias['imagens'], 'id'), array_column($midias['videos'], 'id')),
            'imagens' => $midias['imagens'], 'audios' => $midias['audios'], 'videos' => $midias['videos']];
    }

    /**
     * A resposta da IA (ou a falha). As transcrições dos áudios, feitas no runner,
     * ficam registradas mesmo se a IA falhou.
     *
     * @param  list<array{id: int, texto: string}>  $transcricoes
     */
    public function concluir(User $conta, int $pedidoId, ?string $texto, ?string $erro, array $transcricoes = []): void
    {
        $p = SuportePedido::with('conversa')->whereKey($pedidoId)->where('situacao', 'reservado')->first();
        abort_unless($p && mb_strtolower($conta->email) === mb_strtolower((string) config('services.suporte_pedro.conta')), 404);
        foreach ($transcricoes as $t) {
            SuporteMensagem::where('conversa_id', $p->conversa_id)->whereKey((int) $t['id'])->whereIn('tipo', ['audio', 'video'])
                ->update(['transcricao' => mb_substr(trim((string) $t['texto']), 0, 4000)]);
        }
        $c = $p->conversa;
        $interno = app(AcessoInterno::class)->deQuem((string) $c->telefone);
        $bloco = $texto !== null ? Contexto::ler($texto, $interno ? 3500 : 1500) : null;
        // Quem é da casa não vai a setor nem confirma cliente (FR-A4); registra atendimento
        // só para um cliente que estava entre os citados (FR-A7). Fora disso, é resposta.
        if ($bloco && $interno) {
            $registro = null;
            if ($bloco['acao'] === 'abrir_atendimento' && ! $c->comHumano()) {
                $cliente = app(AcessoInterno::class)->clientesCitados($this->contexto->textoParaRegistrar($c))[(string) $bloco['cliente']] ?? null;
                // Pré-cliente novo, pedido e confirmado na prévia (22/10/2026): nasce no Gestor junto do atendimento.
                $novoPre = ! $cliente && $bloco['cliente'] === 'novo_pre' && ($bloco['novo_pre'] ?? null);
                $registro = $cliente || $novoPre ? $this->atendimento->registrarPorDentro($c, $interno, $cliente, $bloco) : null;
                if (! $cliente && ! $novoPre) {
                    $bloco['resposta'] .= "\n\n(Não registrei: não consegui confirmar o cliente no Gestor. Me diga o nome como está cadastrado.)";
                }
            }
            if ($registro) {
                $bloco['resposta'] .= "\n\n".$registro;
            }
            $bloco = ['acao' => 'responder', 'cliente' => null, 'sistema' => null, 'destino' => null, 'assunto_id' => null, 'roteiro' => null, 'setor' => null, 'registro' => null] + $bloco;
        }

        // Um humano entrou enquanto o Pedro pensava: a resposta dele não vai.
        if ($c->comHumano()) {
            $p->update(['situacao' => 'descartado', 'erro' => 'um atendente assumiu a conversa']);

            return;
        }
        if (! $bloco) {
            $p->update(['situacao' => 'falhou', 'erro' => mb_substr($erro ?: 'resposta fora do formato', 0, 255)]);
            $this->reserva($p);

            return;
        }
        $identificadaAntes = $c->identificada();
        if (! $interno && ($bloco['solicitante'] ?? null)) {
            // Outra pessoa no mesmo telefone não herda a função de quem escreveu antes.
            $mesma = mb_strtolower(trim((string) $c->solicitante_nome)) === mb_strtolower($bloco['solicitante']['nome']);
            $c->update(['solicitante_nome' => $bloco['solicitante']['nome'],
                'solicitante_funcao' => $bloco['solicitante']['funcao'] ?? ($mesma ? $c->solicitante_funcao : null)]);
        }
        $this->confirmarCliente($c, $bloco['cliente']);
        // Acabou de se identificar (T-478): a resposta do pedido de identificação não vai.
        // A conversa volta na hora para o Pedro com o contexto inteiro, e ele responde UMA
        // vez, já cuidando do problema que a pessoa contou — como uma pessoa faria.
        if (! $interno && ! $identificadaAntes && $c->identificada() && $bloco['acao'] === 'responder') {
            $c->update(['identificada_em' => now()]);
            $p->update(['situacao' => 'respondido', 'acao' => 'identificou', 'resumo' => $bloco['resumo']]);
            // Se a volta não entrar agora (identificou às 17:59, IA respondeu às 18:01), a
            // resposta continua segurada de propósito: a última mensagem segue sendo da pessoa,
            // e o Pedro::aguardando a retoma na abertura, já cuidando do problema. Mandar o
            // "obrigado" aqui calaria essa retomada (revisão da T-478).
            $this->enfileirar($c->id);

            return;
        }
        $resposta = $bloco['resposta'];
        // Sem o Gestor, não há setor para onde entregar: vira atendimento no quadro.
        if ($bloco['acao'] === 'encaminhar_setor' && ! $this->canal->viaGestor()) {
            $bloco['acao'] = 'abrir_atendimento';
        }
        // A trava da identificação (T-478) mora AQUI, e não só no pedido à IA: sem a
        // pessoa identificada, nada se abre, nenhum setor além do comercial recebe e nada
        // vai para a base. O que a IA quis abrir vai para um colega da equipe.
        if (! $interno && ! $c->identificada()) {
            if ($bloco['acao'] === 'abrir_atendimento' || ($bloco['acao'] === 'encaminhar_setor' && $bloco['setor'] !== 'comercial')) {
                $bloco['acao'] = 'transferir';
                $bloco['atendente'] = null;
                $bloco['resumo'] = 'sem identificação: '.($bloco['resumo'] ?: 'pediu atendimento');
            }
            $bloco['registro'] = null;
        }
        if ($bloco['acao'] === 'abrir_atendimento') {
            // Equipamento de cliente do Gestor: o atendimento nasce NO Gestor, pela máquina; o número chega em seguida.
            // Cliente do Gestor: tudo vai ao Gestor, equipamento ou sistema (Felipe, 21/10/2026).
            $roteiro = $bloco['roteiro'] ? \App\Models\SuporteRoteiro::where('ativo', true)->find($bloco['roteiro']) : null;
            if ($c->gestor_cliente_id && config('services.suporte_pedro.gestor_gravar')
                && $this->atendimento->pedirNoGestor($c, $bloco['resumo'], $bloco['assunto_id'], $bloco['atendimento'],
                    in_array($roteiro?->prioridade, ['critica', 'alta'], true) ? 'alta' : null)) {
                // O número do Gestor vai em outra mensagem, quando a máquina gravar.
            } elseif ($t = $this->atendimento->abrir($c, $this->comLancamentoNoGestor($c, $bloco), $bloco['sistema'],
                $bloco['roteiro'] ? \App\Models\SuporteRoteiro::where('ativo', true)->find($bloco['roteiro']) : null, $bloco['destino'])) {
                $resposta .= "\n\nO número do seu chamado é T-{$t->id}.";
                $this->marcarSuporteNoGestor($c);
            }
        }
        $encerrar = $bloco['acao'] === 'encerrar';
        // Para uma pessoa: a conversa vai à fila (ou a quem foi nomeado) e o cliente ouve isso na mesma mensagem.
        $transferencia = null;
        if ($bloco['acao'] === 'transferir') {
            $transferencia = app(Transferencia::class)->transferir($c, $bloco['atendente'], $bloco['resumo'] ?: 'cliente pediu um atendente');
            $resposta .= "\n\n".$transferencia['texto'];
        }
        // O registro que o Pedro propõe para a base (T-476): fica à espera de quem confirme.
        // Depois de abrir o atendimento, para a proposta já nascer ligada à tarefa.
        if ($bloco['registro'] ?? null) {
            app(BaseDoSuporte::class)->proporRegistro($c, $bloco['registro']);
        }
        try {
            $enviada = $this->canal->enviar($c, $resposta, 'pedro', null, SuporteMensagem::whereKey($p->ate_mensagem_id)->value('wamid'));
            // De onde veio a resposta (T-476): só as notas que existem na base.
            if ($fontes = app(BaseDoSuporte::class)->fontesValidas($bloco['fontes'] ?? [])) {
                $enviada->update(['fontes' => $fontes]);
            }
            $p->update(['situacao' => 'respondido', 'acao' => $bloco['acao'], 'resumo' => $bloco['resumo'], 'roteiro_id' => $bloco['roteiro']]);
            // Resolvido, segundo o cliente: encerra e pede a avaliação, na mensagem seguinte.
            if ($encerrar) {
                app(Encerramento::class)->encerrar($c->fresh(), null);
            }
            // Depois da resposta, porque entregue ao setor a conversa deixa de ser do Pedro.
            if ($bloco['acao'] === 'encaminhar_setor') {
                $this->entregarAoSetor($c, (string) $bloco['setor'], $bloco['resumo']);
            }
        } catch (ValidationException $e) {
            $p->update(['situacao' => 'falhou', 'acao' => $bloco['acao'], 'erro' => mb_substr((string) collect($e->errors())->flatten()->first(), 0, 255)]);
        }
        // O que o cliente escreveu enquanto o Pedro pensava.
        if (SuporteMensagem::where('conversa_id', $c->id)->where('direcao', 'entrada')->where('id', '>', $p->ate_mensagem_id)->exists()) {
            $this->enfileirar($c->id);
        }
    }

    /**
     * Comercial e financeiro vão para os atendentes do Gestor (Gestor:
     * docs/specs/whatsapp-pedro). Se o Gestor recusar (setor desligado), o
     * pedido vira atendimento no quadro: o cliente já ouviu que alguém continua.
     */
    private function entregarAoSetor(SuporteConversa $c, string $setor, string $resumo): void
    {
        try {
            $this->canal->pedirAoGestor($c, 'entregar', ['setor' => $setor, 'resumo' => $resumo ?: 'Cliente pediu atendimento pelo WhatsApp.']);
            $c->update(['situacao' => 'gestor', 'gestor_setor' => $setor]);
        } catch (ValidationException $e) {
            $this->atendimento->abrir($c, "[{$setor}] ".$resumo.' (o Gestor não aceitou a entrega: '.collect($e->errors())->flatten()->first().')', null);
        }
    }

    /** Suporte com atendimento aberto aqui: no Gestor a conversa fica "atendida no Matriz". */
    private function marcarSuporteNoGestor(SuporteConversa $c): void
    {
        if (! $this->canal->viaGestor() || ! $c->gestor_conversa_id || $c->gestor_setor === 'suporte') {
            return;
        }
        try {
            $this->canal->pedirAoGestor($c, 'suporte');
            $c->update(['gestor_setor' => 'suporte']);
        } catch (ValidationException) {
            // A conversa segue com o Pedro no Gestor; o atendimento já existe aqui.
        }
    }

    /** Gravação no Gestor desligada: a tarefa diz o que lançar lá (cliente e assunto sugerido). */
    private function comLancamentoNoGestor(SuporteConversa $c, array $bloco): string
    {
        if ($bloco['destino'] !== 'gestor' || ! $c->gestor_cliente_id) {
            return $bloco['resumo'];
        }
        $g = app(DiretorioGestor::class)->cliente((int) $c->gestor_cliente_id);
        $assunto = collect($g ? app(DiretorioGestor::class)->assuntosPara($g) : [])->firstWhere('id', $bloco['assunto_id']);

        return $bloco['resumo'].' — LANÇAR NO GESTOR: cliente nº '.$c->gestor_cliente_id.($g ? ' ('.($g['fantasia'] ?: $g['nome']).')' : '')
            .($assunto ? ', assunto '.$assunto['id'].' '.$assunto['nome'] : '').'.';
    }

    /** O código que a IA diz que o cliente confirmou só vale se estava entre os candidatos oferecidos. */
    private function confirmarCliente(SuporteConversa $c, ?string $codigo): void
    {
        if (! $codigo || ! array_key_exists($codigo, $this->contexto->candidatos($c))) {
            return;
        }
        [$origem, $id] = explode(':', $codigo, 2);
        $c->update($origem === 'gestor' ? ['gestor_cliente_id' => (int) $id] : ['cliente_id' => (int) $id]);
        $c->refresh();
    }

    /** Pedido que a IA não respondeu a tempo: resposta automática e atendimento aberto. */
    public function vencidos(): int
    {
        $limite = now()->subSeconds((int) config('services.suporte_pedro.reserva_segundos', 120));
        $n = 0;
        // Abertura no Gestor que ninguém pegou (máquina desligada): vira tarefa no Matriz, com o cliente avisado.
        foreach (\App\Models\SuporteGestorAbertura::with('conversa')->where('situacao', 'pendente')->where('created_at', '<', $limite)->get() as $a) {
            $this->atendimento->concluirNoGestor($a, null, 'nenhuma máquina gravou no Gestor', $this->canal);
            $n++;
        }
        foreach (SuportePedido::with('conversa')->whereIn('situacao', ['pendente', 'reservado'])->where('created_at', '<', $limite)->get() as $p) {
            $p->update(['situacao' => 'expirado', 'erro' => $p->situacao === 'pendente' ? 'nenhuma máquina pegou o pedido' : 'a IA não respondeu a tempo']);
            $this->reserva($p);
            $n++;
        }

        return $n;
    }

    /** A resposta sem IA: avisa o cliente, abre o atendimento e deixa a conversa com os humanos. */
    private function reserva(SuportePedido $p): void
    {
        $c = $p->conversa->fresh();
        // No número do Gestor, enquanto a conversa está no primeiro atendimento, a
        // reserva é dele: sem resposta do Pedro, manda o menu de sempre. Responder
        // daqui também daria duas mensagens ao cliente. Suporte já marcado é nosso.
        if ($c->comHumano()) {
            return;
        }
        if ($this->canal->viaGestor() && $c->gestor_conversa_id && $c->gestor_setor === 'aguardando_menu') {
            // Até o próximo repasse dizer que a conversa voltou a ser nossa (o cliente escolheu Suporte).
            $c->update(['situacao' => 'gestor', 'gestor_setor' => 'menu']);

            return;
        }
        $ultimas = $c->mensagens()->reorder()->where('direcao', 'entrada')->orderByDesc('id')->limit(3)->pluck('texto')->reverse()->implode(' / ');
        // Sem identificação, nada de chamado (T-478): a conversa vai para um colega da equipe.
        if (! app(AcessoInterno::class)->deQuem((string) $c->telefone) && ! $c->identificada()) {
            app(Transferencia::class)->transferir($c, null, 'Pedro sem resposta da IA, pessoa ainda sem identificação: '.mb_substr($ultimas, 0, 200));
            try {
                $this->canal->enviar($c, self::FALLBACK, 'pedro');
            } catch (ValidationException) {
                // Fora da janela ou canal desligado: a conversa já está com a equipe.
            }

            return;
        }
        $t = $this->atendimento->abrir($c, 'Pedro sem resposta da IA; o cliente escreveu: '.mb_substr($ultimas, 0, 220), null);
        if ($t) {
            app(BaseDoSuporte::class)->proporRegistro($c, ['titulo' => 'Atendimento pelo WhatsApp', 'relato' => $ultimas]);
        }
        try {
            $this->canal->enviar($c, self::FALLBACK.($t ? " O número do seu chamado é T-{$t->id}." : ''), 'pedro');
        } catch (ValidationException) {
            // Fora da janela ou canal desligado: o atendimento já está aberto para a equipe.
        }
        $c->update(['situacao' => 'humano', 'humano_id' => null, 'humano_ate' => now()->addHours(SuporteConversa::HUMANO_HORAS)]);
    }
}
