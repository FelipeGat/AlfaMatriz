<?php

namespace App\Services\Suporte;

use App\Models\Notificacao;
use App\Models\SuporteAtendimento;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\AvisoNoTelegram;
use Illuminate\Support\Str;

/**
 * A cobrança dos atendimentos do suporte (docs/specs/suporte-pedro-whatsapp/conhecimento.md;
 * Felipe, 20/10/2026: "todo atendimento crítico tem que ser passado e cobrado
 * constantemente dos técnicos"). Os técnicos de campo estão no Gestor, sem conta
 * aqui: quem recebe é o responsável da tarefa e os responsáveis do suporte, que
 * cobram os técnicos. Também cobra o nº do Gestor que ninguém informou.
 *
 * Só no horário do suporte: lembrete de madrugada no Telegram vira ruído.
 */
class Cobranca
{
    public function __construct(private Conhecimento $conhecimento, private DiretorioGestor $diretorio, private AvisoNoTelegram $telegram) {}

    public function rodar(): int
    {
        if (! Horario::aberto()) {
            return 0;
        }
        $limite = now()->subMinutes($this->conhecimento->cobrancaMinutos());
        $n = 0;
        $abertos = SuporteAtendimento::with(['tarefa', 'conversa.cliente:id,nome,nome_fantasia', 'roteiro:id,titulo'])
            ->whereHas('tarefa', fn ($q) => $q->whereNotIn('status', Tarefa::STATUS_TERMINAIS))
            ->where(fn ($q) => $q->where('prioridade', 'critica')->orWhere(fn ($w) => $w->where('destino', 'gestor')->whereNull('gestor_numero')))
            // A primeira cobrança espera um intervalo depois da abertura: o aviso de abertura já saiu.
            ->where(fn ($q) => $q->where('ultima_cobranca_em', '<', $limite)->orWhere(fn ($w) => $w->whereNull('ultima_cobranca_em')->where('created_at', '<', $limite)))
            ->get();
        foreach ($abertos as $a) {
            if ($this->resolvidoNoGestor($a)) {
                continue;
            }
            $this->cobrar($a);
            $n++;
        }

        return $n;
    }

    /** O atendimento do Gestor informado aparece concluído ou cancelado no retrato: a cobrança do crítico para. */
    private function resolvidoNoGestor(SuporteAtendimento $a): bool
    {
        $g = $a->gestor_numero ? $this->diretorio->atendimentoPorNumero($a->gestor_numero) : null;

        return $g && in_array($g['status'] ?? null, ['concluido', 'cancelado'], true);
    }

    private function cobrar(SuporteAtendimento $a): void
    {
        $t = $a->tarefa;
        $c = $a->conversa;
        $cliente = $c?->cliente ? ($c->cliente->nome_fantasia ?: $c->cliente->nome) : ($c?->nome_perfil ?: $c?->telefoneLegivel());
        $motivos = [];
        if ($a->prioridade === 'critica') {
            $etapa = $a->gestor_numero ? ($this->diretorio->atendimentoPorNumero($a->gestor_numero)['status'] ?? null) : null;
            $motivos[] = 'CRÍTICO'.($a->roteiro ? ' ('.$a->roteiro->titulo.')' : '').$this->prazo($a)
                .($etapa ? ' · Gestor nº '.$a->gestor_numero.': '.str_replace('_', ' ', $etapa) : '');
        }
        if ($a->faltaNumeroDoGestor()) {
            $motivos[] = 'falta lançar no Gestor e informar o número na conversa';
        }
        $titulo = "Cobrança T-{$t->id}: {$cliente} — ".implode('; ', $motivos);
        $rota = $c ? route('suporte.conversas', $c->id) : route('tarefas.index', ['tarefa' => $t->id]);

        $destinatarios = User::where('ativo', true)->where(fn ($q) => $q->whereIn('email', (array) config('services.suporte_pedro.responsaveis'))->when($t->responsavel_id, fn ($w) => $w->orWhere('id', $t->responsavel_id)))->get();
        foreach ($destinatarios as $u) {
            Notificacao::create(['destinatario_id' => $u->id, 'tipo' => 'direcionamento', 'nivel' => $a->prioridade === 'critica' ? 'critico' : 'atencao', 'icone' => 'balao',
                'tarefa_id' => $t->id, 'titulo' => Notificacao::caber($titulo), 'meta' => 'Suporte WhatsApp', 'rota' => $rota]);
        }
        if ($this->telegram->configurado()) {
            $this->telegram->enviar(($a->prioridade === 'critica' ? '🚨' : '⏰').' <b>'.e(Str::before($titulo, ':')).'</b>:'.e(Str::after($titulo, ':'))."\n".e($rota));
        }
        $a->forceFill(['ultima_cobranca_em' => now(), 'cobrancas' => $a->cobrancas + 1])->save();
    }

    private function prazo(SuporteAtendimento $a): string
    {
        if (! $a->prazo_em) {
            return '';
        }
        $min = (int) round(now()->diffInMinutes($a->prazo_em, false));
        $tempo = fn (int $m) => $m >= 60 ? intdiv($m, 60).' h'.($m % 60 ? ' '.($m % 60).' min' : '') : $m.' min';

        return $min >= 0 ? ' · faltam '.$tempo($min).' para o prazo' : ' · PRAZO ESTOURADO há '.$tempo(-$min);
    }
}
