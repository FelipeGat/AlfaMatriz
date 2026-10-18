<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cobrança da revenda por cliente, com boleto e baixa automática
     * (docs/specs/cobranca-revendas-boleto). Permissão própria, fora do
     * "tudo do administrador": só quem recebe o perfil vê (Felipe, 18/10/2026).
     */
    public function up(): void
    {
        Schema::table('revendas', function (Blueprint $table) {
            $table->boolean('cobranca_por_cliente')->default(false);
            $table->unsignedTinyInteger('dia_vencimento')->nullable();
            $table->string('email_financeiro', 190)->nullable();
            $table->string('cep', 9)->nullable();
            $table->string('logradouro', 190)->nullable();
            $table->string('numero', 20)->nullable();
            $table->string('bairro', 120)->nullable();
            $table->string('cidade', 120)->nullable();
            $table->char('uf', 2)->nullable();
            $table->foreignId('conta_financeira_id')->nullable()->constrained('contas_financeiras', 'id', 'revendas_conta_fin_fk')->nullOnDelete();
        });

        Schema::table('cliente_sistema', function (Blueprint $table) {
            // Entra na cobrança da revenda "por cliente"? (cortesia, implantação e teste ficam fora)
            $table->boolean('faturavel')->default(true);
        });

        Schema::table('cobrancas', function (Blueprint $table) {
            $table->string('mp_payment_id', 40)->nullable()->index('cobrancas_mp_payment_idx');
            $table->string('mp_status', 30)->nullable();
            $table->string('mp_status_detail', 60)->nullable();
            $table->string('mp_ticket_url', 500)->nullable();
            $table->string('mp_linha_digitavel', 100)->nullable();
            $table->string('mp_codigo_barras', 100)->nullable();
            $table->timestamp('mp_emitido_em')->nullable();
            $table->timestamp('mp_conferido_em')->nullable();
            $table->string('mp_erro', 255)->nullable();
        });

        $permissaoId = DB::table('permissoes')->where('recurso', 'cobranca_revendas')->value('id')
            ?? DB::table('permissoes')->insertGetId(['recurso' => 'cobranca_revendas', 'descricao' => 'Cobrança de revendas (boleto e baixa automática)', 'created_at' => now(), 'updated_at' => now()]);
        $perfilId = DB::table('perfis')->where('slug', 'cobranca_revendas')->value('id')
            ?? DB::table('perfis')->insertGetId(['nome' => 'Cobrança de revendas', 'slug' => 'cobranca_revendas', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('perfil_permissao')->updateOrInsert(['perfil_id' => $perfilId, 'permissao_id' => $permissaoId],
            ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => true, 'excluir' => false]);
    }

    public function down(): void
    {
        if ($perfilId = DB::table('perfis')->where('slug', 'cobranca_revendas')->value('id')) {
            DB::table('perfil_permissao')->where('perfil_id', $perfilId)->delete();
            DB::table('perfil_user')->where('perfil_id', $perfilId)->delete();
            DB::table('perfis')->where('id', $perfilId)->delete();
        }
        if ($permissaoId = DB::table('permissoes')->where('recurso', 'cobranca_revendas')->value('id')) {
            DB::table('perfil_permissao')->where('permissao_id', $permissaoId)->delete();
            DB::table('permissoes')->where('id', $permissaoId)->delete();
        }
        Schema::table('cobrancas', fn (Blueprint $t) => $t->dropColumn(['mp_payment_id', 'mp_status', 'mp_status_detail', 'mp_ticket_url',
            'mp_linha_digitavel', 'mp_codigo_barras', 'mp_emitido_em', 'mp_conferido_em', 'mp_erro']));
        Schema::table('cliente_sistema', fn (Blueprint $t) => $t->dropColumn('faturavel'));
        Schema::table('revendas', function (Blueprint $t) {
            $t->dropForeign('revendas_conta_fin_fk');
            $t->dropColumn(['cobranca_por_cliente', 'dia_vencimento', 'email_financeiro', 'cep', 'logradouro', 'numero', 'bairro', 'cidade', 'uf', 'conta_financeira_id']);
        });
    }
};
