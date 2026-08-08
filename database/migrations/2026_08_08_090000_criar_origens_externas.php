<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('origens_externas', function (Blueprint $table) {
            $table->id();
            $table->string('entidade_type');     // App\Models\Revenda | App\Models\Cliente
            $table->unsignedBigInteger('entidade_id');
            $table->unsignedBigInteger('sistema_id');
            $table->string('id_externo');
            $table->timestamps();

            $table->unique(['entidade_type', 'entidade_id', 'sistema_id'], 'origens_entidade_unicas');
            $table->unique(['entidade_type', 'sistema_id', 'id_externo'], 'origens_externa_unicas');
            $table->foreign('sistema_id')->references('id')->on('sistemas')->cascadeOnDelete();
        });

        // Carrega a âncora dos registros já sincronizados do AlfaGym.
        $sistemaId = DB::table('sistemas')->where('slug', 'alfagym')->value('id');

        if ($sistemaId) {
            foreach (DB::table('revendas')->whereNotNull('id_externo_origem')->get() as $revenda) {
                DB::table('origens_externas')->insertOrIgnore([
                    'entidade_type' => \App\Models\Revenda::class, 'entidade_id' => $revenda->id,
                    'sistema_id' => $sistemaId, 'id_externo' => $revenda->id_externo_origem,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            foreach (DB::table('clientes')->whereNotNull('id_externo_origem')->get() as $cliente) {
                DB::table('origens_externas')->insertOrIgnore([
                    'entidade_type' => \App\Models\Cliente::class, 'entidade_id' => $cliente->id,
                    'sistema_id' => $sistemaId, 'id_externo' => $cliente->id_externo_origem,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        Schema::table('revendas', fn (Blueprint $table) => $table->dropColumn('id_externo_origem'));
        Schema::table('clientes', fn (Blueprint $table) => $table->dropColumn('id_externo_origem'));
    }

    public function down(): void
    {
        Schema::table('revendas', fn (Blueprint $table) => $table->string('id_externo_origem')->nullable()->after('id'));
        Schema::table('clientes', fn (Blueprint $table) => $table->string('id_externo_origem')->nullable()->after('id'));

        // Devolve a âncora do AlfaGym para as colunas antigas.
        $sistemaId = DB::table('sistemas')->where('slug', 'alfagym')->value('id');

        if ($sistemaId) {
            foreach (DB::table('origens_externas')->where('entidade_type', \App\Models\Revenda::class)->where('sistema_id', $sistemaId)->get() as $origem) {
                DB::table('revendas')->where('id', $origem->entidade_id)->update(['id_externo_origem' => $origem->id_externo]);
            }

            foreach (DB::table('origens_externas')->where('entidade_type', \App\Models\Cliente::class)->where('sistema_id', $sistemaId)->get() as $origem) {
                DB::table('clientes')->where('id', $origem->entidade_id)->update(['id_externo_origem' => $origem->id_externo]);
            }
        }

        Schema::dropIfExists('origens_externas');
    }
};
