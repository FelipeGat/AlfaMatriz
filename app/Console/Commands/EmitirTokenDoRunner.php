<?php

namespace App\Console\Commands;

use App\Mcp\Servers\AlfaMatrizServer;
use Illuminate\Console\Command;

/**
 * Emite (ou revoga) o token do runner da Central de IA de uma pessoa.
 *
 * O desenho do `alfa:changelog-token`: pessoal, o banco guarda só o hash, o
 * token aparece uma vez e a capacidade é `central-ia-runner` e só ela — não
 * abre o `/mcp`, o changelog nem nenhuma outra porta. O runner age como a
 * pessoa: só vê os pedidos dela.
 */
class EmitirTokenDoRunner extends Command
{
    protected $signature = 'alfa:central-ia-token
                            {email : E-mail da pessoa dona da máquina}
                            {--nome=wsl : Um nome para reconhecer o token}
                            {--revogar : Revoga os tokens de runner dessa pessoa em vez de emitir}';

    protected $description = 'Emite ou revoga o token do runner da Central de IA';

    public function handle(): int
    {
        try {
            $usuario = AlfaMatrizServer::usuarioDoProcesso($this->argument('email'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('revogar')) {
            $quantos = $usuario->tokens()->get()
                ->filter(fn ($token) => $token->abilities === ['central-ia-runner'])
                ->each->delete()
                ->count();
            $this->info("{$quantos} token(s) de runner de {$usuario->name} revogado(s).");

            return self::SUCCESS;
        }

        if (! $usuario->canPermissao('central_ia_agentes', 'incluir')) {
            $this->error("{$usuario->name} não tem o perfil \"Central de IA\" — o token não abriria nada.");

            return self::FAILURE;
        }

        $token = $usuario->createToken((string) $this->option('nome'), ['central-ia-runner'])->plainTextToken;

        $this->info("Token do runner de {$usuario->name} ({$this->option('nome')}). Ele só aparece agora:");
        $this->line($token);

        return self::SUCCESS;
    }
}
