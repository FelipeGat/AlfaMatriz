<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Orcamentos\Historico;
use Illuminate\Console\Command;

/**
 * Importa as propostas já enviadas (docs/specs/orcamentos-vixcard/padrao-e-historico.md,
 * FR-H6): o levantamento em JSON e a pasta com os PDFs originais. Rodar de novo
 * não duplica.
 */
class OrcamentosImportarHistorico extends Command
{
    protected $signature = 'orcamentos:importar-historico {json : o levantamento (propostas-import.json)} {pasta : a pasta com os PDFs, pelos caminhos do levantamento} {--autor= : e-mail de quem fica como autor}';

    protected $description = 'Importa as propostas antigas como orçamentos, com o PDF original';

    public function handle(Historico $historico): int
    {
        $propostas = json_decode((string) @file_get_contents($this->argument('json')), true);
        if (! is_array($propostas)) {
            $this->error('JSON ilegível.');

            return self::FAILURE;
        }
        $autor = User::where('email', $this->option('autor') ?: config('services.suporte_pedro.conta'))->first();
        if (! $autor) {
            $this->error('Autor não encontrado.');

            return self::FAILURE;
        }
        foreach ($historico->importar($propostas, (string) $this->argument('pasta'), $autor) as $linha) {
            $this->line($linha);
        }

        return self::SUCCESS;
    }
}
