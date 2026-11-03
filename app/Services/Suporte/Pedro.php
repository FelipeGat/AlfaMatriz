<?php

namespace App\Services\Suporte;

use App\Models\SuporteConversa;
use App\Models\SuporteMensagem;
use App\Models\SuportePedido;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A fila do Pedro (docs/specs/suporte-pedro-whatsapp, etapa 2). Quem pensa é a IA
 * do runner da conta configurada (constitution II), numa vaga só do suporte e
 * sem ferramentas; o Matriz monta o contexto, confere a resposta e faz o resto.
 *
 * Um pedido aberto por conversa: o que o cliente escrever enquanto o Pedro pensa
 * vira o pedido seguinte. Sem resposta da IA a tempo (máquina desligada,
 * travada), o Matriz responde sozinho e passa a conversa a um atendente — o
 * cliente nunca fica sem resposta.
 */
class Pedro
{
    // Frases fixas no tom de uma pessoa da equipe (T-478, dono em 21/10/2026: "o mais natural possível").
    public const FALLBACK = 'Olá! Recebi a sua mensagem, e um colega da equipe responderá por aqui em instantes.';

    /** Nenhuma máquina no ar (Felipe, 23/10/2026: o PC desligado não pode prometer "um colega responderá"). Só fora do horário. */
    public const FORA_DO_AR = 'Olá! No momento estamos fora do horário de atendimento. Sua mensagem ficou registrada e retornaremos no próximo horário.';

    /**
     * Os avisos sem IA dizem a verdade a cada público (Felipe, 31/10/2026, ao ler "estamos fora do
     * horário" numa sexta às 17:19, com a máquina parada): no horário, sem máquina, o cliente não ouve
     * "fora do horário"; quem é da casa ouve que o Pedro está sem as consultas; e a falha da IA de
     * madrugada não promete um colega "em instantes".
     */
    public const SEM_MAQUINA = 'Olá! Recebi a sua mensagem e ela ficou registrada. Respondo por aqui assim que possível.';

    public const SEM_CONSULTAS = 'Estou sem acesso às consultas agora. Sua mensagem ficou registrada e respondo assim que voltar.';

    public const NAO_CONSEGUI = 'Não consegui responder agora. Me mande de novo em alguns minutos, por favor.';

    public const FALLBACK_FORA = 'Olá! Recebi a sua mensagem e ela ficou registrada. Um colega da equipe retorna por aqui no próximo horário de atendimento.';

    /**
     * O cliente do cadastro fora do horário (Felipe, 31/10/2026, 18:23: "após esse horário, informar
     * que estamos fora do horário e, se for plantão, direcionar para o número do plantão"). Sem IA e sem
     * dado nenhum. O começo é fixo: é por ele que a retomada das 08:00 reconhece o aviso.
     */
    public const FORA_DO_HORARIO = 'Olá! Nosso atendimento é de segunda a sexta, %s, e agora estamos fora do horário. Sua mensagem ficou registrada e retornamos no próximo horário de atendimento.';

    /** A mesma noite, mensagem nova: o Gestor manda o menu a quem fica 5 min sem resposta, então algo curto sai. */
    public const FORA_DO_HORARIO_DE_NOVO = 'Sua mensagem também ficou registrada; retornamos no próximo horário de atendimento.';

    // Sem "esse assunto": a frase também sai quando a pessoa só cumprimentou (T-478, 22/10/2026).
    public const ESPECIALISTA = 'Para que você não fique esperando, já chamei um colega da equipe, que continua com você por aqui.';

