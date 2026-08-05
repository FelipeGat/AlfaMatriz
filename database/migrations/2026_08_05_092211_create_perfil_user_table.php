<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfil_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('perfil_id')->constrained('perfis')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'perfil_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfil_user');
    }
};
