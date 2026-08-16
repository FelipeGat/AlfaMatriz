<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O plano de licenciamento de sistema que um cliente final tem contratado.
 *
 * Espelha `cliente_modulo` (2026_08_11_092000), trocando módulo por sistema:
 * é o mesmo padrão de vigência (`data_inicio`/`data_fim`) que o fechamento
 * mensal já sabe varrer, agora para a licença do sistema em si — não só para
 * módulos add-on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cliente_contratos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('sistema_id')->constrained('sistemas')->cascadeOnDelete();
            $table->string('plano');
            $table->decimal('valor_mensal', 12, 2);
            $table->string('status', 20)->default('ativo'); // ativo|suspenso|cancelado
            $table->date('data_inicio');
            $table->date('data_fim')->nullable();
            $table->text('detalhamento')->nullable();
            $table->timestamps();

            $table->unique(['cliente_id', 'sistema_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cliente_contratos');
    }
};
