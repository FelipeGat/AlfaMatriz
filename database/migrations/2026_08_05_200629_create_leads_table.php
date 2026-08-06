<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('cpf_cnpj', 18)->nullable();
            $table->string('email')->nullable();
            $table->string('telefone')->nullable();
            $table->foreignId('revenda_id')->nullable()->constrained('revendas')->nullOnDelete();
            $table->foreignId('sistema_id')->nullable()->constrained('sistemas')->nullOnDelete();
            $table->enum('tipo_interesse', ['saas', 'site', 'app', 'consultoria', 'marketing', 'outro'])->default('saas');
            $table->string('origem')->nullable()->comment('Google, Facebook, Instagram, Indicação, Site, WhatsApp...');
            $table->string('estagio')->default('lead');
            $table->timestamp('estagio_atualizado_em')->nullable();
            $table->decimal('valor_estimado', 12, 2)->nullable();
            $table->string('motivo_perda')->nullable();
            $table->text('observacoes')->nullable();
            $table->foreignId('vendedor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index('estagio');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
