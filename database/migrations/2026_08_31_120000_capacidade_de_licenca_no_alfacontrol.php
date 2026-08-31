<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Oficializa o que produção já ligou à mão: o AlfaControl lê licenças.
 *
 * A migração de 11/08 deixou o AlfaControl sem `sincroniza_licencas` de
 * propósito — a renovação na origem encadeava licenças ativas — e anotou que
 * "ligar depois é uma linha no banco". A linha foi executada direto no banco
 * de produção, e o ciclo horário espelha as licenças dele desde então. Mas
 * decisão que só existe numa UPDATE à mão some num banco recriado do zero:
 * aqui ela vira código.
 *
 * Em produção o up() é inócuo — a capacidade já está lá e o array_unique
 * devolve o mesmo conjunto.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ajustar('alfacontrol', fn (array $caps) => array_values(array_unique([...$caps, 'sincroniza_licencas'])));
    }

    public function down(): void
    {
        $this->ajustar('alfacontrol', fn (array $caps) => array_values(array_diff($caps, ['sincroniza_licencas'])));
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
