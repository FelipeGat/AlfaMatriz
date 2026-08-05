<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contas_pagar', function (Blueprint $table) {
            $table->foreignId('conta_fixa_pagar_id')->nullable()->after('id')->constrained('contas_fixas_pagar')->nullOnDelete();
            $table->string('competencia', 7)->nullable()->after('tipo')->comment('formato AAAA-MM');
        });
    }

    public function down(): void
    {
        Schema::table('contas_pagar', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conta_fixa_pagar_id');
            $table->dropColumn('competencia');
        });
    }
};
