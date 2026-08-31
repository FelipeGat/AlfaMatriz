<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A licença do AlfaControl passa a ser gerenciada pela Matriz.
 *
 * A migração de capacidades de 11/08 deixou o AlfaControl "só com leitura:
 * durante a implantação quem opera revenda, cliente, licença e módulo continua
 * sendo o painel dele". A implantação venceu essa fase: o espelho de licenças
 * roda desde a virada de `sincroniza_licencas`, e o contrato dele ganhou a
 * escrita (POST /licencas, /renovar, /bloquear, /desbloquear) — com a trava de
 * vigência que recusa renovar por cima de dado velho.
 *
 * Esta migração só entra em produção DEPOIS do deploy do AlfaControl com a
 * escrita: com a capacidade ligada antes do outro lado existir, a tela oferece
 * botões que respondem 404.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ajustar('alfacontrol', fn (array $caps) => array_values(array_unique([...$caps, 'gerencia_licenca'])));
    }

    public function down(): void
    {
        $this->ajustar('alfacontrol', fn (array $caps) => array_values(array_diff($caps, ['gerencia_licenca'])));
    }

    private function ajustar(string $slug, callable $transformar): void
    {
        $atual = DB::table('sistemas')->where('slug', $slug)->value('capacidades');

        if ($atual === null) {
            return;
        }

        DB::table('sistemas')->where('slug', $slug)->update([
            'capacidades' => json_encode($transformar(json_decode($atual, true) ?: [])),
        ]);
    }
};
