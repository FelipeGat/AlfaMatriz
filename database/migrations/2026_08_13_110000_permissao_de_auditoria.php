<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A porta da tela de auditoria.
     *
     * Recurso próprio, e não uma aba dentro de `usuarios`: quem administra
     * contas não é necessariamente quem pode ler o rastro de todo mundo. E o
     * rastro atravessa os recursos — a mesma tela mostra receita, cliente,
     * salário em despesa e mudança de permissão. Pendurá-la em qualquer
     * permissão existente daria, de graça, a visão do sistema inteiro a quem
     * só tinha a de um pedaço.
     *
     * Nasce SÓ para o administrador, pela mesma razão. Ampliar depois é uma
     * caixa marcada na grade; estreitar depois é tirar de alguém algo que já
     * estava vendo.
     *
     * Vai em migração porque o deploy roda apenas `migrate --force`: recurso
     * que existe só no seeder não chega a produção, e a tela nasceria dando 403
     * para todo mundo, inclusive para o admin.
     */
    public function up(): void
    {
        $permissaoId = DB::table('permissoes')->where('recurso', 'auditoria')->value('id');

        if (! $permissaoId) {
            $permissaoId = DB::table('permissoes')->insertGetId([
                'recurso' => 'auditoria',
                // Mesma descrição do `PerfilPermissaoSeeder`, de propósito: é o
                // rótulo que a grade de permissões mostra, e duas redações
                // fariam a linha mudar de nome conforme quem semeou o banco.
                'descricao' => 'Auditoria (rastro de quem fez o quê)',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $adminId = DB::table('perfis')->where('slug', 'admin')->value('id');

        if (! $adminId) {
            return;
        }

        $jaExiste = DB::table('perfil_permissao')
            ->where('perfil_id', $adminId)
            ->where('permissao_id', $permissaoId)
            ->exists();

        if ($jaExiste) {
            return;
        }

        // `incluir` e `excluir` ficam falsos e não é descuido: a auditoria não
        // tem o que criar nem o que apagar pela tela. A linha existe para o
        // `ler` e para o `imprimir` — e deixar os outros dois ligados sugeriria
        // uma edição que o modelo recusa.
        DB::table('perfil_permissao')->insert([
            'perfil_id' => $adminId,
            'permissao_id' => $permissaoId,
            'ler' => true,
            'incluir' => false,
            'imprimir' => true,
            'excluir' => false,
        ]);
    }

    public function down(): void
    {
        $permissaoId = DB::table('permissoes')->where('recurso', 'auditoria')->value('id');

        if (! $permissaoId) {
            return;
        }

        // Aqui a permissão SAI de vez, ao contrário do que fez a migração do
        // perfil comercial com `clientes` e `revendas`. A diferença é que
        // `auditoria` nasceu com esta migração e não é vocabulário de mais
        // ninguém: deixá-la para trás seria uma linha órfã na grade, oferecendo
        // uma tela que a volta acabou de remover.
        DB::table('perfil_permissao')->where('permissao_id', $permissaoId)->delete();
        DB::table('permissoes')->where('id', $permissaoId)->delete();
    }
};
