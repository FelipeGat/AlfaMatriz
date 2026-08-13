<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A tabela `sistemas` guarda duas coisas com o mesmo nome.
 *
 * Tudo o que está nela é lido como PRODUTO: entra no fechamento, tem tier de
 * atacado, conta no MRR e aparece no ranking do Comercial. Só que o quadro de
 * tarefas pergunta à MESMA tabela "de qual sistema é esta tarefa?" — e aí a
 * lista só oferece o que se vende. A própria Matriz, a infra e o site não
 * cabem em lugar nenhum, e a tarefa deles nasce sem sistema: o filtro por
 * sistema e a raia por sistema perdem justamente o trabalho de dentro de casa.
 *
 * `natureza` separa os dois sem partir a tabela em duas. Partir criaria uma
 * segunda chave estrangeira em `tarefas` — `sistema_id` OU `interno_id` — e
 * toda tela que hoje faz um `belongsTo` passaria a ter de perguntar dos dois
 * lados. O que muda entre produto e interno é a POPULAÇÃO de cada consulta,
 * não a forma da linha.
 *
 * O padrão é `produto` porque é o que todo o catálogo de hoje é — o backfill
 * mora no DEFAULT, e não num UPDATE, exatamente por isso.
 *
 * Compatível com o azul/verde: a coluna nova tem default, e as duas que ficam
 * anuláveis eram obrigatórias — a versão anterior continua gravando o valor
 * que sempre gravou, sem enxergar nada disto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sistemas', function (Blueprint $table) {
            $table->enum('natureza', ['produto', 'interno'])->default('produto')->after('slug');
        });

        Schema::table('sistemas', function (Blueprint $table) {
            // Sistema interno não é cobrado, e as duas colunas eram
            // obrigatórias. Mantê-las assim obrigaria a inventar uma unidade
            // de cobrança e uma categoria comercial para quem não tem nem uma
            // nem outra — e a invenção apareceria na tela como informação.
            //
            // As telas já sabem lidar com a ausência: `produtos/index` e
            // `sistemas/index` só pintam o selo de categoria `@if` ela existe,
            // e o painel Comercial já agrupa o que não tem em "Sem categoria".
            $table->string('unidade_cobranca')
                ->comment('ex: academia ativa, condominio ativo, aluno, licenca')
                ->nullable()->change();
            $table->enum('categoria', ['saas', 'crm'])->nullable()->change();
        });
    }

    public function down(): void
    {
        // Voltar as colunas a NOT NULL exige que ninguém esteja nulo, e só
        // sistema interno pode estar. Ele não existe no esquema anterior, mas
        // as linhas dele sim: sem este preenchimento, o `change()` abaixo
        // estoura no primeiro interno cadastrado.
        DB::table('sistemas')->whereNull('unidade_cobranca')->update(['unidade_cobranca' => 'licenca']);
        DB::table('sistemas')->whereNull('categoria')->update(['categoria' => 'saas']);

        Schema::table('sistemas', function (Blueprint $table) {
            $table->string('unidade_cobranca')
                ->comment('ex: academia ativa, condominio ativo, aluno, licenca')
                ->nullable(false)->change();
            $table->enum('categoria', ['saas', 'crm'])->default('saas')->nullable(false)->change();
        });

        Schema::table('sistemas', function (Blueprint $table) {
            $table->dropColumn('natureza');
        });
    }
};
