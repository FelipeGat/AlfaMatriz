<?php

namespace App\Console\Commands;

use App\Mcp\Servers\AlfaMatrizServer;
use Illuminate\Console\Command;

/**
 * Emite (ou revoga) o token com que um agente fala com o servidor MCP por HTTP.
 *
 * Pela linha de comando, e não por uma tela, de propósito: quem emite um token
 * está dando a um programa o poder de agir em nome de uma pessoa, e isso é
 * decisão de quem administra o servidor, não de quem usa o painel. O token
 * aparece UMA vez, aqui — o banco guarda só o hash, como o Sanctum manda.
 *
 * A capacidade é sempre `mcp`, e só ela: a rota exige `abilities:mcp`, e um
 * token sem outra capacidade não abre nenhuma porta que um dia venha a aceitar
 * token.
 */
class EmitirTokenMcp extends Command
{
    protected $signature = 'alfa:mcp-token
                            {email : E-mail de quem o agente vai representar}
                            {--nome=agente : Um nome para reconhecer o token (ex.: lxc-dev, mac-rossini)}
                            {--revogar : Revoga todos os tokens MCP dessa pessoa em vez de emitir}';

    protected $description = 'Emite ou revoga o token do servidor MCP para uma pessoa';

    public function handle(): int
    {
        try {
            $usuario = AlfaMatrizServer::usuarioDoProcesso($this->argument('email'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('revogar')) {
            $quantos = $usuario->tokens()->delete();
            $this->info("{$quantos} token(s) de {$usuario->name} revogado(s).");

            return self::SUCCESS;
        }

        $token = $usuario->createToken((string) $this->option('nome'), ['mcp'])->plainTextToken;

        $this->info("Token MCP de {$usuario->name} ({$this->option('nome')}). Ele só aparece agora:");
        $this->line($token);
        $this->newLine();
        $this->comment('Use como cabeçalho "Authorization: Bearer <token>" na rota /mcp.');

        return self::SUCCESS;
    }
}
