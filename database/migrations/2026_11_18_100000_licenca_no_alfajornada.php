<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O AlfaJornada passa a ter a licença espelhada e gerenciada pela Matriz (T-598).
 *
 * Até aqui ele só entrava no ciclo pelo uso (`sincroniza_uso`): a origem não
 * tinha o lado de licença do contrato. A T-583 publicou esse lado no Jornada
 * (v2026.11.10) — GET /licencas e a escrita (POST /licencas, /renovar,
 * /bloquear, /desbloquear) — e a conferência no ar deu 200 com 0 linhas, o
 * esperado antes do primeiro "liberar".
 *
 * As duas capacidades entram juntas, ao contrário do AlfaControl (que ligou a
 * leitura antes e a escrita semanas depois, em 31/08): lá a escrita ainda não
 * existia na origem; aqui as duas já estão no ar. Como lá, esta migração só
 * pode ir para produção DEPOIS do deploy do outro lado — ligada antes, a tela
 * oferece botões que respondem 404.
 *
 * Mesmo array_unique/array_diff das migrações de 31/08: rodar sobre um banco
 * em que alguém já ligou à mão não duplica, e o down() só tira o que é dela.
 */
return new class extends Migration
{
    private const CAPACIDADES = ['sincroniza_licencas', 'gerencia_licenca'];

    public function up(): void
    {
        $this->ajustar('alfajornada', fn (array $caps) => array_values(array_unique([...$caps, ...self::CAPACIDADES])));
    }

    public function down(): void
    {
        $this->ajustar('alfajornada', fn (array $caps) => array_values(array_diff($caps, self::CAPACIDADES)));
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
