<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Cria contas do painel pela linha de comando.
 *
 * O cadastro público foi desativado (o painel fica numa URL pública), então
 * este comando é o único caminho para uma conta nova.
 */
class CriarUsuario extends Command
{
    protected $signature = 'alfa:criar-usuario
                            {nome : Nome de quem vai usar o painel}
                            {email : E-mail de acesso}
                            {--senha= : Senha inicial (se omitida, o comando pergunta)}';

    protected $description = 'Cria um usuário do painel (o cadastro público está desativado)';

    public function handle(): int
    {
        $nome = $this->argument('nome');
        $email = $this->argument('email');
        $senha = $this->option('senha') ?: $this->secret('Senha inicial');

        $validacao = Validator::make(
            ['name' => $nome, 'email' => $email, 'password' => $senha],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:12'],
            ],
            [],
            ['name' => 'nome', 'email' => 'e-mail', 'password' => 'senha']
        );

        if ($validacao->fails()) {
            foreach ($validacao->errors()->all() as $erro) {
                $this->error($erro);
            }

            return self::FAILURE;
        }

        // Nunca sobrescreve quem já existe: trocar senha de alguém é outra
        // operação, e um engano aqui derrubaria o acesso da pessoa.
        if (User::where('email', $email)->exists()) {
            $this->error("Já existe um usuário com o e-mail {$email}. Nenhuma alteração foi feita.");

            return self::FAILURE;
        }

        $usuario = new User;
        $usuario->name = $nome;
        $usuario->email = $email;
        // O cast `hashed` do modelo cuida do hash — não passe por Hash::make aqui.
        $usuario->password = $senha;
        $usuario->ativo = true;
        $usuario->primeiro_acesso = true;
        // O painel exige e-mail verificado (middleware `verified`) e não há
        // envio de e-mail configurado: a conta já nasce liberada.
        $usuario->email_verified_at = now();
        $usuario->save();

        $this->info("Usuário {$email} criado. Peça para trocar a senha no primeiro acesso.");

        return self::SUCCESS;
    }
}
