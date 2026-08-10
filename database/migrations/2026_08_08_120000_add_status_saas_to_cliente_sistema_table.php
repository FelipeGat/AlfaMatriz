<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cliente_sistema', function (Blueprint $table) {
            $table->string('status_saas')->nullable()->after('licenca_id_externo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cliente_sistema', function (Blueprint $table) {
            $table->dropColumn('status_saas');
        });
    }
};
