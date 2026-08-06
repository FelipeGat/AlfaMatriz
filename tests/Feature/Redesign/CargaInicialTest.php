<?php

namespace Tests\Feature\Redesign;

use App\Models\Sistema;
use Database\Seeders\SistemasPrecosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O que a carga inicial cria — e o que ela não pode ressuscitar.
 *
 * Produto removido do banco volta no próximo `db:seed` se continuar na carga.
 * O mesmo tipo de armadilha já tinha aparecido no e-mail do administrador
 * (feature gestao-acessos); aqui ela vale para o catálogo.
 */
class CargaInicialTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-049 O catálogo de produtos só tem sistema que existe: a carga
     * inicial não recria o AlfaSchool, removido em 06/08/2026 por não ser um
     * produto real.
     */
    public function test_a_carga_inicial_nao_ressuscita_sistema_removido(): void
    {
        $this->seed(SistemasPrecosSeeder::class);

        $this->assertDatabaseMissing('sistemas', ['slug' => 'alfaschool']);

        // E o que deve existir continua existindo — a remoção não pode ter
        // levado o catálogo junto.
        $this->assertGreaterThanOrEqual(5, Sistema::count());
        foreach (['alfagym', 'alfahome', 'alfajornada'] as $slug) {
            $this->assertDatabaseHas('sistemas', ['slug' => $slug]);
        }
    }
}
