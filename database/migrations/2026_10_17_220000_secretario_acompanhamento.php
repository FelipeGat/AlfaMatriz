<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Secretário, Etapa 7 (docs/specs/secretario-acompanhamento): a situação
     * que pede acompanhamento (uma linha por chave e conta — a chave única é a
     * supressão de duplicata), o registro de cada resumo enviado e as regras
     * da pessoa (JSON nas preferências).
     */
    public function up(): void
    {
        Schema::create('secretario_acompanhamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('regra', 24);
            $table->string('chave', 191);
            $table->string('prioridade', 8);
            $table->string('texto', 500);
            $table->string('link', 500)->nullable();
            $table->dateTime('referencia_em')->nullable();
            $table->string('situacao', 12)->default('ativo');
            $table->dateTime('adiado_ate')->nullable();
            $table->unsignedTinyInteger('avisos')->default(0);
            $table->dateTime('avisado_em')->nullable();
            $table->dateTime('resolvido_em')->nullable();
            $table->timestamps();

            // Nomes curtos de propósito: o automático passa dos 64 caracteres
            // que o MariaDB aceita (o SQLite dos testes não reclama).
            $table->unique(['user_id', 'chave'], 'sec_acomp_user_chave_unique');
            $table->index(['user_id', 'situacao'], 'sec_acomp_user_situacao_index');
        });

        Schema::create('secretario_acompanhamento_envios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('canal', 10);
            $table->string('status', 10)->default('pendente');
            $table->unsignedTinyInteger('tentativas')->default(0);
            $table->dateTime('proxima_tentativa_em')->nullable();
            $table->json('acompanhamentos');
            $table->unsignedSmallInteger('quantos');
            $table->bigInteger('telegram_message_id')->nullable();
            $table->string('erro', 40)->nullable();
            $table->dateTime('entregue_em')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'sec_acomp_envio_user_criado_index');
            $table->index(['status', 'proxima_tentativa_em'], 'sec_acomp_envio_status_tentativa_index');
        });

        Schema::table('secretario_preferencias', function (Blueprint $table) {
            $table->json('acompanhamento')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('secretario_preferencias', function (Blueprint $table) {
            $table->dropColumn('acompanhamento');
        });
        Schema::dropIfExists('secretario_acompanhamento_envios');
        Schema::dropIfExists('secretario_acompanhamentos');
    }
};
