<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Produto da VixCard (Felipe, 17/10/2026): a Alfa desenvolve, a VixCard
     * vende. Terceira natureza, fora de `Sistema::produtos()` — não entra em
     * MRR, tier nem fechamento —, para as tarefas apontarem para ele.
     */
    public function up(): void
    {
        Schema::table('sistemas', function (Blueprint $table) {
            $table->enum('natureza', ['produto', 'interno', 'vixcard'])->default('produto')->change();
        });
    }

    public function down(): void
    {
        DB::table('sistemas')->where('natureza', 'vixcard')->update(['natureza' => 'interno']);
        Schema::table('sistemas', function (Blueprint $table) {
            $table->enum('natureza', ['produto', 'interno'])->default('produto')->change();
        });
    }
};