    /** O começo do erro de quem a máquina reservou e não respondeu: a marca do aviso já dado (T-494). */
    public const SEM_RETORNO = 'sem confirmação do Gestor';

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
        // O Pedro atende o cliente novo e a casa a qualquer hora; o cliente do cadastro, só no horário (Felipe, 31/10/2026).
        $esgotado = null;
        $foraDoHorario = null;
        $pedido = DB::transaction(function () use ($conversaId, &$esgotado, &$foraDoHorario) {
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
            // Cliente do cadastro fora do horário: o aviso fixo, sem IA. A regra é do código, e não do pedido à IA.
            if (! Horario::aberto() && app(Identificacao::class)->ehCliente($c)) {
                $foraDoHorario = $c;

                return null;
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
        if ($foraDoHorario) {
            $this->avisarForaDoHorario($foraDoHorario);
        }

        return $pedido;
    }

    /**
     * O aviso ao cliente do cadastro fora do horário. Completo, com o horário e o plantão, uma vez a
     * cada 12 h; nas mensagens seguintes, o curto. No máximo um a cada 2 min (30, se o último falhou):
     * quem manda três mensagens seguidas recebe um aviso, e o Pedro::aguardando, a cada minuto,
     * responde o que chegou no intervalo — antes dos 5 min em que o Gestor mandaria o menu.
     */
    private function avisarForaDoHorario(SuporteConversa $c): void
    {
        $ultima = $c->mensagens()->reorder()->where('direcao', 'pedro')->orderByDesc('id')->first();
        if ($ultima && $ultima->created_at->gt(now()->subMinutes($ultima->status === 'falhou' ? 30 : 2))) {
            return;
        }
        $completo = ! $c->mensagens()->reorder()->where('direcao', 'pedro')->where('texto', 'like', self::comecoDoForaDoHorario().'%')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'falhou'))->where('created_at', '>=', now()->subHours(12))->exists();
        try {
            $this->canal->enviar($c, $completo ? sprintf(self::FORA_DO_HORARIO, Horario::faixa()).$this->plantao() : self::FORA_DO_HORARIO_DE_NOVO, 'pedro');
        } catch (ValidationException) {
            // Fora da janela, canal desligado, ou o Gestor já mandou o menu: a mensagem fica registrada.
        }
    }

    /** O começo fixo do aviso de fora do horário, antes do horário configurado. */
    private static function comecoDoForaDoHorario(): string
    {
        return Str::before(self::FORA_DO_HORARIO, '%s');
    }

