<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Secretário, Etapa 3 (docs/specs/secretario-briefing): o briefing do dia,
     * um automático por conta e dia (a chave única segura a repetição), e as
     * preferências de horário e dias.
     */
    public function up(): void
    {
        Schema::create('secretario_briefings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('data');
            $table->string('origem', 10);
            $table->text('texto');
            $table->json('fontes')->nullable();
            $table->string('telegram', 12)->default('sem');
            $table->unsignedTinyInteger('tentativas')->default(0);
            $table->dateTime('proxima_tentativa_em')->nullable();
            $table->dateTime('gerado_em');
            $table->timestamps();

            $table->unique(['user_id', 'data', 'origem']);
            $table->index(['telegram', 'proxima_tentativa_em']);
        });

        Schema::table('secretario_preferencias', function (Blueprint $table) {
            $table->boolean('briefing')->default(true);
            $table->string('briefing_hora', 5)->default('07:00');
            $table->json('briefing_dias')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('secretario_preferencias', function (Blueprint $table) {
            $table->dropColumn(['briefing', 'briefing_hora', 'briefing_dias']);
        });
        Schema::dropIfExists('secretario_briefings');
    }
};
