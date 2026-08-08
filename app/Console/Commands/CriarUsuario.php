<?php

namespace App\Console\Commands;

use App\Models\Perfil;
use App\Models\Revenda;
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
                            {--senha= : Senha inicial (se omitida, o comando pergunta)}
                            {--perfil=operacao : Perfil de acesso (admin, financeiro, operacao)}
                            {--revenda= : ID da revenda para escopo restrito (opcional)}';

    protected $description = 'Cria um usuário do painel (o cadastro público está desativado)';

    public function handle(): int
    {
        $nome = $this->argument('nome');
        $email = $this->argument('email');
        $senha = $this->option('senha') ?: $this->secret('Senha inicial');

        $perfil = $this->option('perfil');
        $revendaId = $this->option('revenda');

        $validacao = Validator::make(
            ['name' => $nome, 'email' => $email, 'password' => $senha, 'perfil' => $perfil],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:12'],
                'perfil' => ['required', 'in:admin,financeiro,operacao'],
            ],
            [],
            ['name' => 'nome', 'email' => 'e-mail', 'password' => 'senha', 'perfil' => 'perfil']
        );

        if ($validacao->fails()) {
            foreach ($validacao->errors()->all() as $erro) {
                $this->error($erro);
            }

            return self::FAILURE;
        }

        if ($revendaId !== null) {
            if (! Revenda::whereKey($revendaId)->exists()) {
                $this->error("Não existe revenda com o ID {$revendaId}.");

                return self::FAILURE;
            }
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
        $usuario->revenda_id = $revendaId !== null ? (int) $revendaId : null;
        // O painel exige e-mail verificado (middleware `verified`) e não há
        // envio de e-mail configurado: a conta já nasce liberada.
        $usuario->email_verified_at = now();
        $usuario->save();

        $perfilModel = Perfil::firstOrCreate(['slug' => $perfil], ['nome' => $this->rotuloDoPerfil($perfil)]);
        // Perfil recém-criado nasce sem permissão (senão o usuário travaria em
        // 403 em tudo). O seeder garante o conjunto padrão.
        if ($perfilModel->permissoes()->count() === 0) {
            (new \Database\Seeders\PerfilPermissaoSeeder)->run();
        }
        $usuario->perfis()->attach($perfilModel->id);

        $escopo = $revendaId !== null ? " com escopo na revenda {$revendaId}" : ' da matriz';
        $this->info("Usuário {$email} criado (perfil {$perfil}{$escopo}). Peça para trocar a senha no primeiro acesso.");

        return self::SUCCESS;
    }

    private function rotuloDoPerfil(string $slug): string
    {
        return match ($slug) {
            'admin' => 'Administrador',
            'financeiro' => 'Financeiro',
            default => 'Operação',
        };
    }
}
