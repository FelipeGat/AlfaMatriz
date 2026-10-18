<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Perfil VixCard (docs/specs/acesso-vixcard, 18/10/2026): o Victor acompanha
     * e opera os projetos da VixCard — quadro e Agenda —, e só eles. O que ele
     * enxerga é recortado pelo escopo global `EscopoVixcard`, ligado a este
     * slug; aqui só as portas: tarefas e agenda, sem excluir nem triagem.
     */
    public function up(): void
    {
        $perfilId = DB::table('perfis')->where('slug', 'vixcard')->value('id')
            ?? DB::table('perfis')->insertGetId(['nome' => 'VixCard', 'slug' => 'vixcard', 'created_at' => now(), 'updated_at' => now()]);

        foreach (['tarefas', 'agenda'] as $recurso) {
            $permissaoId = DB::table('permissoes')->where('recurso', $recurso)->value('id');
            if ($permissaoId) {
                DB::table('perfil_permissao')->updateOrInsert(
                    ['perfil_id' => $perfilId, 'permissao_id' => $permissaoId],
                    ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => false, 'excluir' => false],
                );
            }
        }
    }

    public function down(): void
    {
        if ($perfilId = DB::table('perfis')->where('slug', 'vixcard')->value('id')) {
            DB::table('perfil_permissao')->where('perfil_id', $perfilId)->delete();
            DB::table('perfil_user')->where('perfil_id', $perfilId)->delete();
            DB::table('perfis')->where('id', $perfilId)->delete();
        }
    }
};
