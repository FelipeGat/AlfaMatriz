<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ler uso real vira capacidade própria: `sincroniza_uso`.
 *
 * O AlfaControl e o AlfaJornada passam a servir `/api/matriz/v1/uso`; o
 * AlfaGym não — a unidade dele é a academia, que o `/clientes` já cobre.
 * Perguntar `/uso` a quem não o serve daria 404 a cada ciclo, então a leitura
 * é por capacidade declarada, como licenças e módulos.
 *
 * O AlfaJornada ganha também `sincroniza`: é a primeira vez que ele entra no
 * ciclo. Se a linha dele não existir no banco (produção nunca o cadastrou),
 * nada acontece — cadastrá-lo pela tela de Sistemas vem antes de configurar
 * endereço e chave, e as capacidades entram no próximo deploy desta migração
 * já aplicada via seeder de referência ou pela própria tela.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->ajustar('alfacontrol', fn (array $caps) => array_values(array_unique([...$caps, 'sincroniza_uso'])));
        $this->ajustar('alfajornada', fn (array $caps) => array_values(array_unique([...$caps, 'sincroniza', 'sincroniza_uso'])));
    }

    public function down(): void
    {
        $this->ajustar('alfacontrol', fn (array $caps) => array_values(array_diff($caps, ['sincroniza_uso'])));
        $this->ajustar('alfajornada', fn (array $caps) => array_values(array_diff($caps, ['sincroniza', 'sincroniza_uso'])));
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
