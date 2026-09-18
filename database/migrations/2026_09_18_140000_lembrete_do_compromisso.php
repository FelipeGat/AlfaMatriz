<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quando o lembrete deste compromisso já foi enviado — a marca que impede
     * o aviso de repetir.
     *
     * O lembrete é disparado por um comando que roda de poucos em poucos
     * minutos e varre os compromissos que estão para começar. Sem uma marca de
     * "já avisei", cada passada mandaria o mesmo aviso de novo — a pessoa
     * receberia "sua reunião é daqui a pouco" seis vezes em meia hora. Esta
     * coluna é o carimbo que o comando confere antes de avisar e escreve depois.
     *
     * Ela ZERA quando o compromisso é remarcado (o controller a limpa quando o
     * início muda): uma reunião movida para outro horário é um compromisso novo
     * do ponto de vista do lembrete, e quem foi avisado do horário antigo
     * precisa ser avisado do novo. Editar só o título não zera — o início é que
     * manda.
     */
    public function up(): void
    {
        Schema::table('compromissos', function (Blueprint $table) {
            $table->timestamp('lembrete_enviado_em')->nullable()->after('duracao_horas');
        });
    }

    public function down(): void
    {
        Schema::table('compromissos', function (Blueprint $table) {
            $table->dropColumn('lembrete_enviado_em');
        });
    }
};
