<?php

namespace App\Console\Commands;

use App\Models\Compromisso;
use App\Models\Notificacao;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Avisa no sino quem tem compromisso perto de começar.
 *
 * Roda de poucos em poucos minutos e pega os compromissos que entram na janela
 * de `Compromisso::LEMBRETE_MINUTOS` e ainda não avisaram. O carimbo
 * `lembrete_enviado_em` é o que impede o mesmo aviso de sair a cada passada —
 * ver a migração que criou a coluna.
 *
 * Diferente do aviso de marcação (`CompromissoController::avisarParticipantes`),
 * este NÃO pula ninguém: não há "autor" de um lembrete: quem marcou a reunião
 * também quer ser lembrado dela. Por isso cria a notificação direto, e não pelo
 * `Notificacao::avisar`, que existe para não ecoar a ação de volta a quem a fez.
 */
class LembrarCompromissos extends Command
{
    protected $signature = 'agenda:lembrar-compromissos';

    protected $description = 'Avisa os participantes de compromissos que estão para começar';

    public function handle(): int
    {
        $agora = now();

        $compromissos = Compromisso::query()
            ->with('participantes:id')
            ->aLembrar($agora)
            ->get()
            // A janela grossa é por dia (o índice); aqui o corte fino pelo
            // instante: só o que começa DEPOIS de agora e dentro dos próximos
            // LEMBRETE_MINUTOS. O início mora em duas colunas, então a
            // comparação é em PHP.
            ->filter(fn (Compromisso $c) => $c->comecaEm()->gt($agora)
                && $c->comecaEm()->lte($agora->copy()->addMinutes(Compromisso::LEMBRETE_MINUTOS)));

        foreach ($compromissos as $compromisso) {
            $minutos = (int) ceil($agora->diffInMinutes($compromisso->comecaEm()));

            foreach ($compromisso->participantes as $participante) {
                Notificacao::create([
                    'destinatario_id' => $participante->id,
                    'tipo' => 'lembrete',
                    'nivel' => 'atencao',
                    'icone' => 'clock',
                    'titulo' => 'Começa às '.$compromisso->comecaEm()->format('H:i').': '.$compromisso->titulo,
                    'meta' => 'Em '.$minutos.' min · '.$compromisso->intervalo(),
                    'rota' => route('agenda.index', ['em' => Carbon::parse($compromisso->data)->toDateString()]),
                    'tarefa_id' => $compromisso->tarefa_id,
                ]);
            }

            $compromisso->update(['lembrete_enviado_em' => $agora]);

            $this->line("  #{$compromisso->id} {$compromisso->titulo}: {$compromisso->participantes->count()} avisado(s)");
        }

        $this->info($compromissos->count().' compromisso(s) lembrado(s).');

        return self::SUCCESS;
    }
}
