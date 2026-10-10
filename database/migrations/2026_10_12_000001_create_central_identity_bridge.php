<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_ia_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->uuid('subject')->unique();
            $table->unsignedBigInteger('session_epoch')->default(1);
            $table->char('access_fingerprint', 64);
            $table->timestamps();
        });
        Schema::create('central_ia_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained('central_ia_subjects')->restrictOnDelete();
            $table->char('ticket_hash', 64)->unique();
            $table->char('flow_hash', 64);
            $table->string('audience');
            $table->unsignedBigInteger('session_epoch');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
        // Extend the existing permission vocabulary; grant nothing automatically.
        foreach (['central_ia' => 'Central de IA: conversa privada e integrações', 'central_ia_execucao' => 'Central de IA: executar agentes'] as $resource => $description) {
            DB::table('permissoes')->insertOrIgnore(['recurso' => $resource, 'descricao' => $description, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Identity bridge rollback must preserve subjects and existing grants.');
    }
};
