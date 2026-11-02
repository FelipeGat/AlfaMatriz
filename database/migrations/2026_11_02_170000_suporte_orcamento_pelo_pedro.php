<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * pedro-orcamento (02/11/2026): o proprietário pede um orçamento ao Pedro pelo WhatsApp.
     *   - a prévia conferida fica na conversa até o "sim", como a da agenda;
     *   - o orçamento confirmado espera na fila a máquina do dono, que o grava no Gestor; a chave
     *     da prévia é única aqui e vai ao Gestor, que devolve o mesmo orçamento se ela voltar;
     *   - o retrato do Gestor passa a trazer as empresas com o percentual de NF.
     */
    public function up(): void
    {
        Schema::table('suporte_conversas', function (Blueprint $table) {
            $table->json('previa_orcamento')->nullable();
        });
        Schema::create('suporte_gestor_orcamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversa_id')->constrained('suporte_conversas')->cascadeOnDelete();
            $table->string('chave', 64)->unique();
            $table->string('pedido_por', 120);
            $table->json('pedido');
            $table->string('situacao', 15)->default('pendente')->index();
            $table->json('resultado')->nullable();
            $table->string('erro', 300)->nullable();
            $table->timestamp('reservado_em')->nullable();
            $table->timestamps();
        });
        Schema::table('suporte_gestor_retrato', function (Blueprint $table) {
            $table->json('empresas')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('suporte_gestor_retrato', function (Blueprint $table) {
            $table->dropColumn('empresas');
        });
        Schema::dropIfExists('suporte_gestor_orcamentos');
        Schema::table('suporte_conversas', function (Blueprint $table) {
            $table->dropColumn('previa_orcamento');
        });
    }
};
