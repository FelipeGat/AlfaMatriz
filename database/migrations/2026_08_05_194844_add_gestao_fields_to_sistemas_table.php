<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sistemas', function (Blueprint $table) {
            $table->string('versao')->nullable()->after('base_url');
            $table->string('responsavel')->nullable()->after('versao');
            $table->text('roadmap')->nullable()->after('responsavel');
        });
    }

    public function down(): void
    {
        Schema::table('sistemas', function (Blueprint $table) {
            $table->dropColumn(['versao', 'responsavel', 'roadmap']);
        });
    }
};
