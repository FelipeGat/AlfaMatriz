<?php

namespace Database\Seeders;

use App\Models\Auditoria;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * O `semRegistro` cobre a semeadura INTEIRA, e não cada seeder por dentro.
     *
     * Semear não é alguém fazendo alguma coisa: é o banco nascendo. Sem a
     * mordaça, a tela de auditoria de um ambiente recém-montado abriria com
     * centenas de linhas de "Sistema criou despesa" — e o que se foi procurar
     * ali já estaria três páginas abaixo. Em produção não muda nada: o deploy
     * roda só `migrate --force`.
     */
    public function run(): void
    {
        Auditoria::semRegistro(fn () => $this->call([
            PerfilPermissaoSeeder::class,
            DadosIniciaisSeeder::class,
            SistemasPrecosSeeder::class,
            DespesasAlfaSeeder::class,
        ]));
    }
}
