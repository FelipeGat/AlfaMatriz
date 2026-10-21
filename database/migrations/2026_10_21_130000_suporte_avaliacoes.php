<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Encerramento com avaliação (Felipe, 21/10/2026: "como nos outros chats, o da Intelbras
     * por exemplo, no final ter uma forma de avaliação e encerramento do atendimento"). Uma
     * linha por encerramento: quem atendeu, quem encerrou, a nota de 1 a 5 e o comentário.
     */
    public function up(): void
    {
        Schema::create('suporte_avaliacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversa_id')->constrained('suporte_conversas')->cascadeOnDelete();
            $table->foreignId('atendente_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('encerrado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('nota')->nullable();
            $table->string('comentario', 1000)->nullable();
            $table->timestamp('respondida_em')->nullable();
            $table->timestamps();
            $table->index(['conversa_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suporte_avaliacoes');
    }
};
