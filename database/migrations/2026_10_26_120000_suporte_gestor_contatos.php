<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * T-478 + T-479 do Gestor: a pessoa que se identificou ao Pedro vira contato do cliente
     * no Gestor (`cliente_contatos_identificados`). A gravação é da máquina, pela ponte, como
     * as aberturas; esta é a fila dela. Uma linha por (cliente, telefone, nome, função)
     * pedida: o Gestor faz o upsert pela chave dele, então repetir não duplica lá.
     */
    public function up(): void
    {
        Schema::create('suporte_gestor_contatos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversa_id')->constrained('suporte_conversas')->cascadeOnDelete();
            $table->unsignedBigInteger('gestor_cliente_id');
            $table->string('nome', 120);
            $table->string('papel', 60)->nullable();
            $table->string('telefone', 20);
            $table->char('telefone_chave', 10);
            $table->string('situacao', 15)->default('pendente')->index();
            $table->string('erro', 300)->nullable();
            $table->timestamp('reservado_em')->nullable();
            $table->timestamps();
            $table->index(['gestor_cliente_id', 'telefone_chave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suporte_gestor_contatos');
    }
};
