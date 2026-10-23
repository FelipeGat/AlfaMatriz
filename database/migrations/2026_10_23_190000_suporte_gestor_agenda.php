<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * pedro-dia-do-tecnico, FASE 4: as mudanças na agenda dos técnicos confirmadas com o "sim" —
     * visita nova, horário corrigido, cancelamento —, que a máquina do dono grava no Gestor. Uma
     * linha por parada; a chave impede gravar duas vezes a mesma prévia; o lote junta as da mesma
     * confirmação para a resposta final.
     */
    public function up(): void
    {
        Schema::create('suporte_gestor_agenda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversa_id')->constrained('suporte_conversas')->cascadeOnDelete();
            $table->string('chave', 80)->unique();
            $table->string('lote', 60)->index();
            $table->string('tipo', 20);
            $table->unsignedBigInteger('visita_id')->nullable();
            $table->string('atendimento', 20)->nullable();
            $table->unsignedBigInteger('tecnico_id');
            $table->string('inicio', 16)->nullable();
            $table->string('fim', 16)->nullable();
            $table->json('esperado')->nullable();
            $table->string('motivo', 300)->nullable();
            $table->string('observacao', 300)->nullable();
            $table->string('pedido_por', 120);
            $table->string('situacao', 15)->default('pendente')->index();
            $table->json('resultado')->nullable();
            $table->string('erro', 300)->nullable();
            $table->timestamp('reservado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suporte_gestor_agenda');
    }
};
