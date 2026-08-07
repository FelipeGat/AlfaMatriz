<?php

namespace App\Console\Commands;

use App\Models\Sistema;
use App\Services\Integracao\SincronizacaoService;
use Illuminate\Console\Command;

/**
 * Sincroniza o retrato local com os sistemas da casa.
 *
 * É o que faz a integração rodar de verdade, agora: uma execução completa de
 * madrugada e uma leve de hora em hora, agendadas no routes/console.php
 * (Q-013 decide os horários finais). O registro em sincronizacoes é o que
 * permite descobrir se a rotina parou sem ninguém perceber.
 */
class SincronizarSistemas extends Command
{
    protected $signature = 'app:sincronizar-sistemas
                            {--sistema= : Slug de um sistema específico (padrão: todos os produtos vendidos como serviço)}
                            {--escopo=completa : completa|planos|revendas|clientes|usuarios|licencas|contadores}
                            {--origem=comando : comando|manual|agendada}';

    protected $description = 'Sincroniza o retrato local com os sistemas da casa';

    public function handle(SincronizacaoService $servico): int
    {
        $sistemas = $this->option('sistema')
            ? Sistema::where('slug', $this->option('sistema'))->get()
            : Sistema::where('categoria', 'saas')->orderBy('nome')->get();

        if ($sistemas->isEmpty()) {
            $this->warn('Nenhum sistema para sincronizar.');

            return self::SUCCESS;
        }

        $falhas = 0;

        foreach ($sistemas as $sistema) {
            $execucao = $servico->sincronizar($sistema, $this->option('escopo'), $this->option('origem'));

            if ($execucao->deuCerto()) {
                $this->info(sprintf(
                    '%s: %d lidos, %d criados, %d atualizados, %d ausentes.',
                    $sistema->nome,
                    $execucao->itens_lidos,
                    $execucao->itens_criados,
                    $execucao->itens_atualizados,
                    $execucao->itens_ausentes,
                ));

                continue;
            }

            $falhas++;
            $this->error(sprintf('%s: %s', $sistema->nome, $execucao->erro_mensagem ?? $execucao->status));
        }

        return $falhas === 0 ? self::SUCCESS : self::FAILURE;
    }
}
