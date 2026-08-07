<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Confere, no servidor, por onde o e-mail vai sair — e sai de verdade.
 *
 * O painel passou muito tempo com o envio apontado para o arquivo de log em
 * produção: nada chegava a ninguém e nada acusava o problema. Por isso este
 * comando IMPRIME a configuração efetiva ANTES de tentar enviar. Um destino
 * errado aparece na hora, em vez de sumir junto com o e-mail.
 */
class TestarEmail extends Command
{
    protected $signature = 'app:testar-email
                            {destino? : Para quem enviar (padrão: o próprio remetente configurado)}';

    protected $description = 'Mostra por onde o e-mail sai e envia uma mensagem de teste';

    public function handle(): int
    {
        $meio = (string) config('mail.default');
        $mailer = (array) config("mail.mailers.{$meio}", []);
        $remetente = (string) config('mail.from.address');
        $destino = (string) ($this->argument('destino') ?: $remetente);

        $this->line('Por onde o envio vai sair:');
        $this->line('  meio ......... '.($meio !== '' ? $meio : '(não configurado)'));
        $this->line('  servidor ..... '.$this->descrever($mailer, 'host'));
        $this->line('  porta ........ '.$this->descrever($mailer, 'port'));
        $this->line('  criptografia . '.$this->descrever($mailer, 'scheme'));
        $this->line('  usuário ...... '.$this->descrever($mailer, 'username'));
        // A senha nunca é impressa: o valor viria do ambiente e este comando
        // roda num terminal que costuma ficar em histórico.
        $this->line('  senha ........ '.(filled($mailer['password'] ?? null) ? 'definida' : 'NÃO definida'));
        $this->line('  remetente .... '.($remetente !== '' ? $remetente : '(não configurado)'));
        $this->line('  destino ...... '.($destino !== '' ? $destino : '(não informado)'));
        $this->newLine();

        if ($meio === 'log') {
            $this->warn(
                'O envio está apontado para o arquivo de log: nada sai para a internet. '
                .'Ajuste MAIL_MAILER no ambiente do servidor.'
            );
        }

        if ($destino === '') {
            $this->error(
                'Não há para quem enviar: informe um destino ou preencha MAIL_FROM_ADDRESS no ambiente.'
            );

            return self::FAILURE;
        }

        $this->line("Enviando para {$destino}...");

        try {
            Mail::raw($this->corpo(), function ($mensagem) use ($destino) {
                $mensagem->to($destino)->subject('AlfaMatriz — teste de envio');
            });
        } catch (Throwable $erro) {
            // Cada informação na sua linha: mensagem longa numa linha só é o
            // que faz um erro de servidor passar despercebido no terminal.
            $this->error('O envio falhou.');
            $this->line('  motivo: '.$erro->getMessage());
            $this->line('  se a porta 587 estiver bloqueada na saída, tente MAIL_SCHEME=smtps com MAIL_PORT=465.');

            return self::FAILURE;
        }

        $this->info("Envio concluído sem erro. Confira a caixa de entrada de {$destino}.");

        if ($meio === 'log') {
            $this->line('Como o meio é o arquivo de log, a mensagem está em storage/logs — não na caixa de entrada.');
        }

        return self::SUCCESS;
    }

    private function descrever(array $mailer, string $chave): string
    {
        $valor = $mailer[$chave] ?? null;

        return filled($valor) ? (string) $valor : '(não configurado)';
    }

    private function corpo(): string
    {
        return implode("\n", [
            'Mensagem de teste do AlfaMatriz.',
            '',
            'Se você recebeu isto, o envio de e-mail do painel está funcionando',
            'e os avisos automáticos vão conseguir chegar.',
            '',
            'Enviada por: php artisan app:testar-email',
        ]);
    }
}
