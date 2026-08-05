<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar')->nullable()->after('email');
            $table->boolean('primeiro_acesso')->default(true)->after('avatar');
            $table->boolean('ativo')->default(true)->after('primeiro_acesso');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar', 'primeiro_acesso', 'ativo', 'deleted_at']);
        });
    }
};
