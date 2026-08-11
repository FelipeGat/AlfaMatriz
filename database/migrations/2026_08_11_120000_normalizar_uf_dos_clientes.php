<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Põe em maiúscula a UF que já está gravada.
 *
 * O formulário sempre exibiu a UF em caixa alta por CSS (`text-transform`), que
 * não altera o valor enviado: quem digitava "es" via "ES" na tela e gravava
 * "es". O model agora normaliza na escrita, mas isso só vale daqui pra frente —
 * o que já está no banco continua misturado.
 *
 * A atualização é incondicional de propósito. A tentação seria filtrar por
 * `uf <> UPPER(uf)`, só que a collation daqui é utf8mb4_unicode_ci: "es" e "ES"
 * são iguais para o MySQL, a comparação nunca dá verdadeiro e o UPDATE não
 * pegaria linha nenhuma. Comparar exige BINARY, e não vale a complicação num
 * campo de duas letras.
 *
 * Sem `down`: a caixa original não é recuperável depois de sobrescrita, e
 * fingir o contrário seria pior que assumir. De todo modo, "es" e "ES"
 * significam a mesma coisa — não há informação a perder na volta.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('clientes')
            ->whereNotNull('uf')
            ->update(['uf' => DB::raw('UPPER(TRIM(uf))')]);
    }

    public function down(): void
    {
        //
    }
};
