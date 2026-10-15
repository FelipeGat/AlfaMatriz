<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada pessoa usa o PRÓPRIO bot do Telegram na Central (T-374, decisão do dono
 * em 14/10/2026). Antes era um bot só, no `.env`, criado na conta pessoal de
 * quem montou a Central. Esta tabela guarda o bot da CONVERSA de cada um. O
 * código de confirmação continua saindo por um segundo bot da pessoa, cujo
 * token fica só na máquina dela e nunca passa por aqui (T-368; revisão da
 * T-374, 15/10/2026: este texto dizia, por engano, que o mesmo bot levaria o
 * código).
 *
 * - `token` é cifrado com a APP_KEY (cast `encrypted` no modelo).
 * - `telegram_bot_id` único: o mesmo bot em duas contas misturaria as
 *   conversas.
 * - `servidor` é o hash do APP_URL de quem cadastrou. O ambiente local roda
 *   com cópia do banco de produção: sem essa marca, o processo do bot de lá
 *   leria os bots de todo mundo e derrubaria o da produção com 409.
 * - O cursor (`proximo_update_id`) passa a ser por bot; o
 *   `central_ia_telegram_cursor` antigo fica sem uso e sai numa publicação
 *   posterior.
 *
 * O número do update é de cada bot: dois bots mandam o update 10 e o segundo
 * era descartado como repetição. O único de `central_ia_mensagens` passa a ser
 * por pessoa (um bot por pessoa).
 *
 * Sem backfill: o bot antigo vinha do `.env` e não tinha dono. O vínculo
 * (`central_ia_telegram_vinculos`) continua: o id de um chat privado é o id da
 * pessoa no Telegram, igual em qualquer bot, então quem cadastrar o mesmo bot
 * de antes segue com a conversa ligada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_ia_telegram_bots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('telegram_bot_id')->unique();
            $t->string('usuario', 64);
            $t->text('token');
            $t->char('servidor', 64)->index();
            $t->unsignedBigInteger('proximo_update_id')->default(0);
            // ok | token_invalido | webhook | conflito — o que a tela mostra.
            $t->string('situacao', 16)->default('ok');
            $t->timestamp('situacao_em')->nullable();
            $t->timestamps();
        });

        Schema::table('central_ia_mensagens', function (Blueprint $t) {
            $t->dropUnique(['telegram_update_id']);
            $t->unique(['user_id', 'telegram_update_id']);
        });
    }

    public function down(): void
    {
        Schema::table('central_ia_mensagens', function (Blueprint $t) {
            $t->dropUnique(['user_id', 'telegram_update_id']);
            $t->unique(['telegram_update_id']);
        });
        Schema::dropIfExists('central_ia_telegram_bots');
    }
};
