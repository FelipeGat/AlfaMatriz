<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O Telegram como segundo canal da MESMA conversa.
     *
     * Um chat privado por conta e uma conta por chat: os dois únicos impedem
     * que a mensagem de uma pessoa caia na conversa de outra por um pareamento
     * repetido. O código de pareamento guarda só o hash — quem lê o banco não
     * consegue parear o próprio Telegram na conta alheia.
     */
    public function up(): void
    {
        Schema::create('central_ia_telegram_vinculos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('chat_id')->unique();
            $table->string('telegram_usuario')->nullable();
            $table->timestamps();
        });

        Schema::create('central_ia_telegram_codigos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->char('codigo_hash', 64)->unique();
            $table->timestamp('expira_em');
            $table->timestamps();
        });

        // Até onde o bot já leu. Fica no banco, e não em cache, porque perder o
        // cursor é reler updates — e a reentrega quem segura é o único de
        // `central_ia_mensagens.telegram_update_id`, não o cursor.
        Schema::create('central_ia_telegram_cursor', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('proximo_update_id')->default(0);
            $table->timestamps();
        });

        // Uma entrega por mensagem. `incerta` é a mensagem que pode ter
        // chegado (a resposta do Telegram se perdeu): ela NÃO é reenviada
        // sozinha, porque mensagem dobrada no celular é pior que uma faltando
        // — e a conversa inteira continua na tela.
        Schema::create('central_ia_entregas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mensagem_id')->unique()->constrained('central_ia_mensagens')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('chat_id');
            $table->string('status', 16)->default('pendente');
            $table->unsignedSmallInteger('tentativas')->default(0);
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->string('erro', 64)->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_ia_entregas');
        Schema::dropIfExists('central_ia_telegram_cursor');
        Schema::dropIfExists('central_ia_telegram_codigos');
        Schema::dropIfExists('central_ia_telegram_vinculos');
    }
};
