<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_ia_execucoes', function (Blueprint $table) {
            $table->json('progresso')->nullable();
            $table->timestamp('progresso_em')->nullable();
            $table->timestamp('status_avisado_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('central_ia_execucoes', fn (Blueprint $table) => $table->dropColumn(['progresso', 'progresso_em', 'status_avisado_em']));
    }
};
