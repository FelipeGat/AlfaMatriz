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
     * Cliente do Gestor com problema de equipamento (Felipe, 20/10/2026): o
     * atendimento é aberto NO Gestor, onde estão os técnicos. Quem grava é a
     * máquina, pela ponte; aqui só se anota o pedido (um por conversa em aberto).
     */
    public function pedirNoGestor(SuporteConversa $c, string $resumo, ?int $assuntoId = null): ?SuporteGestorAbertura
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
        $solicitante = $c->nome_perfil ?: 'Contato pelo WhatsApp';

        return SuporteGestorAbertura::create(['conversa_id' => $c->id, 'gestor_cliente_id' => $c->gestor_cliente_id, 'assunto_id' => $assunto['id'], 'empresa_id' => $g['empresa_id'] ?? null,
            'descricao' => Str::limit(trim($resumo) ?: 'Cliente pediu atendimento pelo WhatsApp.', 1500)."\n\n(Aberto pelo Pedro, suporte no WhatsApp — {$solicitante}, {$c->telefoneLegivel()}.)",
            'solicitante' => Str::limit($solicitante, 120, ''), 'telefone' => $c->telefone]);
    }

    /** A máquina gravou (ou não) no Gestor: avisa o cliente e a equipe; se falhou, abre no quadro do Matriz. */
    public function concluirNoGestor(SuporteGestorAbertura $a, ?string $numero, ?string $erro, CanalWhatsApp $canal): void
    {
        $c = $a->conversa;
        if ($numero) {
            $a->update(['situacao' => 'feito', 'numero' => mb_substr($numero, 0, 30)]);
            $c->update(['gestor_atendimento' => $a->numero]);
            $g = app(DiretorioGestor::class)->cliente((int) $a->gestor_cliente_id);
            $nome = $g['fantasia'] ?? $g['nome'] ?? 'cliente do Gestor';
            foreach ($this->responsaveis() as $u) {
                Notificacao::create(['destinatario_id' => $u->id, 'tipo' => 'direcionamento', 'nivel' => 'atencao', 'icone' => 'balao',
                    'titulo' => Notificacao::caber("Gestor nº {$a->numero}: {$nome} — ".Str::before($a->descricao, "\n\n")), 'meta' => 'Suporte WhatsApp', 'rota' => route('suporte.conversas', $c->id)]);
            }
            if ($this->telegram->configurado()) {
                $this->telegram->enviar('📞 <b>Gestor nº '.e($a->numero).'</b>: '.e($nome)."\n".e(Str::before($a->descricao, "\n\n"))."\n".e(route('suporte.conversas', $c->id)));
            }
            try {
                $canal->enviar($c, "Seu atendimento é o nº {$a->numero}. A equipe técnica retorna por aqui.", 'pedro');
            } catch (\Illuminate\Validation\ValidationException) {
                // Fora da janela: a equipe já foi avisada.
            }

            return;
        }
        $a->update(['situacao' => 'falhou', 'erro' => mb_substr($erro ?: 'a ponte não devolveu o número', 0, 255)]);
        if ($t = $this->abrir($c, Str::before($a->descricao, "\n\n").' (não foi possível abrir no Gestor: '.$a->erro.')', null)) {
            try {
                $canal->enviar($c, "Seu atendimento é o nº T-{$t->id}. A equipe técnica retorna por aqui.", 'pedro');
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

        $t = $this->tarefas->criar([
            'titulo' => Str::limit('Suporte: '.$nomeCliente.' — '.$resumo, 150),
            'resumo' => Str::limit($resumo.' WhatsApp '.$c->telefoneLegivel().'. Conversa: '.route('suporte.conversas', $c->id), 500),
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
