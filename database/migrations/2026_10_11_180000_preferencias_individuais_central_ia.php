<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('central_ia_preferencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('regras');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_ia_preferencias');
    }
};
