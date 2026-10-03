<?php

namespace App\Console\Commands;

use App\Models\Sistema;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Emite (ou revoga) o token com que o servidor de um sistema manda os erros
 * do log ao vigia (#219) — o espelho do `alfa:mcp-token`.
 *
 * Pela linha de comando, e não por tela, pelo mesmo motivo: quem emite o token
 * dá a uma máquina o poder de abrir bug no quadro, e isso é decisão de quem
 * administra o servidor. O token aparece UMA vez, aqui; o banco guarda só o
 * hash (`sistemas.vigia_token_hash`). Um por sistema: emitir de novo troca o
 * token, e o antigo para de valer na hora — é também o jeito de girá-lo.
 */
class EmitirTokenDoVigia extends Command
{
    protected $signature = 'alfa:vigia-token
                            {sistema : Slug, nome ou id do sistema (ex.: alfagym)}
                            {--revogar : Apaga o token em vez de emitir: o servidor para de conseguir enviar}';

    protected $description = 'Emite ou revoga o token do vigia de logs de um sistema';

    public function handle(): int
    {
        $sistema = self::sistema((string) $this->argument('sistema'));

        if (! $sistema) {
            $this->error("Sistema '{$this->argument('sistema')}' não encontrado. Use o slug, o nome ou o id.");

            return self::FAILURE;
        }

        if ($this->option('revogar')) {
            $sistema->forceFill(['vigia_token_hash' => null])->save();
            $this->info("Token do vigia de {$sistema->nome} revogado.");

            return self::SUCCESS;
        }

        $segredo = Str::random(48);
        $sistema->forceFill(['vigia_token_hash' => hash('sha256', $segredo)])->save();

        $this->info("Token do vigia de {$sistema->nome}. Ele só aparece agora (e o anterior, se havia, deixou de valer):");
        $this->line($sistema->id.'|'.$segredo);
        $this->newLine();
        $this->comment('Ponha em VIGIA_TOKEN no .env do deploy/vigia-logs/ do servidor desse sistema.');

        return self::SUCCESS;
    }

    /** Pelo slug, pelo nome (sem diferença de maiúsculas) ou pelo id. */
    public static function sistema(string $chave): ?Sistema
    {
        return Sistema::where('slug', $chave)->first()
            ?? Sistema::whereRaw('LOWER(nome) = ?', [mb_strtolower($chave)])->first()
            ?? (ctype_digit($chave) ? Sistema::find((int) $chave) : null);
    }
}
