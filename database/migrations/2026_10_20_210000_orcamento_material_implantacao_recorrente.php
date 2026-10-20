<?php

use App\Models\OrcamentoItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Material, implantação e recorrente separados (Felipe, 20/10/2026: "tudo que
     * for recorrente precisa ser mostrado, o que é material também e implantação
     * também"). Os itens que já existem recebem a categoria provável pela
     * descrição, e os totais são refeitos.
     */
    public function up(): void
    {
        Schema::table('orcamento_itens', function (Blueprint $table) {
            $table->string('categoria', 12)->nullable()->after('cobranca');
        });
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->decimal('total_material', 12, 2)->default(0)->after('total_unico');
            $table->decimal('total_implantacao', 12, 2)->default(0)->after('total_material');
        });
        foreach (DB::table('orcamento_itens')->get(['id', 'descricao', 'cobranca']) as $i) {
            DB::table('orcamento_itens')->where('id', $i->id)->update(['categoria' => OrcamentoItem::categoriaProvavel((string) $i->descricao, $i->cobranca)]);
        }
        foreach (DB::table('orcamentos')->pluck('id') as $id) {
            $soma = fn (string $cat) => (float) DB::table('orcamento_itens')->where('orcamento_id', $id)->where('cobranca', 'unico')->where('categoria', $cat)
                ->sum(DB::raw('ROUND(quantidade * valor_unitario, 2)'));
            DB::table('orcamentos')->where('id', $id)->update(['total_material' => round($soma('material'), 2), 'total_implantacao' => round($soma('implantacao'), 2)]);
        }
    }

    public function down(): void
    {
        Schema::table('orcamento_itens', fn (Blueprint $t) => $t->dropColumn('categoria'));
        Schema::table('orcamentos', fn (Blueprint $t) => $t->dropColumn(['total_material', 'total_implantacao']));
    }
};
