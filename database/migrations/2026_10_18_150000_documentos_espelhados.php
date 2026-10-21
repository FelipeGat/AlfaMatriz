<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pastas de documentos espelhadas do OneDrive pela máquina do dono
     * (docs/specs/documentos-espelhados). Acesso POR PASTA — o dono e quem ele
     * escolheu —, e não por perfil: o administrador não vê por ser administrador.
     */
    public function up(): void
    {
        Schema::create('documento_pastas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users', 'id', 'doc_pastas_user_fk')->cascadeOnDelete();
            $table->string('nome', 120);
            $table->string('maquina', 64)->nullable();
            $table->timestamp('lido_em')->nullable();
            $table->unsignedInteger('arquivos')->default(0);
            $table->unsignedBigInteger('bytes')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'nome'], 'doc_pastas_dono_nome');
        });

        Schema::create('documento_pasta_acessos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pasta_id')->constrained('documento_pastas', 'id', 'doc_acessos_pasta_fk')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users', 'id', 'doc_acessos_user_fk')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['pasta_id', 'user_id'], 'doc_acessos_unico');
        });

        Schema::create('documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pasta_id')->constrained('documento_pastas', 'id', 'documentos_pasta_fk')->cascadeOnDelete();
            $table->string('caminho', 600);
            $table->char('caminho_hash', 40);
            $table->string('subpasta', 500)->default('');
            $table->string('nome', 255);
            $table->string('extensao', 16)->default('');
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('tamanho');
            $table->char('sha256', 64);
            $table->timestamp('modificado_em')->nullable();
            $table->string('arquivo', 255);
            $table->timestamps();
            $table->unique(['pasta_id', 'caminho_hash'], 'documentos_pasta_caminho');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos');
        Schema::dropIfExists('documento_pasta_acessos');
        Schema::dropIfExists('documento_pastas');
    }
};
