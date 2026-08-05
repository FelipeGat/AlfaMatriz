<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cliente_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('email');
            $table->boolean('principal')->default(false);
            $table->boolean('financeiro')->default(false);
            $table->timestamps();
        });

        // Migra o e-mail único existente (se houver) como principal+financeiro.
        $clientes = \DB::table('clientes')->whereNotNull('email')->where('email', '!=', '')->get(['id', 'email']);
        foreach ($clientes as $cliente) {
            \DB::table('cliente_emails')->insert([
                'cliente_id' => $cliente->id,
                'email' => $cliente->email,
                'principal' => true,
                'financeiro' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('email')->nullable();
        });

        Schema::dropIfExists('cliente_emails');
    }
};
