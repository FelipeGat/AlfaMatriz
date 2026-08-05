<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('revenda_id')->nullable()->constrained('revendas')->nullOnDelete();
            $table->string('nome');
            $table->string('nome_fantasia')->nullable();
            $table->string('razao_social')->nullable();
            $table->string('cpf_cnpj', 18)->nullable();
            $table->enum('tipo_pessoa', ['PF', 'PJ'])->default('PJ');
            $table->string('email')->nullable();
            $table->string('telefone')->nullable();
            $table->string('cidade')->nullable();
            $table->string('uf', 2)->nullable();
            $table->boolean('ativo')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index('cpf_cnpj');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
