<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "A definir" é prioridade de verdade, e não a ausência de uma.
     *
     * Quem abre uma tarefa nem sempre é quem prioriza — e sem esta opção o
     * cadastro cai em "Média" por omissão. O padrão vira então uma afirmação
     * que ninguém fez e que ninguém revisa: a fila de triagem some dentro do
     * meio da escala, indistinguível do que já foi olhado e classificado assim.
     *
     * Ela entra no fim do enum, e não perto de "baixa", porque a ordem do enum
     * não é a ordem de gravidade — essa vive no `ordenarColuna` do controller.
     */
    public function up(): void
    {
        Schema::table('tarefas', function (Blueprint $table) {
            $table->enum('prioridade', ['baixa', 'media', 'alta', 'critica', 'nao_definida'])
                ->default('media')->change();
        });
    }

    public function down(): void
    {
        // Sem a opção no enum, quem estiver "a definir" precisa ir para algum
        // lugar. Média é o padrão do cadastro — é para onde essas tarefas
        // teriam ido se a opção nunca tivesse existido.
        DB::table('tarefas')
            ->where('prioridade', 'nao_definida')
            ->update(['prioridade' => 'media']);

        Schema::table('tarefas', function (Blueprint $table) {
            $table->enum('prioridade', ['baixa', 'media', 'alta', 'critica'])->default('media')->change();
        });
    }
};
