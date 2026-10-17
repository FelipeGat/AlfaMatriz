<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Secretário, Etapa 2 (docs/specs/secretario-agenda): a conta Google da
     * PRÓPRIA pessoa, as agendas dela e um cache mínimo dos eventos (sem
     * descrição nem convidados). Tokens cifrados no modelo (cast `encrypted`).
     */
    public function up(): void
    {
        Schema::create('secretario_google_contas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('conta')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->dateTime('expira_em');
            $table->string('escopos', 500)->default('');
            $table->string('situacao', 16)->default('conectada');
            $table->dateTime('sincronizado_em')->nullable();
            $table->string('erro', 40)->nullable();
            $table->dateTime('aviso_reconectar_em')->nullable();
            $table->timestamps();
        });

        Schema::create('secretario_google_agendas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('google_id');
            $table->string('nome');
            $table->boolean('primaria')->default(false);
            $table->boolean('incluida')->default(true);
            $table->boolean('padrao')->default(false);
            $table->string('palavras')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'google_id']);
        });

        Schema::create('secretario_google_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agenda_id')->constrained('secretario_google_agendas')->cascadeOnDelete();
            $table->string('google_id');
            $table->string('titulo');
            $table->dateTime('inicio');
            $table->dateTime('fim');
            $table->boolean('dia_inteiro')->default(false);
            $table->string('link', 500)->nullable();
            $table->timestamps();

            $table->unique(['agenda_id', 'google_id']);
            $table->index(['user_id', 'inicio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secretario_google_eventos');
        Schema::dropIfExists('secretario_google_agendas');
        Schema::dropIfExists('secretario_google_contas');
    }
};
