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
                'password' => bcrypt('AlfaTecnologia@2026'),
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
}
