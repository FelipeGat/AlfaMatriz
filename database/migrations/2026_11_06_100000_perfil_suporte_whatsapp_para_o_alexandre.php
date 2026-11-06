<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * O Alexandre opera o Pedro pelo Matriz (Felipe, 06/11/2026).
     *
     * A v2026.11.06.1 o pôs na lista de quem administra, mas a lista não abre a
     * porta: a aba Suporte inteira pede o recurso `suporte`, e a conta dele é só
     * "Membro do time", que não o tem — o 403 que ele viu.
     *
     * Perfil próprio, em vez dos dois atalhos: o Administrador daria junto o
     * caixa, os usuários e a auditoria; a grade do Membro daria as conversas dos
     * clientes a qualquer membro que entrar depois. Só `ler` e `editar`, as duas
     * únicas ações que as rotas do Suporte pedem.
     *
     * Vai em migração porque o deploy roda só `migrate --force`.
     */
    public function up(): void
    {
        $perfilId = DB::table('perfis')->where('slug', 'suporte_whatsapp')->value('id')
            ?? DB::table('perfis')->insertGetId(['nome' => 'Suporte WhatsApp', 'slug' => 'suporte_whatsapp', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('perfil_permissao')->updateOrInsert(
            ['perfil_id' => $perfilId, 'permissao_id' => DB::table('permissoes')->where('recurso', 'suporte')->value('id')],
            ['ler' => true, 'incluir' => false, 'editar' => true, 'imprimir' => false, 'excluir' => false],
        );

        // Pelo e-mail, o mesmo da lista de administradores: quem não existir neste banco simplesmente não recebe.
        foreach (DB::table('users')->where('email', 'alexandreblankl@outlook.com')->pluck('id') as $userId) {
            DB::table('perfil_user')->updateOrInsert(['perfil_id' => $perfilId, 'user_id' => $userId]);
        }
    }

    public function down(): void
    {
        // A conta fica, com os perfis que já tinha; só este sai de perto dela.
        if ($perfilId = DB::table('perfis')->where('slug', 'suporte_whatsapp')->value('id')) {
            DB::table('perfil_permissao')->where('perfil_id', $perfilId)->delete();
            DB::table('perfil_user')->where('perfil_id', $perfilId)->delete();
            DB::table('perfis')->where('id', $perfilId)->delete();
        }
    }
};
