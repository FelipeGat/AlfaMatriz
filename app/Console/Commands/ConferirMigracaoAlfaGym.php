<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Confere a migração do AlfaGym antes de virar a chave.
 *
 * Os dados vêm pelo sincronizador; este comando não corrige nada, só olha e
 * conta o que ficou de fora. Três coisas quebram DEPOIS da virada se passarem
 * despercebidas:
 *
 *  1. cliente sem revenda — não tem dono nem entra no faturamento de ninguém;
 *  2. cliente licenciado sem âncora de licença — renovar e suspender falham,
 *     porque as duas operações precisam do id da licença no AlfaGym;
 *  3. revenda sem acesso ao painel — a revenda migrada não consegue entrar.
 *
 * Sai 0 só quando as três listas estão vazias: serve como porteiro da virada.
 */
class ConferirMigracaoAlfaGym extends Command
{
    protected $signature = 'alfa:conferir-migracao-alfagym';

    protected $description = 'Confere o que veio do AlfaGym e lista as divergências antes de virar a chave';

    public function handle(): int
    {
        $sistema = Sistema::where('slug', 'alfagym')->first();

        if (! $sistema) {
            $this->error('Sistema AlfaGym não está cadastrado na matriz.');

            return self::FAILURE;
        }

        $semRevenda = Cliente::whereNull('revenda_id')->orderBy('nome')->pluck('nome');
        $semAncora = $this->licenciadosSemAncora($sistema);
        $semAcesso = $this->revendasSemAcesso();

        $this->recorte('Clientes sem revenda', $semRevenda,
            'Sem dono, não entram no faturamento de nenhuma revenda.');

        $this->recorte('Clientes licenciados sem âncora de licença', $semAncora,
            'Renovar e suspender falham: as duas operações precisam do id da licença no AlfaGym. Rode `php artisan alfa:sincronizar-sistemas`.');

        $this->recorte('Revendas sem acesso ao painel', $semAcesso,
            'Não conseguem entrar para cadastrar cliente. Rode `php artisan alfa:criar-acessos-revendas`.');

        $total = $semRevenda->count() + $semAncora->count() + $semAcesso->count();

        $this->newLine();

        if ($total === 0) {
            $this->info('Nenhuma divergência: a base do AlfaGym está pronta para virar a chave.');

            return self::SUCCESS;
        }

        $this->warn($total.' divergência(s) encontrada(s) — resolva antes de virar a chave.');

        return self::FAILURE;
    }

    /**
     * Cliente que tem licença no vínculo mas não guardou o id dela no AlfaGym.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function licenciadosSemAncora(Sistema $sistema): \Illuminate\Support\Collection
    {
        return Cliente::query()
            ->join('cliente_sistema', 'cliente_sistema.cliente_id', '=', 'clientes.id')
            ->where('cliente_sistema.sistema_id', $sistema->id)
            // "Licenciado" é quem tem vigência ou status de licença registrados;
            // quem está só pendente ainda não tem licença para ancorar.
            ->where(fn ($q) => $q
                ->whereNotNull('cliente_sistema.licenca_status')
                ->orWhereNotNull('cliente_sistema.licenca_fim_em'))
            ->where(fn ($q) => $q
                ->whereNull('cliente_sistema.licenca_id_externo')
                ->orWhere('cliente_sistema.licenca_id_externo', ''))
            ->orderBy('clientes.nome')
            ->pluck('clientes.nome');
    }

    /** @return \Illuminate\Support\Collection<int, string> */
    private function revendasSemAcesso(): \Illuminate\Support\Collection
    {
        $comAcesso = User::whereNotNull('revenda_id')->pluck('revenda_id')->unique();

        return Revenda::whereNotIn('id', $comAcesso)->orderBy('nome')->pluck('nome');
    }

    /** @param  \Illuminate\Support\Collection<int, string>  $itens */
    private function recorte(string $titulo, \Illuminate\Support\Collection $itens, string $porque): void
    {
        $this->newLine();

        if ($itens->isEmpty()) {
            $this->line('<info>✓</info> '.$titulo.': nenhum');

            return;
        }

        $this->warn($titulo.' ('.$itens->count().'):');
        $this->line('  '.$porque);

        foreach ($itens as $item) {
            $this->line('  · '.$item);
        }
    }
}
