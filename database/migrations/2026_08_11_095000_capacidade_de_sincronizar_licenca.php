<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ler licença vira capacidade própria, separada de `sincroniza`.
 *
 * Até aqui, quem sincronizava puxava tudo: revendas, clientes e licenças. Mas
 * um sistema pode ter o cadastro em ordem e a licença não — é o caso do
 * AlfaControl, onde renovar encadeia licenças ativas em vez de substituir a
 * anterior. Puxar esse retrato faria a Matriz espelhar o defeito e, pior,
 * faturar em cima dele.
 *
 * O AlfaGym ganha a capacidade porque é o que ele já fazia. O AlfaControl fica
 * sem: sincroniza revenda, cliente e módulo, e a licença dele não é lida até o
 * problema na origem estar resolvido. Ligar depois é uma linha no banco.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ajustar('alfagym', fn (array $caps) => array_values(array_unique([...$caps, 'sincroniza_licencas'])));
    }

    public function down(): void
    {
        $this->ajustar('alfagym', fn (array $caps) => array_values(array_diff($caps, ['sincroniza_licencas'])));
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
