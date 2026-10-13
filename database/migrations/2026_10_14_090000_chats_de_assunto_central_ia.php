<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chats Business (docs/specs/chats-business): o chat de assunto é uma tarefa
 * da Central de outro tipo — reaproveita fila, dossiê, Telegram e avisos — com
 * grupo, e que nunca se conclui sozinha. O Telegram ganha o ponteiro para um
 * assunto (`/assunto`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('central_ia_tarefas', function (Blueprint $t) {
            $t->string('tipo', 20)->default('tarefa');
            $t->foreignId('grupo_id')->nullable()->constrained('central_ia_grupos')->nullOnDelete();
            $t->index(['user_id', 'tipo', 'status']);
        });

        Schema::table('central_ia_telegram_vinculos', function (Blueprint $t) {
            $t->foreignId('assunto_atual_id')->nullable()->constrained('central_ia_tarefas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('central_ia_telegram_vinculos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('assunto_atual_id');
        });

        Schema::table('central_ia_tarefas', function (Blueprint $t) {
            $t->dropIndex(['user_id', 'tipo', 'status']);
            $t->dropConstrainedForeignId('grupo_id');
            $t->dropColumn('tipo');
        });
    }
};
