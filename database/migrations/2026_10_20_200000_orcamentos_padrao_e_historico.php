<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Orçamentos, etapa 2 (docs/specs/orcamentos-vixcard/padrao-e-historico.md): o
     * pré-cliente do Funil, a capa e o conteúdo padrão editáveis, e o histórico das
     * propostas antigas com o PDF que foi enviado.
     *
     * As seções opcionais ficam num JSON (`secoes`) e os dados cadastrais do cliente
     * em outro (`dados_cliente`): são campos que só a proposta lê, e uma coluna por
     * campo seria dezenas de colunas vazias na maioria dos orçamentos.
     */
    public function up(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->after('produto')->constrained('leads', 'id', 'orc_lead_fk')->nullOnDelete();
            $table->string('tipo', 40)->nullable()->after('lead_id');
            $table->string('rotulo_3', 30)->nullable()->after('cidade');
            $table->string('valor_3', 160)->nullable()->after('rotulo_3');
            $table->string('natureza', 20)->nullable()->after('valor_3');
            $table->json('dados_cliente')->nullable()->after('natureza');
            $table->text('objeto')->nullable()->after('dados_cliente');
            $table->json('secoes')->nullable()->after('passos');
            $table->text('planos')->nullable()->after('secoes');
            $table->text('pagamento_texto')->nullable()->after('desconto_avista');
            // Nunca vai ao PDF: o que a equipe precisa saber do orçamento (o "observações" sai impresso).
            $table->text('notas_internas')->nullable()->after('observacoes');
            $table->string('origem_arquivo', 255)->nullable()->unique('orc_origem_unq');
            $table->string('pdf_original', 255)->nullable();
            $table->timestamp('importado_em')->nullable();
            $table->unsignedSmallInteger('validade_dias')->nullable()->default(15)->change();
        });
    }

    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->dropForeign('orc_lead_fk');
            $table->dropUnique('orc_origem_unq');
            $table->dropColumn(['lead_id', 'tipo', 'rotulo_3', 'valor_3', 'natureza', 'dados_cliente', 'objeto', 'secoes', 'planos', 'pagamento_texto', 'notas_internas',
                'origem_arquivo', 'pdf_original', 'importado_em']);
        });
    }
};
