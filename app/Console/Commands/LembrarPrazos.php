<?php

namespace App\Console\Commands;

use App\Models\Notificacao;
use App\Models\Tarefa;
use Illuminate\Console\Command;

/**
 * Avisa no sino, na manhã do dia, quem tem tarefa vencendo hoje.
 *
 * Prazo é um DIA, sem hora — então não dá para lembrar "30 min antes" como o
 * compromisso. O aviso é de manhã, no dia do vencimento, e responde a pergunta
 * "o que preciso entregar hoje". Vai só para o RESPONSÁVEL: o prazo é o
 * compromisso dele com a data.
 *
 * Agrupa por pessoa num aviso só: quem tem três tarefas vencendo hoje recebe
 * uma linha ("Vencem hoje: 3 tarefas"), e não três — três avisos idênticos no
 * mesmo minuto seriam ruído, não informação.
 *
 * Roda uma vez por dia (ver o agendamento em `routes/console.php`), então não
 * precisa de marca de deduplicação como o lembrete de compromisso: a própria
 * cadência garante o "uma vez".
 */
class LembrarPrazos extends Command
{
    protected $signature = 'agenda:lembrar-prazos';

    protected $description = 'Avisa os responsáveis por tarefas que vencem hoje';

    public function handle(): int
    {
        $hoje = now()->startOfDay();

        // Tarefa encerrada não vence: o prazo dela virou história, como na
        // Agenda. Sem responsável não há a quem avisar.
        $porResponsavel = Tarefa::query()
            ->whereNotNull('prazo')
            ->whereNotNull('responsavel_id')
            ->whereDate('prazo', $hoje->toDateString())
            ->whereNotIn('status', Tarefa::STATUS_TERMINAIS)
            ->get()
            ->groupBy('responsavel_id');

        foreach ($porResponsavel as $responsavelId => $tarefas) {
            $titulo = $tarefas->count() === 1
                ? 'Vence hoje: '.$tarefas->first()->titulo
                : 'Vencem hoje: '.$tarefas->count().' tarefas';

            Notificacao::create([
                'destinatario_id' => $responsavelId,
                'tipo' => 'lembrete',
                'nivel' => 'atencao',
                'icone' => 'clock',
                'titulo' => $titulo,
                // Os títulos numa linha só: no card do sino o meta é truncado, e
                // a lista inteira mora na Agenda, para onde a rota leva.
                'meta' => $tarefas->pluck('titulo')->implode(' · '),
                'rota' => route('agenda.index', ['visao' => 'lista']),
            ]);

            $this->line("  responsável #{$responsavelId}: {$tarefas->count()} tarefa(s)");
        }

        $this->info($porResponsavel->count().' responsável(is) avisado(s).');

        return self::SUCCESS;
    }
}
