<?php

namespace App\Console\Commands;

use App\Models\Tarefa;
use App\Services\FluxoTarefaService;
use Illuminate\Console\Command;

/**
 * O reflexo do portão do staging no quadro de tarefas.
 *
 * Quando o `deploy-staging-alfamatriz.sh` reprova no portão, o staging fica na
 * versão anterior — e cada card em Em staging passa a mentir: ele diz que o
 * código está lá, e o código nunca chegou. O deploy chama este comando para o
 * quadro contar a verdade sozinho, sem depender de alguém lembrar da
 * convenção: `reprovou` bloqueia as tarefas da coluna com o motivo padrão (e
 * o bloqueio avisa no sino, como qualquer bloqueio); `passou` destrava só o
 * que este comando travou — bloqueio de gente é de gente, e só gente solta.
 */
class PortaoDoStaging extends Command
{
    /**
     * A assinatura do bloqueio automático. O `passou` procura por ELA para
     * destravar: como nenhuma rota edita motivo de bloqueio depois de criado,
     * o texto separa com segurança o que o portão travou do que uma pessoa
     * travou.
     */
    public const MOTIVO = 'Portão do staging reprovou — o código desta etapa não chegou ao staging.';

    protected $signature = 'alfa:portao-staging {veredito : reprovou ou passou}';

    protected $description = 'Reflete o veredito do portão do staging nas tarefas em Em staging';

    public function handle(FluxoTarefaService $fluxo): int
    {
        $veredito = $this->argument('veredito');

        if (! in_array($veredito, ['reprovou', 'passou'], true)) {
            $this->error("Veredito desconhecido: {$veredito}. Use 'reprovou' ou 'passou'.");

            return self::FAILURE;
        }

        return $veredito === 'reprovou'
            ? $this->bloquearAColuna($fluxo)
            : $this->destravarOQueOPortaoTravou($fluxo);
    }

    private function bloquearAColuna(FluxoTarefaService $fluxo): int
    {
        // Só as de desenvolvimento: uma operacional encalhada num portão (o
        // caso de emergência da troca de tipo) não tem código esperando deploy,
        // e o motivo do portão seria mentira nela. E a já bloqueada fica como
        // está — o motivo dela é de gente, e vale mais que o do robô.
        $tarefas = Tarefa::where('status', 'em_staging')
            ->where('tipo', 'desenvolvimento')
            ->whereNull('bloqueado_em')
            ->get();

        foreach ($tarefas as $tarefa) {
            $fluxo->bloquear($tarefa, self::MOTIVO);
        }

        $this->info($tarefas->count().' tarefa(s) em Em staging bloqueada(s) pelo portão.');

        return self::SUCCESS;
    }

    private function destravarOQueOPortaoTravou(FluxoTarefaService $fluxo): int
    {
        $tarefas = Tarefa::where('bloqueio_motivo', self::MOTIVO)->get();

        foreach ($tarefas as $tarefa) {
            $fluxo->destravar($tarefa);
        }

        $this->info($tarefas->count().' tarefa(s) destravada(s) — o portão passou.');

        return self::SUCCESS;
    }
}
