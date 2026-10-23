<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A reunião gravada sabe qual compromisso da Agenda ela é (Felipe, 22/10/2026: a da Medsenior foi
     * gravada sem estar na agenda). Apagar o compromisso só desfaz o vínculo.
     */
    public function up(): void
    {
        Schema::table('secretario_reunioes', function (Blueprint $table) {
            $table->foreignId('compromisso_id')->nullable()->after('sistema_id')->constrained('compromissos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('secretario_reunioes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('compromisso_id');
        });
    }
};
