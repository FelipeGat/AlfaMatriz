<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O que a Matriz pode fazer com um sistema deixa de ser deduzido do slug e
 * passa a ser declarado na linha dele.
 *
 * Até aqui o código perguntava "esse sistema é o alfagym?" em oito lugares para
 * responder, na verdade, "esse sistema sabe gerenciar licença?". Com mais de um
 * sistema integrado a pergunta pelo slug passa a dar a resposta errada.
 *
 * O backfill mora AQUI e não no seeder de propósito: `deploy/publicar.sh` roda
 * só `migrate --force`. Se a lista viesse do seeder, o AlfaGym em produção
 * acordaria sem capacidade nenhuma e as ações de licença sumiriam da tela.
 */
return new class extends Migration
{
    /**
     * O que cada sistema já integrado sabe fazer hoje. O AlfaGym recebe
     * exatamente o conjunto que reproduz o comportamento atual — a migration é
     * de dados, não de comportamento.
     *
     * O AlfaControl entra só com leitura: durante a implantação quem opera
     * revenda, cliente, licença e módulo continua sendo o painel dele.
     *
     * @var array<string, array<int, string>>
     */
    private const CAPACIDADES = [
        'alfagym' => [
            'sincroniza',
            'provisiona_revenda',
            'provisiona_cliente',
            'exige_admin_no_cliente',
            'gerencia_licenca',
        ],
        'alfacontrol' => [
            'sincroniza',
            'sincroniza_modulos',
        ],
    ];

    public function up(): void
    {
        Schema::table('sistemas', function (Blueprint $table) {
            $table->json('capacidades')->nullable()->after('token');
        });

        foreach (self::CAPACIDADES as $slug => $capacidades) {
            DB::table('sistemas')
                ->where('slug', $slug)
                ->update(['capacidades' => json_encode($capacidades)]);
        }

        // Quem não está na lista não sabe fazer nada pela Matriz — é o padrão
        // seguro: um sistema novo não ganha poder por descuido.
        DB::table('sistemas')->whereNull('capacidades')->update(['capacidades' => json_encode([])]);
    }

    public function down(): void
    {
        Schema::table('sistemas', fn (Blueprint $table) => $table->dropColumn('capacidades'));
    }
};
