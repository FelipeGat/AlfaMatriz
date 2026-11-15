<?php

namespace App\Console\Commands;

use App\Mcp\Servers\AlfaMatrizServer;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Emite (ou revoga) o token da API Executiva de leitura.
 *
 * Pela linha de comando, como o do MCP: dar a um programa o poder de consultar
 * a vida da empresa é decisão de quem administra o servidor. Aparece UMA vez; o
 * banco guarda só o hash. Capacidade única, `executivo:ler`, com validade
 * (30 dias por padrão) — token sem prazo é o que ninguém lembra de revogar.
 * Recusa qualquer conta que não seja a de `services.executivo.conta`.
 */
class EmitirTokenExecutivo extends Command
{
    public const CAPACIDADE = 'executivo:ler';

    protected $signature = 'alfa:executivo-token
                            {email : E-mail da conta (só a configurada em services.executivo.conta)}
                            {--nome=chatgpt : Um nome para reconhecer o token}
                            {--revogar : Revoga todos os tokens executivos dessa conta em vez de emitir}';

    protected $description = 'Emite ou revoga o token da API Executiva (somente leitura)';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        try {
            if (strcasecmp($email, (string) config('services.executivo.conta')) !== 0) {
                throw new RuntimeException('A API Executiva serve só a conta configurada; '.$email.' não é ela.');
            }

            $usuario = AlfaMatrizServer::usuarioDoProcesso($email);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('revogar')) {
            $quantos = $usuario->tokens()->where('abilities', 'like', '%'.self::CAPACIDADE.'%')->delete();
            $this->info("{$quantos} token(s) executivo(s) de {$usuario->name} revogado(s).");

            return self::SUCCESS;
        }

        $dias = max(1, (int) config('services.executivo.validade_dias'));
        $token = $usuario->createToken((string) $this->option('nome'), [self::CAPACIDADE], now()->addDays($dias))->plainTextToken;

        $this->info("Token executivo de {$usuario->name} ({$this->option('nome')}), válido por {$dias} dias. Só aparece agora:");
        $this->line($token);
        $this->newLine();
        $this->comment('Use como "Authorization: Bearer <token>" em /api/executivo. Só leitura.');

        return self::SUCCESS;
    }
}
