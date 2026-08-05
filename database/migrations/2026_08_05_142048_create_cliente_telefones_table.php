<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cliente_telefones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('telefone', 30);
            $table->boolean('principal')->default(false);
            $table->timestamps();
        });

        $clientes = \DB::table('clientes')->whereNotNull('telefone')->where('telefone', '!=', '')->get(['id', 'telefone']);
        foreach ($clientes as $cliente) {
            \DB::table('cliente_telefones')->insert([
                'cliente_id' => $cliente->id,
                'telefone' => $cliente->telefone,
                'principal' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('clientes', function (Blueprint $table) {
            $table->dropColumn('telefone');
        });
    }

    public function down(): void
    {
        Schema::table('clientes', function (Blueprint $table) {
            $table->string('telefone')->nullable();
        });

        Schema::dropIfExists('cliente_telefones');
    }
};
