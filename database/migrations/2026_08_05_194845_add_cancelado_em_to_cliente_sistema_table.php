<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cliente_sistema', function (Blueprint $table) {
            $table->date('cancelado_em')->nullable()->after('ativado_em');
        });
    }

    public function down(): void
    {
        Schema::table('cliente_sistema', function (Blueprint $table) {
            $table->dropColumn('cancelado_em');
        });
    }
};
