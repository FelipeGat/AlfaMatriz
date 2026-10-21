<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A lista de acesso interno do Pedro (docs/specs/suporte-pedro-whatsapp/acesso-interno.md),
     * com os números que o Felipe passou em 21/10/2026. Não
     * sobrescreve uma lista já editada.
     */
    public function up(): void
    {
        if (DB::table('suporte_ajustes')->where('chave', 'acessos_internos')->exists()) {
            return;
        }
        DB::table('suporte_ajustes')->insert(['chave' => 'acessos_internos', 'valor' => "+55 27 99884-5397 | Felipe | diretor\n+55 27 99745-8265 | Wallas | gestor\n+55 27 3109-3265 | Alexandre | suporte",
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('suporte_ajustes')->where('chave', 'acessos_internos')->delete();
    }
};
