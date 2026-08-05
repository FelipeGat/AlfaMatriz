<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Altera o e-mail e/ou a senha de uma conta existente.
 *
 * O painel está numa URL pública e o cadastro público está desativado: mudar
 * acesso é operação de servidor, não de tela. A conta é a mesma — histórico,
 * perfis e permissões continuam ligados a ela.
 */
class AlterarAcesso extends Command
{
    protected $signature = 'alfa:alterar-acesso
                            {email : E-mail atual da conta}
                            {--novo-email= : Novo e-mail de acesso}
                            {--senha= : Nova senha (se omitida, o comando pergunta em modo oculto)}';

    protected $description = 'Altera o e-mail e/ou a senha de uma conta do painel';

    public function handle(): int
    {
        $usuario = User::where('email', $this->argument('email'))->first();

        if (! $usuario) {
            $this->error("Não existe conta com o e-mail {$this->argument('email')}. Nenhuma alteração foi feita.");

            return self::FAILURE;
        }

        $novoEmail = $this->option('novo-email');
        $novaSenha = $this->option('senha');

        // Sem `--senha`, sempre pergunta — inclusive quando o e-mail também
        // está mudando, que é o caso mais comum. Em branco mantém a atual, para
        // quem só quer trocar o endereço.
        if ($novaSenha === null) {
            $novaSenha = $this->secret('Nova senha (em branco mantém a atual)');
        }

        if ($novoEmail !== null && $novoEmail !== '') {
            $erro = $this->validarEmail($novoEmail, $usuario);

            if ($erro !== null) {
                $this->error($erro);

                return self::FAILURE;
            }
        }

        if ($novaSenha !== null && $novaSenha !== '') {
            $validacao = Validator::make(
                ['password' => $novaSenha],
                ['password' => ['required', 'string', 'min:12']],
                [],
                ['password' => 'senha']
            );

            if ($validacao->fails()) {
                $this->error($validacao->errors()->first());

                return self::FAILURE;
            }
        }

        // Só toca no banco depois que TUDO passou: alteração pela metade
        // (e-mail novo com senha recusada, por exemplo) tranca o acesso.
        if ($novoEmail !== null && $novoEmail !== '') {
            $usuario->email = $novoEmail;
        }

        if ($novaSenha !== null && $novaSenha !== '') {
            // O cast `hashed` do modelo cuida do hash.
            $usuario->password = $novaSenha;
        }

        $usuario->save();

        $this->info("Acesso atualizado. A conta agora entra com {$usuario->email}.");

        return self::SUCCESS;
    }

    private function validarEmail(string $novoEmail, User $usuario): ?string
    {
        $validacao = Validator::make(
            ['email' => $novoEmail],
            ['email' => ['required', 'string', 'email', 'max:255']],
            [],
            ['email' => 'e-mail']
        );

        if ($validacao->fails()) {
            return $validacao->errors()->first();
        }

        $jaUsado = User::where('email', $novoEmail)
            ->where('id', '!=', $usuario->id)
            ->exists();

        if ($jaUsado) {
            return "O e-mail {$novoEmail} já pertence a outra conta. Nenhuma alteração foi feita.";
        }

        return null;
    }
}
