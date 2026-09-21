<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A categoria do compromisso — o que dá cor ao agendamento.
     *
     * Até aqui todo compromisso era o mesmo azul. A categoria (reunião interna,
     * com cliente, deploy, externo, foco) passa a decidir a cor, e a cor passa
     * a carregar informação — como o estado já faz no quadro. Os tons saem da
     * paleta já validada do sistema (ver `Compromisso::CATEGORIAS`); nada de cor
     * inventada.
     *
     * `default('interna')` é o ponto: todo compromisso que já existe herda a
     * reunião interna, que é o azul de hoje — a tela não muda de cor sozinha, só
     * quando alguém escolhe outra categoria.
     */
    public function up(): void
    {
        Schema::table('compromissos', function (Blueprint $table) {
            $table->string('categoria')->default('interna')->after('descricao');
        });
    }

    public function down(): void
    {
        Schema::table('compromissos', function (Blueprint $table) {
            $table->dropColumn('categoria');
        });
    }
};