    /**
     * Os avisos sem IA que não respondem o que a pessoa pediu: a retomada (retomar) não os conta como
     * resposta, e o pedido à IA diz que a conversa está sendo retomada. @return list<string>
     */
    public static function avisosDeEspera(): array
    {
        return [self::FORA_DO_AR, self::SEM_MAQUINA, self::SEM_CONSULTAS, self::comecoDoForaDoHorario(), self::FORA_DO_HORARIO_DE_NOVO];
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
            $this->canal->enviar($c, self::ESPECIALISTA.(Horario::aberto() ? '' : Transferencia::foraDoHorario()), 'pedro', null, SuporteMensagem::whereKey($p->ate_mensagem_id)->value('wamid'));
        } catch (ValidationException $e) {
            // Fora da janela ou canal desligado: a conversa já está na fila da equipe.
            $p->update(['erro' => mb_substr((string) collect($e->errors())->flatten()->first(), 0, 255)]);
        }
    }

    /** A cada minuto: quem escreveu e ficou sem resposta entra na fila. */
    public function aguardando(): int
    {
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
        // Quem já se identificou em outra conversa (contato do Gestor, T-478/T-479) não precisa
        // se apresentar de novo: a conversa entra identificada antes de o prompt sair.
        if (! app(AcessoInterno::class)->deQuem((string) $p->conversa->telefone)) {
            app(ContatosDoGestor::class)->reconhecer($p->conversa);
        }

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
        // A resposta da casa sai em partes (Contexto::partes); o teto aqui só segura um texto sem fim.
        $bloco = $texto !== null ? Contexto::ler($texto, $interno ? 20000 : 1500) : null;
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
            // Recusado pela conferência: só o aviso, sem o "estou enviando ao Gestor" que a IA escreveu
            // (23/10/2026: o Wallas leu "enviando" e, embaixo, "ainda não registrei").
            if ($registro && str_starts_with($registro, 'Ainda não registrei')) {
                $bloco['resposta'] = $registro;
            } elseif ($registro) {
                $bloco['resposta'] .= "\n\n".$registro;
            }
            // O dia do técnico (pedro-dia-do-tecnico, FASE 2): a prévia é conferida e guardada; nada vai ao Gestor ainda.
            // A lista é a do sistema, com o total (bugfix de 02/11/2026); da IA fica só o que ela entendeu e perguntou.
            if ($bloco['acao'] === 'previa_agenda' && ! $c->comHumano()) {
                $bloco['resposta'] = trim(rtrim($bloco['resposta'])."\n\n".app(AgendaDoTecnico::class)->previa($c, $interno, (array) ($bloco['agenda'] ?? [])));
            }
            // O "sim" da prévia (FASE 4): a resposta é a do sistema, que sabe o que entrou na fila.
            if ($bloco['acao'] === 'confirmar_agenda' && ! $c->comHumano()) {
                $bloco['resposta'] = app(AgendaDoTecnico::class)->confirmar($c, $interno);
            }
            // O orçamento de um proprietário (pedro-orcamento): a prévia é a do sistema, com a conta; a IA só entendeu
            // o pedido. Sem o bloco, a IA está perguntando o que falta, e a resposta é a dela.
            if ($bloco['acao'] === 'previa_orcamento' && ! $c->comHumano() && ($bloco['orcamento'] ?? null)) {
                $bloco['resposta'] = app(OrcamentoDoGestor::class)->previa($c, $interno, $bloco['orcamento']);
            }
            if ($bloco['acao'] === 'confirmar_orcamento' && ! $c->comHumano()) {
                $bloco['resposta'] = app(OrcamentoDoGestor::class)->confirmar($c, $interno);
            }
            // A correção leva só o que mudou, e o sistema aplica sobre a última prévia (FR-026); a repetição
            // reenvia o mesmo pedido, com a mesma chave (FR-023).
            if ($bloco['acao'] === 'corrigir_orcamento' && ! $c->comHumano() && ($bloco['correcao'] ?? null)) {
                $bloco['resposta'] = app(OrcamentoDoGestor::class)->corrigir($c, $interno, $bloco['correcao']);
            }
            if ($bloco['acao'] === 'repetir_orcamento' && ! $c->comHumano()) {
                $bloco['resposta'] = app(OrcamentoDoGestor::class)->repetir($c, $interno);
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
        // Prévia e confirmação de agenda e de orçamento são só da casa (pedro-orcamento, FR-019): a ação que a IA
        // devolver numa conversa de cliente não vale; o texto segue o caminho de qualquer resposta (e a trava de preço).
        if (! $interno && in_array($bloco['acao'], ['previa_agenda', 'confirmar_agenda', 'previa_orcamento', 'confirmar_orcamento', 'corrigir_orcamento', 'repetir_orcamento'], true)) {
            $bloco['acao'] = 'responder';
        }
        $identificadaAntes = $c->identificada();
        $clienteAntes = ! $interno && app(Identificacao::class)->ehCliente($c);
        if (! $interno && ($bloco['solicitante'] ?? null)) {
            // Outra pessoa no mesmo telefone não herda a função de quem escreveu antes.
            $mesma = mb_strtolower(trim((string) $c->solicitante_nome)) === mb_strtolower($bloco['solicitante']['nome']);
            $c->update(['solicitante_nome' => $bloco['solicitante']['nome'],
                'solicitante_funcao' => $bloco['solicitante']['funcao'] ?? ($mesma ? $c->solicitante_funcao : null)]);
        }
        $this->confirmarCliente($c, $bloco['cliente']);
        // A pessoa identificada vira contato do cliente no Gestor (T-479), pela máquina.
        if (! $interno) {
            app(ContatosDoGestor::class)->registrar($c);
        }
        // Fora do horário, quem acabou de se confirmar cliente que já existe (Felipe, 31/10/2026): a resposta
        // da IA não sai e nada se abre — vale o aviso do cliente, como se ele tivesse escrito do cadastro.
        if (! $interno && ! $clienteAntes && ! Horario::aberto() && app(Identificacao::class)->ehCliente($c->fresh())) {
            if ($c->identificada()) {
                $c->update(['identificada_em' => $c->identificada_em ?? now()]);
            }
            $p->update(['situacao' => 'respondido', 'acao' => 'fora_do_horario', 'resumo' => $bloco['resumo']]);
            $this->avisarForaDoHorario($c->fresh());

            return;
        }
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
        // Preço só pelo comercial (pedro-comunicacao, FR-020): valor em dinheiro não sai para quem não é da casa.
        $semValor = false;
        if (! $interno) {
            [$resposta, $semValor] = Contexto::semPrecoParaCliente($resposta);
        }
        // Sem o Gestor, não há setor para onde entregar: vira pedido de atendimento.
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
            $semGestor = match (true) {
                ! $c->gestor_cliente_id => 'cliente sem cadastro no Gestor',
                ! config('services.suporte_pedro.gestor_gravar') => 'gravação no Gestor desligada',
                default => null,
            };
            // O número do Gestor vai em outra mensagem, quando a máquina gravar.
            $semGestor ??= $this->atendimento->pedirNoGestor($c, $bloco['resumo'], $bloco['assunto_id'], $bloco['atendimento'],
                in_array($roteiro?->prioridade, ['critica', 'alta'], true) ? 'alta' : null) ? null : 'nenhum assunto do Gestor para o cliente';
            // Sem o Gestor, nada de tarefa no quadro (T-488): um atendente da Invest lança lá.
            if ($semGestor) {
                $urgente = $roteiro?->prioridade === 'critica' ? 'CRÍTICO ('.$roteiro->titulo.'); ' : '';
                $resposta .= "\n\n".$this->atendimento->paraAtendente($c, $urgente.$semGestor, $bloco['resumo'], $bloco['assunto_id'])['texto'];
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
            // Quem é da casa pode receber a resposta em várias mensagens (pedro-comunicacao, FR-012); o cliente, numa só.
            $partes = $interno ? Contexto::partes($resposta) : [$resposta];
            $enviada = $this->canal->enviar($c, array_shift($partes), 'pedro', null, SuporteMensagem::whereKey($p->ate_mensagem_id)->value('wamid'));
            $erroDasPartes = null;
            foreach ($partes as $parte) {
                try {
                    $this->canal->enviar($c, $parte, 'pedro');
                } catch (ValidationException $e) {
                    // A primeira já saiu: o pedido está respondido; o que não saiu fica anotado e as seguintes param.
                    $erroDasPartes = mb_substr('parte recusada: '.collect($e->errors())->flatten()->first(), 0, 255);
                    break;
                }
            }
            // De onde veio a resposta (T-476): só as notas que existem na base.
            if ($fontes = app(BaseDoSuporte::class)->fontesValidas($bloco['fontes'] ?? [])) {
                $enviada->update(['fontes' => $fontes]);
            }
            $p->update(['situacao' => 'respondido', 'acao' => $bloco['acao'], 'resumo' => $bloco['resumo'], 'roteiro_id' => $bloco['roteiro'],
                'erro' => $erroDasPartes ?? ($semValor ? 'valor em dinheiro retirado da resposta ao cliente' : null)]);
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
     * docs/specs/whatsapp-pedro). Se o Gestor recusar (setor desligado), a
     * conversa vai a um atendente (T-488): o cliente já ouviu que alguém continua.
     */
    private function entregarAoSetor(SuporteConversa $c, string $setor, string $resumo): void
    {
        try {
            $this->canal->pedirAoGestor($c, 'entregar', ['setor' => $setor, 'resumo' => $resumo ?: 'Cliente pediu atendimento pelo WhatsApp.']);
            $c->update(['situacao' => 'gestor', 'gestor_setor' => $setor]);
        } catch (ValidationException $e) {
            $this->atendimento->paraAtendente($c, "o Gestor não aceitou a entrega ao {$setor}: ".collect($e->errors())->flatten()->first(), "[{$setor}] ".$resumo);
        }
    }

    /** Suporte que ficou com um atendente daqui: no Gestor a conversa fica "atendida no Matriz". */
    private function marcarSuporteNoGestor(SuporteConversa $c): void
    {
        if (! $this->canal->viaGestor() || ! $c->gestor_conversa_id || $c->gestor_setor === 'suporte') {
            return;
        }
        try {
            $this->canal->pedirAoGestor($c, 'suporte');
            $c->update(['gestor_setor' => 'suporte']);
        } catch (ValidationException) {
            // A conversa segue no Gestor; o atendente daqui já foi chamado.
        }
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

    /** Pedido que a IA não respondeu a tempo: resposta automática e a conversa com um atendente. */
    public function vencidos(): int
    {
        $limite = now()->subSeconds((int) config('services.suporte_pedro.reserva_segundos', 120));
        $n = 0;
        // Abertura no Gestor que ninguém pegou (máquina desligada): vai a um atendente, com o cliente avisado.
        // A falha só vale se ela AINDA está pendente na hora de gravar (T-494, 23/10/2026: o Pedro disse ao
        // Wallas "lance à mão" e a máquina gravou o nº 824 depois). A máquina reserva com trava; sem a
        // condição, esta volta sobrescrevia a reserva e o aviso saía para o que ainda ia ser gravado.
        foreach (\App\Models\SuporteGestorAbertura::with('conversa')->where('situacao', 'pendente')->where('created_at', '<', $limite)->get() as $a) {
            if (! $this->desistir($a, 'nenhuma máquina gravou no Gestor')) {
                continue;
            }
            $this->atendimento->concluirNoGestor($a, null, $a->erro, $this->canal);
            $n++;
        }
        // Mudança de agenda que nenhuma máquina pegou (pedro-dia-do-tecnico): falha, e quem pediu recebe o resumo.
        // Lote em andamento não: a máquina grava 5 por volta, e as últimas de uma semana inteira esperam mais que o
        // prazo na fila com ela trabalhando (bugfix de 02/11/2026). Vale o que ela fez do lote no prazo.
        // Andando = a máquina reservou ou respondeu algo do lote no prazo (reservado_em só ela preenche: a falha daqui não conta).
        $andando = \App\Models\SuporteGestorAgenda::whereNotNull('reservado_em')->where('updated_at', '>=', $limite)->distinct()->pluck('lote')->all();
        foreach (\App\Models\SuporteGestorAgenda::where('situacao', 'pendente')->where('created_at', '<', $limite)->whereNotIn('lote', $andando)->get() as $m) {
            if (! $this->desistir($m, 'nenhuma máquina gravou no Gestor')) {
                continue;
            }
            app(AgendaDoTecnico::class)->concluirLote($m->lote, $this->canal);
            $n++;
        }
        // Orçamento confirmado que nenhuma máquina pegou (pedro-orcamento): falha, e quem pediu fica sabendo.
        // updated_at, e não created_at: o "tentar de novo" põe a mesma linha de volta na fila (FR-023).
        foreach (\App\Models\SuporteGestorOrcamento::with('conversa')->where('situacao', 'pendente')->where('updated_at', '<', $limite)->get() as $o) {
            if (! $this->desistir($o, 'nenhuma máquina gravou no Gestor')) {
                continue;
            }
            app(OrcamentoDoGestor::class)->concluir($o, $this->canal);
            $n++;
        }
        $n += $this->semRetornoDoGestor();
        foreach (SuportePedido::with('conversa')->whereIn('situacao', ['pendente', 'reservado'])->where('created_at', '<', $limite)->get() as $p) {
            $semMaquina = $p->situacao === 'pendente';
            $p->update(['situacao' => 'expirado', 'erro' => $semMaquina ? 'nenhuma máquina pegou o pedido' : 'a IA não respondeu a tempo']);
            $this->reserva($p, $semMaquina);
            $n++;
        }

        return $n;
    }

    /**
     * Pendente → falhou só se ninguém reservou no meio (T-494): a atualização é condicional, e quem
     * perde a corrida para a máquina não avisa nada — o resultado dela é que vale.
     */
    private function desistir(\Illuminate\Database\Eloquent\Model $pedido, string $erro): bool
    {
        if (! $pedido->newQuery()->whereKey($pedido->getKey())->where('situacao', 'pendente')->update(['situacao' => 'falhou', 'erro' => $erro, 'updated_at' => now()])) {
            return false;
        }
        $pedido->refresh();

        return true;
    }

    /**
     * Reservado pela máquina e sem resposta (T-494): a ponte travou no meio, e o pedido PODE ter sido
     * gravado — ou pode ser gravado ainda, quando ela destravar. Por isso não vira falha nem "lance à
     * mão": quem pediu ouve, uma vez, que o Gestor não confirmou e que é preciso conferir lá antes de
     * lançar. Continua reservado, e o resultado que chegar depois ainda vale (com aviso de duplicidade).
     */
    private function semRetornoDoGestor(): int
    {
        $limite = now()->subMinutes(max(1, (int) config('services.suporte_pedro.gravacao_sem_retorno_minutos', 10)));
        $n = 0;
        foreach (\App\Models\SuporteGestorAbertura::with('conversa')->where('situacao', 'reservado')->whereNull('erro')->where('reservado_em', '<', $limite)->get() as $a) {
            if ($this->marcarSemRetorno($a)) {
                $this->atendimento->semRetornoDoGestor($a, $this->canal);
                $n++;
            }
        }
        foreach (\App\Models\SuporteGestorOrcamento::with('conversa')->where('situacao', 'reservado')->whereNull('erro')->where('reservado_em', '<', $limite)->get() as $o) {
            if ($this->marcarSemRetorno($o)) {
                app(OrcamentoDoGestor::class)->semRetorno($o, $this->canal);
                $n++;
            }
        }
        foreach (\App\Models\SuporteGestorAgenda::with('conversa')->where('situacao', 'reservado')->whereNull('erro')->where('reservado_em', '<', $limite)->get()->groupBy('lote') as $mudancas) {
            $marcadas = $mudancas->filter(fn ($m) => $this->marcarSemRetorno($m));
            if ($marcadas->isEmpty()) {
                continue;
            }
            $desde = $marcadas->min('reservado_em')->setTimezone(config('app.timezone'))->format('H:i');
            try {
                $this->canal->enviar($marcadas->first()->conversa, "A agenda do Gestor ainda não confirmou {$marcadas->count()} mudança(s) que comecei a gravar às {$desde}. "
                    .'Elas podem já estar lá: confira a agenda no Gestor antes de lançar à mão. Quando a confirmação chegar, eu aviso aqui.', 'pedro');
            } catch (ValidationException) {
                // Fora da janela: a situação fica registrada na conversa (O QUE FOI ENVIADO AO GESTOR).
            }
            $n++;
        }

        return $n;
    }

    /** Marca o pedido reservado como "sem retorno", uma vez só (o erro preenchido é a marca; a situação fica). */
    private function marcarSemRetorno(\Illuminate\Database\Eloquent\Model $pedido): bool
    {
        $desde = $pedido->reservado_em->setTimezone(config('app.timezone'))->format('H:i');
        if (! $pedido->newQuery()->whereKey($pedido->getKey())->where('situacao', 'reservado')->whereNull('erro')
            ->update(['erro' => self::SEM_RETORNO." desde {$desde}", 'updated_at' => now()])) {
            return false;
        }
        $pedido->refresh();

        return true;
    }

    /** A resposta sem IA: avisa o cliente e deixa a conversa com um atendente, com o que lançar no Gestor. */
    private function reserva(SuportePedido $p, bool $semMaquina = false): void
    {
        $c = $p->conversa->fresh();
        // No número do Gestor, enquanto a conversa está no primeiro atendimento, a
        // reserva é dele: sem resposta do Pedro, manda o menu de sempre. Responder
        // daqui também daria duas mensagens ao cliente. Suporte já marcado é nosso.
        if ($c->comHumano()) {
            return;
        }
        // Sem máquina nenhuma (Felipe, 23/10/2026: o PC desligado): o aviso é de fora do horário,
        // com o plantão, e a conversa FICA com o Pedro, que a retoma quando voltar (retomar()). Também
        // no primeiro atendimento do Gestor: a resposta confirmada segura o menu de lá. Uma vez a cada 3 h.
        $interno = app(AcessoInterno::class)->deQuem((string) $c->telefone);
        if ($semMaquina) {
            $aviso = match (true) {
                $interno !== null => self::SEM_CONSULTAS,
                Horario::aberto() => self::SEM_MAQUINA.$this->plantao(),
                default => $this->foraDoAr(),
            };
            if (! $c->mensagens()->reorder()->where('direcao', 'pedro')->where('texto', $aviso)->where('enviada_em', '>=', now()->subHours(3))->exists()) {
                try {
                    $this->canal->enviar($c, $aviso, 'pedro');
                } catch (ValidationException) {
                    // Fora da janela, canal desligado, ou o Gestor já mandou o menu.
                }
            }

            return;
        }
        // A IA falhou com quem é da casa: ele consulta o Pedro, não é atendimento — nada vai à fila da equipe.
        if ($interno) {
            try {
                $this->canal->enviar($c, self::NAO_CONSEGUI, 'pedro');
            } catch (ValidationException) {
                // Fora da janela ou canal desligado.
            }

            return;
        }
        $aviso = Horario::aberto() ? self::FALLBACK : self::FALLBACK_FORA.$this->plantao();
        if ($this->canal->viaGestor() && $c->gestor_conversa_id && $c->gestor_setor === 'aguardando_menu') {
            // Até o próximo repasse dizer que a conversa voltou a ser nossa (o cliente escolheu Suporte).
            $c->update(['situacao' => 'gestor', 'gestor_setor' => 'menu']);

            return;
        }
        $ultimas = $c->mensagens()->reorder()->where('direcao', 'entrada')->orderByDesc('id')->limit(3)->pluck('texto')->reverse()->implode(' / ');
        // Sem identificação, nada de chamado (T-478): a conversa vai para um colega da equipe.
        if (! $c->identificada()) {
            app(Transferencia::class)->transferir($c, null, 'Pedro sem resposta da IA, pessoa ainda sem identificação: '.mb_substr($ultimas, 0, 200));
            try {
                $this->canal->enviar($c, $aviso, 'pedro');
            } catch (ValidationException) {
                // Fora da janela ou canal desligado: a conversa já está com a equipe.
            }

            return;
        }
        // Nada de tarefa no quadro nem de número T-N (T-487, O GATOSO: foi o que este caminho fez).
        $this->atendimento->paraAtendente($c, 'Pedro sem resposta da IA', 'O cliente escreveu: '.mb_substr($ultimas, 0, 220));
        if ($c->identificada()) {
            app(BaseDoSuporte::class)->proporRegistro($c, ['titulo' => 'Atendimento pelo WhatsApp', 'relato' => $ultimas]);
        }
        try {
            $this->canal->enviar($c, $aviso, 'pedro');
        } catch (ValidationException) {
            // Fora da janela ou canal desligado: a conversa já está com a equipe.
        }
    }

    /**
     * O Pedro de volta ao ar dá andamento ao que ficou sem resposta (Felipe, 23/10/2026: "sempre que
     * o Pedro ficar on, ele tem que avaliar as conversas e dar andamento"). Conversa do Pedro em que a
     * última mensagem do cliente não teve resposta — os avisos sem IA não contam —, na janela de 24 h.
     * O cliente do cadastro, só no horário: é assim que às 08:00 o Pedro retoma o que ficou da noite
     * (Felipe, 31/10/2026). Uma retomada por conversa a cada 30 min: máquina que aparece e não pega
     * pedido não vira enxurrada.
     */
    public function retomar(): int
    {
        $conta = User::where('email', (string) config('services.suporte_pedro.conta'))->first();
        if (! config('services.suporte_pedro.ligado') || ! $conta
            || ! \App\Models\CentralIaRunner::where('user_id', $conta->id)->where('visto_em', '>=', now()->subMinutes(2))->exists()) {
            return 0;
        }
        $n = 0;
        foreach (SuporteConversa::where('situacao', 'pedro')->where('ultima_entrada_em', '>=', now()->subHours(SuporteConversa::JANELA_HORAS))->get() as $c) {
            if ($c->comHumano() || ! $c->janelaAberta() || (! Horario::aberto() && app(Identificacao::class)->ehCliente($c))) {
                continue;
            }
            $entrada = (int) $c->mensagens()->reorder()->where('direcao', 'entrada')->max('id');
            $respondida = $c->mensagens()->reorder()->whereIn('direcao', ['pedro', 'humano_app', 'humano_matriz'])->where('id', '>', $entrada)
                ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'falhou'));
            foreach (self::avisosDeEspera() as $aviso) {
                $respondida->where('texto', 'not like', $aviso.'%');
            }
            $respondida = $respondida->exists();
            if (! $entrada || $respondida || SuportePedido::where('conversa_id', $c->id)->where(fn ($q) => $q->whereIn('situacao', ['pendente', 'reservado'])
                ->orWhere(fn ($w) => $w->where('ate_mensagem_id', $entrada)->where('created_at', '>=', now()->subMinutes(30))))->exists()) {
                continue;
            }
            SuportePedido::create(['conversa_id' => $c->id, 'ate_mensagem_id' => $entrada]);
            $n++;
        }

        return $n;
    }

    /** O aviso de fora do ar, com o plantão. */
    public function foraDoAr(): string
    {
        return self::FORA_DO_AR.$this->plantao();
    }

    /** O plantão dos contatos da empresa (Suporte › Pedro), para acrescentar a um aviso; sem plantão cadastrado, nada. */
    private function plantao(): string
    {
        $linha = collect(preg_split('/\R/', app(Conhecimento::class)->ajuste('contatos')))->first(fn ($l) => str_contains(mb_strtolower($l), 'plant'));
        $telefone = $linha && preg_match('/\(?\d{2}\)?\s*\d{4,5}-?\d{4}/', $linha, $m) ? $m[0] : null;

        return $telefone ? " Em caso de urgência ou emergência, ligue para o nosso plantão: {$telefone}." : '';
    }
}
