<?php

namespace Database\Seeders;

use App\Models\CentroCusto;
use App\Models\Perfil;
use App\Models\Revenda;
use App\Models\User;
use Illuminate\Database\Seeder;

class DadosIniciaisSeeder extends Seeder
{
    public function run(): void
    {
        $invest = Revenda::updateOrCreate(
            ['cnpj' => null],
            ['nome' => 'Invest Soluções', 'ativo' => true]
        );

        CentroCusto::updateOrCreate(['nome' => 'Alfa Tecnologia'], ['ativo' => true]);

        $admin = User::updateOrCreate(
            ['email' => 'admin@alfatecnologia.com.br'],
            [
                'name' => 'Administrador Alfa',
                'password' => bcrypt($this->senhaDoAdmin()),
                'primeiro_acesso' => false,
                'ativo' => true,
                'email_verified_at' => now(),
            ]
        );

        $perfilAdmin = Perfil::where('slug', 'admin')->first();
        if ($perfilAdmin) {
            $admin->perfis()->syncWithoutDetaching([$perfilAdmin->id]);
        }
    }

    /**
     * A senha de exemplo está publicada no README — em produção ela não pode
     * virar a senha real do painel, que fica numa URL pública. Fora de
     * produção o padrão continua valendo para não travar o setup local.
     */
    private function senhaDoAdmin(): string
    {
        $senha = env('ADMIN_PASSWORD');

        if (filled($senha)) {
            return $senha;
        }

        if (app()->environment('production')) {
            throw new \RuntimeException(
                'Defina ADMIN_PASSWORD no ambiente antes de rodar a carga inicial em produção: '
                .'a senha de exemplo do README não pode ser a senha do painel publicado.'
            );
        }

        return 'AlfaTecnologia@2026';
    }
}
