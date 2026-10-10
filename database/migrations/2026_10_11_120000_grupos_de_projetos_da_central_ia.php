<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grupos de projetos no cockpit (pedido do dono em 10/10/2026: VixCard,
     * Grupo Soluções e Pessoal). São da conta — cada pessoa organiza os seus.
     * Apagar o grupo não apaga projeto: o projeto fica "sem grupo".
     */
    public function up(): void
    {
        Schema::create('central_ia_grupos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('nome', 60);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'nome']);
        });

        Schema::table('central_ia_projetos', function (Blueprint $table) {
            $table->foreignId('grupo_id')->nullable()->after('projeto')->constrained('central_ia_grupos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('central_ia_projetos', fn (Blueprint $t) => $t->dropConstrainedForeignId('grupo_id'));
        Schema::dropIfExists('central_ia_grupos');
    }
};
