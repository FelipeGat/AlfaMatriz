<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documentos em mão dupla (docs/specs/documentos-mao-dupla): o Matriz anota
     * a alteração e a máquina do dono a aplica no OneDrive. As subpastas vêm no
     * inventário para a pasta vazia também aparecer (FR-006).
     */
    public function up(): void
    {
        Schema::table('documento_pastas', function (Blueprint $table) {
            $table->json('subpastas')->nullable();
        });

        Schema::create('documento_operacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pasta_id')->constrained('documento_pastas', 'id', 'doc_ops_pasta_fk')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users', 'id', 'doc_ops_user_fk')->nullOnDelete();
            $table->string('tipo', 20);
            $table->string('caminho', 600);
            $table->string('destino', 600)->nullable();
            $table->string('arquivo', 255)->nullable();
            $table->unsignedBigInteger('tamanho')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('situacao', 12)->default('pendente');
            $table->string('erro', 200)->nullable();
            $table->timestamp('feita_em')->nullable();
            $table->timestamps();
            $table->index(['pasta_id', 'situacao'], 'doc_ops_pasta_situacao');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documento_operacoes');
        Schema::table('documento_pastas', function (Blueprint $table) {
            $table->dropColumn('subpastas');
        });
    }
};
