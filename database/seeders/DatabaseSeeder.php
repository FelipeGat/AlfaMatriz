<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PerfilPermissaoSeeder::class,
            DadosIniciaisSeeder::class,
            SistemasPrecosSeeder::class,
            DespesasAlfaSeeder::class,
        ]);
    }
}
