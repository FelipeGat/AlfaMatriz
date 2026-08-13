<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `roadmap` é coluna sem escritor — e nunca teve um de verdade.
     *
     * Ela nasceu em `add_gestao_fields_to_sistemas_table` junto com `versao` e
     * `responsavel`. As duas irmãs ganharam campo no formulário do sistema e
     * viraram informação; esta ficou esperando uma tela que não veio. O único
     * caminho de escrita que chegou a existir foi `ProdutoController@update`,
     * um endpoint que nenhuma view chamava e nenhum teste cobria — ele saiu, e
     * com ele o último lugar do código capaz de preencher a coluna.
     *
     * Sai por não ter meio-termo honesto. Coluna que nada escreve e nada lê não
     * é dado guardado, é dado que o esquema PROMETE e não entrega: quem abrir a
     * tabela vai encontrá-la e concluir que existe um roadmap por sistema em
     * algum lugar. Se um dia o roadmap virar produto, ele volta com a tela
     * junto — e provavelmente não como um `text` solto, porque roadmap com
     * datas e itens não cabe num campo de texto livre.
     *
     * SOBRE O DADO: não há. Nenhum seeder, factory, view ou teste escreve nela,
     * e o endpoint que podia nunca foi alcançável pela interface. O risco
     * residual é alguém ter preenchido a coluna por SQL à mão em produção —
     * nesse caso o conteúdo se perde aqui, e a volta abaixo devolve a coluna
     * vazia, não o texto.
     */
    public function up(): void
    {
        Schema::table('sistemas', function (Blueprint $table) {
            $table->dropColumn('roadmap');
        });
    }

    public function down(): void
    {
        Schema::table('sistemas', function (Blueprint $table) {
            // Mesma definição da migração que a criou, `after` inclusive: a
            // volta tem de devolver a tabela ao formato de antes, e não a uma
            // variação parecida que sirva para o mesmo teste passar.
            $table->text('roadmap')->nullable()->after('responsavel');
        });
    }
};
