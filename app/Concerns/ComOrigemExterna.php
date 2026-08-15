<?php

namespace App\Concerns;

use App\Models\OrigemExterna;
use App\Models\Sistema;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Resolução de uma entidade local pela âncora num sistema externo.
 *
 * A âncora vive em `origens_externas` (entidade + sistema + id_externo),
 * então o mesmo registro pode ser referenciado por vários sistemas sem
 * colidir — e a sincronização de cada sistema só enxerga a própria âncora.
 */
trait ComOrigemExterna
{
    /**
     * O registro desta entidade que existe na origem `sistema` com o
     * `id_externo` dado, ou null.
     *
     * `$comExcluidos` é para a sincronização, e só para PULAR: a âncora de
     * uma revenda excluída continua ocupando (sistema, id_externo), e não
     * enxergar a entidade atrás dela mandava o ciclo criar uma gêmea — que
     * colidia na âncora e derrubava tudo. A reconciliação por documento não
     * cobre esse caso: a excluída pode não ter documento nenhum.
     */
    public static function porOrigemExterna(Sistema $sistema, string $idExterno, bool $comExcluidos = false): ?static
    {
        $origem = OrigemExterna::query()
            ->where('entidade_type', static::class)
            ->where('entidade_id', '!=', 0)
            ->where('sistema_id', $sistema->id)
            ->where('id_externo', $idExterno)
            ->first();

        if (! $origem) {
            return null;
        }

        // Pelo trait, e não por `method_exists`: `withTrashed` é macro que o
        // SoftDeletes registra no BUILDER, não método da classe — perguntar à
        // classe responde não para todo mundo, e o ramo nunca rodaria.
        $query = $comExcluidos && in_array(SoftDeletes::class, class_uses_recursive(static::class), true)
            ? static::withTrashed()
            : static::query();

        return $query->find($origem->entidade_id);
    }

    /**
     * Vincula este registro à origem `sistema`/`id_externo` (createOrUpdate).
     */
    public function ancorarEm(Sistema $sistema, string $idExterno): OrigemExterna
    {
        return OrigemExterna::query()->updateOrCreate(
            ['entidade_type' => static::class, 'entidade_id' => $this->id, 'sistema_id' => $sistema->id],
            ['id_externo' => $idExterno]
        );
    }

    public function idExternoNoSistema(Sistema $sistema): ?string
    {
        return OrigemExterna::query()
            ->where('entidade_type', static::class)
            ->where('entidade_id', $this->id)
            ->where('sistema_id', $sistema->id)
            ->value('id_externo');
    }
}
