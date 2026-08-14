<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Editar deixa de ser um efeito colateral de incluir.
     *
     * A grade de perfis tinha quatro caixas e `ChecarPermissao` lia o verbo
     * HTTP: `POST`, `PUT` e `PATCH` caíam todos em `incluir`. Na prática, quem
     * podia cadastrar um lead podia reescrever todos os leads existentes — e a
     * grade não tinha como dizer o contrário, porque a distinção não existia
     * nem como coluna.
     *
     * Com a coluna, "registrar o que chega" e "mexer no que já está
     * registrado" viram duas decisões separadas, que é o que o dono do produto
     * pediu em 14/08/2026: poder total só do perfil Administrador.
     */
    public function up(): void
    {
        Schema::table('perfil_permissao', function (Blueprint $table) {
            // Depois de `incluir` de propósito: a ordem da tabela é a ordem em
            // que a grade é lida na tela, e editar mora ao lado do irmão de
            // quem acabou de se separar.
            $table->boolean('editar')->default(false)->after('incluir');
        });

        $this->herdarDeIncluir();
    }

    /**
     * Todo perfil que hoje inclui passa a editar também.
     *
     * Sem isto, a publicação tira a edição do sistema inteiro de uma vez: a
     * coluna nasce `false`, `ChecarPermissao` passa a exigi-la no mesmo
     * instante, e toda tela de edição responde 403 até alguém abrir a grade e
     * remarcar perfil por perfil. O time descobriria a mudança tentando
     * trabalhar.
     *
     * A separação que interessa é a que passa a EXISTIR — quem tira de quem é
     * decisão de operação, tomada na grade, com calma, depois. É por isso que o
     * backfill copia em vez de zerar: ele preserva exatamente o que valia
     * ontem, e o dia seguinte é que fica diferente.
     *
     * Vale também para o `admin`, que tem `incluir` em tudo e por isso herda
     * `editar` em tudo — o perfil é imutável na tela, então ele não teria outro
     * caminho para receber a ação nova.
     */
    private function herdarDeIncluir(): void
    {
        DB::table('perfil_permissao')->where('incluir', true)->update(['editar' => true]);
    }

    public function down(): void
    {
        Schema::table('perfil_permissao', function (Blueprint $table) {
            $table->dropColumn('editar');
        });
    }
};
