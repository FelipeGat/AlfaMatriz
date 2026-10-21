<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A capa elaborada (Felipe, 20/10/2026: "com imagem de fundo e clientes que
     * atendemos e informações do cliente"): a foto de cada orçamento e os ajustes do
     * módulo — dados do emissor, clientes atendidos e a capa padrão —, editáveis
     * na tela, sem nada fixo no código.
     *
     * A lista de clientes nasce da apresentação da Radier (v2, 29/09/2026), a que já
     * foi enviada com "instituições e empresas que já atendemos". Telefone, e-mail e
     * cidade nascem do rodapé que as propostas já usavam. CNPJ, razão social e
     * endereço do emissor ficam vazios: nenhuma proposta os trazia.
     */
    public function up(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->string('capa_imagem', 255)->nullable()->after('lead');
        });
        Schema::create('orcamento_ajustes', function (Blueprint $table) {
            $table->id();
            $table->string('chave', 40)->unique();
            $table->text('valor')->nullable();
            $table->timestamps();
        });
        $agora = now();
        foreach ([
            'emissor_nome' => 'VixSoftHouse',
            'emissor_cidade' => 'Vitória/ES',
            'emissor_telefone' => '(27) 3335-4950',
            'emissor_email' => 'comercial@vixcard.com.br',
            'clientes' => "## Ensino\nCEDTEC\nFaculdade Apoena\nFAESA\nUNESC Colatina\n## Saúde\nHospital Jayme Santos Neves\nHospital Infantil de Cachoeiro\nSanta Casa de Cachoeiro\nRede Meridional\n"
                ."## Shopping e corporativo\nRede Indigo\nShopping Vitória\nShopping Boulevard\nRede Gazeta\n## Indústria\nGrupo Águia Branca\nTechnip\nBrametal\nVitória Motors",
        ] as $chave => $valor) {
            DB::table('orcamento_ajustes')->insert(['chave' => $chave, 'valor' => $valor, 'created_at' => $agora, 'updated_at' => $agora]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('orcamento_ajustes');
        Schema::table('orcamentos', fn (Blueprint $t) => $t->dropColumn('capa_imagem'));
    }
};
