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
    public const FALLBACK = 'Recebemos sua mensagem. Um atendente da equipe vai te responder por aqui em instantes.';

    public function __construct(private CanalWhatsApp $canal, private Contexto $contexto, private Atendimento $atendimento) {}

    /** Põe a conversa na fila, se o Pedro deve responder agora. */
    public function enfileirar(int $conversaId): ?SuportePedido
    {
        if (! config('services.suporte_pedro.ligado') || ! Horario::aberto()) {
            return null;
        }

        return DB::transaction(function () use ($conversaId) {
            $c = SuporteConversa::whereKey($conversaId)->lockForUpdate()->first();
            $ultima = $c ? $c->mensagens()->reorder()->orderByDesc('id')->first() : null;
            // 'gestor': conversa entregue ao comercial ou ao financeiro do Gestor — lá quem responde é gente.
            if (! $c || $c->situacao === 'gestor' || $c->comHumano() || ! $c->janelaAberta() || ! $ultima || $ultima->direcao !== 'entrada'
                || SuportePedido::where('conversa_id', $c->id)->whereIn('situacao', ['pendente', 'reservado'])->exists()) {
                return null;
            }
            if ($c->situacao === 'humano') {
                $c->update(['situacao' => 'pedro', 'humano_id' => null, 'humano_ate' => null]);
            }

            return SuportePedido::create(['conversa_id' => $c->id, 'ate_mensagem_id' => $ultima->id]);
        });
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
            $p?->update(['situacao' => 'reservado', 'maquina' => $maquina, 'reservado_em' => now()]);

            return $p;
        });

        if (! $p) {
            return null;
        }
        // As fotos e os áudios ANTES do prompt: a busca no Gestor grava a mídia, e o
        // prompt marca o que foi anexado.
        $midias = app(Midias::class)->paraOPedido($p->conversa);

        return ['id' => $p->id, 'agente' => (string) config('services.suporte_pedro.agente'), 'modelo' => (string) config('services.suporte_pedro.modelo'),
            'prompt' => $this->contexto->prompt($p->conversa->fresh(), array_column($midias['imagens'], 'id')),
            'imagens' => $midias['imagens'], 'audios' => $midias['audios']];
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
            SuporteMensagem::where('conversa_id', $p->conversa_id)->whereKey((int) $t['id'])->where('tipo', 'audio')
                ->update(['transcricao' => mb_substr(trim((string) $t['texto']), 0, 4000)]);
        }
        $c = $p->conversa;
        $bloco = $texto !== null ? Contexto::ler($texto) : null;

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
        $this->confirmarCliente($c, $bloco['cliente']);
        $resposta = $bloco['resposta'];
        // Sem o Gestor, não há setor para onde entregar: vira atendimento no quadro.
        if ($bloco['acao'] === 'encaminhar_setor' && ! $this->canal->viaGestor()) {
            $bloco['acao'] = 'abrir_atendimento';
        }
        if ($bloco['acao'] === 'abrir_atendimento') {
            // Equipamento de cliente do Gestor: o atendimento nasce NO Gestor, pela máquina; o número chega em seguida.
            if ($bloco['destino'] === 'gestor' && $c->gestor_cliente_id && config('services.suporte_pedro.gestor_gravar')
                && $this->atendimento->pedirNoGestor($c, $bloco['resumo'], $bloco['assunto_id'])) {
                // O número do Gestor vai em outra mensagem, quando a máquina gravar.
            } elseif ($t = $this->atendimento->abrir($c, $this->comLancamentoNoGestor($c, $bloco), $bloco['sistema'],
                $bloco['roteiro'] ? \App\Models\SuporteRoteiro::where('ativo', true)->find($bloco['roteiro']) : null, $bloco['destino'])) {
                $resposta .= "\n\nSeu atendimento é o nº T-{$t->id}.";
                $this->marcarSuporteNoGestor($c);
            }
        }
        try {
            $this->canal->enviar($c, $resposta, 'pedro', null, SuporteMensagem::whereKey($p->ate_mensagem_id)->value('wamid'));
            $p->update(['situacao' => 'respondido', 'acao' => $bloco['acao'], 'resumo' => $bloco['resumo'], 'roteiro_id' => $bloco['roteiro']]);
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
        $t = $this->atendimento->abrir($c, 'Pedro sem resposta da IA; o cliente escreveu: '.mb_substr($ultimas, 0, 220), null);
        try {
            $this->canal->enviar($c, self::FALLBACK.($t ? " Seu atendimento é o nº T-{$t->id}." : ''), 'pedro');
        } catch (ValidationException) {
            // Fora da janela ou canal desligado: o atendimento já está aberto para a equipe.
        }
        $c->update(['situacao' => 'humano', 'humano_id' => null, 'humano_ate' => now()->addHours(SuporteConversa::HUMANO_HORAS)]);
    }
}
