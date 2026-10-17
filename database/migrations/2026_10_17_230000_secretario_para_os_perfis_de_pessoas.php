<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * O Secretário para todos os perfis de pessoas (decisão do Felipe em
     * 17/10/2026, depois da Etapa 7). Cada conta tem o SEU: tudo é escopado
     * pelo `user_id`, e ninguém — administrador incluído — vê o de outra.
     * Ficam de fora Revenda (cliente externo) e Exibição (o monitor da parede).
     */
    private const PERFIS = ['admin', 'financeiro', 'operacao', 'membro', 'comercial'];

    public function up(): void
    {
        $permissaoId = DB::table('permissoes')->where('recurso', 'secretario')->value('id');
        if (! $permissaoId) {
            return;
        }
        foreach (DB::table('perfis')->whereIn('slug', self::PERFIS)->pluck('id') as $perfilId) {
            DB::table('perfil_permissao')->updateOrInsert(
                ['perfil_id' => $perfilId, 'permissao_id' => $permissaoId],
                ['ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => false, 'excluir' => true],
            );
        }
    }

    public function down(): void
    {
        $permissaoId = DB::table('permissoes')->where('recurso', 'secretario')->value('id');
        if ($permissaoId) {
            DB::table('perfil_permissao')->where('permissao_id', $permissaoId)
                ->whereIn('perfil_id', DB::table('perfis')->whereIn('slug', self::PERFIS)->pluck('id'))->delete();
        }
    }
};
