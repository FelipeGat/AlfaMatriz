<?php

namespace App\Console\Commands;

use App\Mcp\Servers\AlfaMatrizServer;
use Illuminate\Console\Command;

/**
 * Emite (ou revoga) o token com que o `deploy/publicar-changelog.sh` registra
 * o changelog no AlfaMatriz (#225).
 *
 * O desenho do `alfa:mcp-token`: pela linha de comando, pessoal (o changelog
 * tem autor), o banco guarda só o hash e o token aparece uma vez. A capacidade
 * é `changelog` e só ela — a rota exige `abilities:changelog`, e o mesmo
 * token não abre o `/mcp` nem nenhuma outra porta.
 */
class EmitirTokenDeChangelog extends Command
{
    protected $signature = 'alfa:changelog-token
                            {email : E-mail de quem publica os changelogs}
                            {--nome=mac : Um nome para reconhecer o token}
                            {--revogar : Revoga os tokens de changelog dessa pessoa em vez de emitir}';

    protected $description = 'Emite ou revoga o token com que o publicar-changelog.sh registra o changelog';

    public function handle(): int
    {
        try {
            $usuario = AlfaMatrizServer::usuarioDoProcesso($this->argument('email'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('revogar')) {
            // Só os de changelog: revogar este não pode derrubar o token MCP
            // da mesma pessoa.
            $quantos = $usuario->tokens()->get()
                ->filter(fn ($token) => $token->abilities === ['changelog'])
                ->each->delete()
                ->count();
            $this->info("{$quantos} token(s) de changelog de {$usuario->name} revogado(s).");

            return self::SUCCESS;
        }

        $token = $usuario->createToken((string) $this->option('nome'), ['changelog'])->plainTextToken;

        $this->info("Token de changelog de {$usuario->name} ({$this->option('nome')}). Ele só aparece agora:");
        $this->line($token);
        $this->newLine();
        $this->comment('Guarde no chaveiro do Mac: ALFAMATRIZ_CHANGELOG_TOKEN=<token> deploy/publicar-changelog.sh --guardar-registro');

        return self::SUCCESS;
    }
}
