<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * As portas das telas do Monitor (#394, 17/10/2026).
     *
     * Duas, por decisão do dono: "uma pessoa pode ter acesso à parte de
     * monitoramento mas não à parte de assinaturas da Hostinger". Por isso a
     * Hostinger (vencimento de VPS e domínio, plano, renovação) não é aba nem
     * bloco do `monitor`, e sim recurso próprio — o mesmo critério que separou
     * `relatorios` de `dashboard`.
     *
     * `monitor` segue a régua da Manutenção, que é de onde a vitrine do
     * monitor sai: copia a linha REAL de `manutencao` de cada perfil (a grade é
     * editável, e copiar a viva preserva o ajuste feito à mão). Só `ler` e
     * `imprimir` (exportar o relatório) — as telas não escrevem nada.
     *
     * `monitor_hostinger` nasce SÓ no Administrador. É dado de contrato com
     * fornecedor, e quem mais precisar recebe na grade, de propósito; a
     * migração não adivinha.
     *
     * A permissão precisa EXISTIR em produção: o deploy roda só
     * `migrate --force`, e sem esta migração o item do menu nem apareceria.
     */
    public function up(): void
    {
        $monitor = $this->recurso('monitor', 'Monitor (servidores, sistemas e aparelhos de acesso do AlfaMonitor)');
        $hostinger = $this->recurso('monitor_hostinger', 'Monitor — assinaturas e domínios da Hostinger');

        $linhasDaManutencao = DB::table('perfil_permissao')
            ->join('permissoes', 'permissoes.id', '=', 'perfil_permissao.permissao_id')
            ->where('permissoes.recurso', 'manutencao')
            ->where('perfil_permissao.ler', true)
            ->select('perfil_permissao.*')
            ->get();

        foreach ($linhasDaManutencao as $linha) {
            $this->conceder((int) $linha->perfil_id, $monitor, (bool) $linha->imprimir);
        }

        $admin = DB::table('perfis')->where('slug', 'admin')->value('id');

        if ($admin) {
            $this->conceder((int) $admin, $monitor, true);
            $this->conceder((int) $admin, $hostinger, true);
        }
    }

    public function down(): void
    {
        // Os dois nasceram aqui: deixá-los seria linha órfã na grade.
        $ids = DB::table('permissoes')->whereIn('recurso', ['monitor', 'monitor_hostinger'])->pluck('id');

        DB::table('perfil_permissao')->whereIn('permissao_id', $ids)->delete();
        DB::table('permissoes')->whereIn('id', $ids)->delete();
    }

    private function recurso(string $recurso, string $descricao): int
    {
        $id = DB::table('permissoes')->where('recurso', $recurso)->value('id');

        if ($id) {
            return (int) $id;
        }

        // Mesma descrição do `PerfilPermissaoSeeder`: é o rótulo da grade.
        return (int) DB::table('permissoes')->insertGetId([
            'recurso' => $recurso,
            'descricao' => $descricao,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function conceder(int $perfilId, int $permissaoId, bool $imprimir): void
    {
        // Linha que já existe foi ajustada na grade e fica como está.
        $jaExiste = DB::table('perfil_permissao')
            ->where('perfil_id', $perfilId)
            ->where('permissao_id', $permissaoId)
            ->exists();

        if ($jaExiste) {
            return;
        }

        DB::table('perfil_permissao')->insert([
            'perfil_id' => $perfilId,
            'permissao_id' => $permissaoId,
            'ler' => true,
            'incluir' => false,
            'editar' => false,
            'imprimir' => $imprimir,
            'excluir' => false,
        ]);
    }
};
