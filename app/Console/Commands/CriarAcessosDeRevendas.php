<?php

namespace App\Console\Commands;

use App\Models\Perfil;
use App\Models\Revenda;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Dá acesso ao painel às revendas que chegaram pelo sincronizador.
 *
 * Revenda provisionada pela Matriz já nasce com acesso (o
 * RevendaController::provisionar cria). As que vieram do AlfaGym pelo sync não
 * têm usuário nenhum — sem este comando, a revenda migrada não consegue entrar
 * para cadastrar cliente.
 *
 * Cada revenda recebe uma senha própria, gerada aqui e impressa uma única vez.
 * Senha compartilhada entre revendas concorrentes seria acesso cruzado
 * esperando acontecer.
 */
class CriarAcessosDeRevendas extends Command
{
    protected $signature = 'alfa:criar-acessos-revendas
                            {--revenda= : Só a revenda com este ID (padrão: todas as que não têm acesso)}';

    protected $description = 'Cria o acesso ao painel das revendas migradas do AlfaGym';

    public function handle(): int
    {
        $perfil = Perfil::where('slug', 'revenda')->first();

        if (! $perfil) {
            $this->error('Perfil "revenda" não existe. Rode `php artisan db:seed --class=PerfilPermissaoSeeder` primeiro.');

            return self::FAILURE;
        }

        $revendas = Revenda::query()
            ->when($this->option('revenda'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('nome')
            ->get();

        if ($revendas->isEmpty()) {
            $this->info('Nenhuma revenda encontrada.');

            return self::SUCCESS;
        }

        $criados = [];
        $jaTinham = [];
        $pendencias = [];

        foreach ($revendas as $revenda) {
            // Rodar de novo não pode derrubar quem já entra: revenda com acesso
            // é relatada e passa adiante, sem senha redefinida.
            if (User::where('revenda_id', $revenda->id)->exists()) {
                $jaTinham[] = $revenda->nome;

                continue;
            }

            $email = trim((string) $revenda->contato_email);

            if ($email === '') {
                $pendencias[] = $revenda->nome.' — sem e-mail de contato';

                continue;
            }

            if (User::where('email', $email)->exists()) {
                $pendencias[] = $revenda->nome.' — o e-mail '.$email.' já pertence a outro usuário';

                continue;
            }

            // Sem símbolos: a senha é lida do terminal e repassada por quem
            // administra. 20 caracteres alfanuméricos já são fortes, e nenhum
            // deles se confunde com a formatação do relatório.
            $senha = Str::password(20, symbols: false);

            $usuario = User::create([
                'name' => $revenda->contato_nome ?: $revenda->nome,
                'email' => $email,
                'password' => $senha,
                'revenda_id' => $revenda->id,
                'ativo' => true,
                // A senha sai daqui no relatório para ser repassada por quem
                // administra — ou seja, ela passa por um canal que não é da
                // pessoa. A troca no primeiro acesso é o que lhe dá vida curta.
                'primeiro_acesso' => true,
            ]);

            $usuario->perfis()->syncWithoutDetaching([$perfil->id]);

            $criados[] = [$revenda->nome, $email, $senha];
        }

        $this->relatar($criados, $jaTinham, $pendencias);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string}>  $criados
     * @param  array<int, string>  $jaTinham
     * @param  array<int, string>  $pendencias
     */
    private function relatar(array $criados, array $jaTinham, array $pendencias): void
    {
        if ($criados !== []) {
            $this->newLine();
            $this->info('Acessos criados ('.count($criados).') — a senha aparece só agora:');

            foreach ($criados as [$nome, $email, $senha]) {
                $this->line('  · '.$nome.' · '.$email.' · senha: '.$senha);
            }
        }

        if ($jaTinham !== []) {
            $this->newLine();
            $this->line('Já tinham acesso ('.count($jaTinham).'): '.implode(', ', $jaTinham));
        }

        if ($pendencias !== []) {
            $this->newLine();
            $this->warn('Sem acesso, precisam de você ('.count($pendencias).'):');

            foreach ($pendencias as $pendencia) {
                $this->line('  · '.$pendencia);
            }
        }

        if ($criados === [] && $pendencias === []) {
            $this->newLine();
            $this->info('Todas as revendas já têm acesso ao painel.');
        }
    }
}
