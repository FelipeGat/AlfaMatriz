<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Comercial › Orçamentos (docs/specs/orcamentos-vixcard): a proposta no padrão
     * VixCard, com um modelo só de PDF. Recurso próprio, FORA do "tudo do
     * administrador" — só o Felipe e o Victor (decisão de 19/10/2026).
     */
    public function up(): void
    {
        Schema::create('orcamentos', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('ano');
            $table->unsignedInteger('numero');
            $table->unsignedTinyInteger('revisao')->default(1);
            $table->foreignId('revisao_de_id')->nullable()->constrained('orcamentos', 'id', 'orc_revisao_de_fk')->nullOnDelete();
            $table->foreignId('substituido_por_id')->nullable()->constrained('orcamentos', 'id', 'orc_substituido_fk')->nullOnDelete();
            $table->string('situacao', 12)->default('rascunho');
            $table->string('produto', 40);
            $table->string('cliente', 160);
            $table->string('contato', 120)->nullable();
            $table->string('cidade', 120)->nullable();
            $table->string('titulo', 120);
            $table->string('destaque', 80)->nullable();
            $table->text('lead')->nullable();
            $table->unsignedSmallInteger('validade_dias')->default(15);
            $table->decimal('entrada', 12, 2)->default(0);
            $table->unsignedTinyInteger('parcelas')->default(1);
            $table->decimal('desconto_avista', 5, 2)->default(0);
            $table->text('inclui')->nullable();
            $table->text('nao_inclui')->nullable();
            $table->text('passos')->nullable();
            $table->boolean('aceite')->default(true);
            $table->text('observacoes')->nullable();
            $table->decimal('total_unico', 12, 2)->default(0);
            $table->decimal('total_mensal', 12, 2)->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users', 'id', 'orc_user_fk')->nullOnDelete();
            $table->string('pdf_arquivo', 255)->nullable();
            $table->timestamp('pdf_gerado_em')->nullable();
            $table->timestamp('enviado_em')->nullable();
            $table->timestamp('respondido_em')->nullable();
            $table->timestamps();
            $table->unique(['ano', 'numero', 'revisao'], 'orc_numero_unico');
            $table->index('situacao', 'orc_situacao');
        });

        Schema::create('orcamento_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orcamento_id')->constrained('orcamentos', 'id', 'orc_itens_orc_fk')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->string('descricao', 255);
            $table->decimal('quantidade', 12, 3)->default(1);
            $table->string('unidade', 20)->default('un');
            $table->decimal('valor_unitario', 12, 2)->default(0);
            $table->string('cobranca', 8)->default('unico');
            $table->timestamps();
        });

        $permissaoId = DB::table('permissoes')->where('recurso', 'orcamentos')->value('id')
            ?? DB::table('permissoes')->insertGetId(['recurso' => 'orcamentos', 'descricao' => 'Orçamentos VixCard (propostas em PDF)', 'created_at' => now(), 'updated_at' => now()]);
        $perfilId = DB::table('perfis')->where('slug', 'orcamentos')->value('id')
            ?? DB::table('perfis')->insertGetId(['nome' => 'Orçamentos VixCard', 'slug' => 'orcamentos', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('perfil_permissao')->updateOrInsert(['perfil_id' => $perfilId, 'permissao_id' => $permissaoId],
            ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => true, 'excluir' => true]);
        // Felipe e Victor, pelo e-mail: quem não existir neste banco simplesmente não recebe.
        foreach (DB::table('users')->whereIn('email', ['felipehenriquegat@gmail.com', 'victor@vixcard.com.br'])->pluck('id') as $userId) {
            DB::table('perfil_user')->updateOrInsert(['perfil_id' => $perfilId, 'user_id' => $userId]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('orcamento_itens');
        Schema::dropIfExists('orcamentos');
        if ($perfilId = DB::table('perfis')->where('slug', 'orcamentos')->value('id')) {
            DB::table('perfil_permissao')->where('perfil_id', $perfilId)->delete();
            DB::table('perfil_user')->where('perfil_id', $perfilId)->delete();
            DB::table('perfis')->where('id', $perfilId)->delete();
        }
        if ($permissaoId = DB::table('permissoes')->where('recurso', 'orcamentos')->value('id')) {
            DB::table('perfil_permissao')->where('permissao_id', $permissaoId)->delete();
            DB::table('permissoes')->where('id', $permissaoId)->delete();
        }
    }
};
