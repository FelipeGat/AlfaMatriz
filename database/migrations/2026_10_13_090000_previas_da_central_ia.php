<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prévias visuais (dono, 13/10/2026): o conceito que o agente quer mostrar —
 * imagem, PDF, página — ficava em C:\tmp, onde o Mac não alcança. Agora a
 * máquina envia, e a Central mostra na conversa e manda ao Telegram.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_ia_previas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedBigInteger('execucao_id')->index();
            // Código do endereço da prévia (ver Previas::servir).
            $table->string('chave', 64)->index();
            $table->string('caminho', 300);
            $table->string('mime', 100);
            $table->unsignedInteger('tamanho');
            $table->string('sha256', 64);
            $table->string('telegram', 12)->nullable();
            $table->timestamps();
            $table->unique(['execucao_id', 'caminho']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_ia_previas');
    }
};
