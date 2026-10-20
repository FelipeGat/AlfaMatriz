<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * O Pedro (docs/specs/suporte-pedro-whatsapp, etapa 2). A fila é própria, e
     * não a da Central de IA: cada WhatsApp viraria uma conversa na Central do
     * Felipe e disputaria a vaga dos pedidos de código. O runner da conta do
     * Felipe atende esta fila numa vaga só dela, com a mesma IA, sem ferramentas.
     */
    public function up(): void
    {
        Schema::create('suporte_pedidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversa_id')->constrained('suporte_conversas', 'id', 'sup_ped_conversa_fk')->cascadeOnDelete();
            // Até qual mensagem do cliente este pedido responde (as que chegarem depois viram outro pedido).
            $table->unsignedBigInteger('ate_mensagem_id');
            $table->string('situacao', 12)->default('pendente');
            $table->string('maquina', 64)->nullable();
            $table->timestamp('reservado_em')->nullable();
            $table->string('acao', 20)->nullable();
            $table->text('resumo')->nullable();
            $table->string('erro', 255)->nullable();
            $table->timestamps();
            $table->index(['situacao', 'id'], 'sup_ped_fila');
        });

        // Quem assina os atendimentos que o Pedro abre: desativada e sem perfil, como a conta do vigia de logs.
        if (! DB::table('users')->where('email', 'pedro-suporte@alfamatriz.interno')->exists()) {
            $conta = ['name' => 'Pedro (suporte)', 'email' => 'pedro-suporte@alfamatriz.interno', 'password' => Hash::make(Str::random(64)),
                'created_at' => now(), 'updated_at' => now()];
            foreach (['ativo' => false, 'primeiro_acesso' => false] as $coluna => $valor) {
                if (Schema::hasColumn('users', $coluna)) {
                    $conta[$coluna] = $valor;
                }
            }
            DB::table('users')->insert($conta);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('suporte_pedidos');
    }
};
