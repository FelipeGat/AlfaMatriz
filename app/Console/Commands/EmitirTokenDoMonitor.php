<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Emite (ou revoga) o token com que o AlfaMonitor manda os alertas ao Matriz
 * (#394) — o espelho do `alfa:vigia-token`, pelos mesmos motivos: pela linha
 * de comando, aparece UMA vez, o banco guarda só o hash
 * (`sistemas.monitor_token_hash`) e emitir de novo gira o token.
 *
 * O sistema é o que MANDA (o AlfaMonitor), e não o monitorado.
 */
class EmitirTokenDoMonitor extends Command
{
    protected $signature = 'alfa:monitor-token
                            {sistema : Slug, nome ou id do sistema que manda os alertas (ex.: alfamonitor)}
                            {--revogar : Apaga o token em vez de emitir: o monitor para de conseguir enviar}';

    protected $description = 'Emite ou revoga o token do webhook do AlfaMonitor';

    public function handle(): int
    {
        $sistema = EmitirTokenDoVigia::sistema((string) $this->argument('sistema'));

        if (! $sistema) {
            $this->error("Sistema '{$this->argument('sistema')}' não encontrado. Use o slug, o nome ou o id.");

            return self::FAILURE;
        }

        if ($this->option('revogar')) {
            $sistema->forceFill(['monitor_token_hash' => null])->save();
            $this->info("Token do monitor de {$sistema->nome} revogado.");

            return self::SUCCESS;
        }

        $segredo = Str::random(48);
        $sistema->forceFill(['monitor_token_hash' => hash('sha256', $segredo)])->save();

        $this->info("Token do monitor de {$sistema->nome}. Ele só aparece agora (e o anterior, se havia, deixou de valer):");
        $this->line($sistema->id.'|'.$segredo);
        $this->newLine();
        $this->comment('Cadastre no webhook "AlfaMatriz" do AlfaMonitor (cabeçalho X-AlfaMonitor-Token), sem passar por chat.');

        return self::SUCCESS;
    }
}
