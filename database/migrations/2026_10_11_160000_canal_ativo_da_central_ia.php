<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_ia_conversas', function (Blueprint $table) {
            $table->string('canal_ativo', 16)->default('web');
            $table->string('projeto_canal', 128)->nullable();
            $table->unsignedBigInteger('tarefa_canal')->nullable();
            $table->unsignedBigInteger('canal_desde_id')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('central_ia_conversas', fn (Blueprint $table) => $table->dropColumn([
            'canal_ativo', 'projeto_canal', 'tarefa_canal', 'canal_desde_id',
        ]));
    }
};
