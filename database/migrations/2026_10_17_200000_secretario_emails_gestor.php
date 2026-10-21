<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Secretário, Etapa 5 (docs/specs/secretario-emails-gestor): caixas IMAP
     * da própria pessoa (senha cifrada no modelo), só os METADADOS das
     * mensagens (o corpo nunca é guardado) e o último retrato do Gestor.
     */
    public function up(): void
    {
        Schema::create('secretario_email_contas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('rotulo', 60);
            $table->string('host', 120);
            $table->unsignedInteger('porta');
            $table->string('seguranca', 4);
            $table->string('usuario', 190);
            $table->text('senha');
            $table->string('pasta_entrada', 120)->default('INBOX');
            $table->string('pasta_enviados', 120)->nullable();
            $table->json('posicoes')->nullable();
            $table->string('situacao', 8)->default('ok');
            $table->string('erro', 80)->nullable();
            $table->dateTime('lida_em')->nullable();
            $table->timestamps();
        });

        Schema::create('secretario_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conta_id')->constrained('secretario_email_contas')->cascadeOnDelete();
            $table->string('pasta', 120);
            $table->unsignedBigInteger('uidvalidity');
            $table->unsignedBigInteger('uid');
            $table->string('message_id')->nullable();
            $table->string('responde_a')->nullable();
            $table->string('de_nome', 190)->nullable();
            $table->string('de_email', 190);
            $table->string('assunto');
            $table->dateTime('recebido_em');
            $table->string('tipo', 16)->default('outro');
            $table->string('urgencia', 8)->default('normal');
            $table->boolean('pede_retorno')->default(false);
            $table->boolean('enviado')->default(false);
            $table->boolean('respondido')->default(false);
            $table->timestamps();

            $table->unique(['conta_id', 'pasta', 'uidvalidity', 'uid']);
            $table->index(['user_id', 'recebido_em']);
            $table->index(['conta_id', 'message_id']);
        });

        Schema::create('secretario_gestor_retratos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('maquina', 64);
            $table->dateTime('lido_em');
            $table->json('dados');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secretario_gestor_retratos');
        Schema::dropIfExists('secretario_emails');
        Schema::dropIfExists('secretario_email_contas');
    }
};
