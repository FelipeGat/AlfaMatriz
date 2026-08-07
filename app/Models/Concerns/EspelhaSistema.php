<?php

namespace App\Models\Concerns;

use App\Models\Sistema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * Comportamento comum a tudo que é retrato local de um sistema.
 *
 * Toda tabela de retrato tem a mesma forma: pertence a um sistema, é
 * identificada pelo que o sistema chama de identificador, guarda a resposta
 * crua e sabe dizer se aquele registro sumiu na origem.
 *
 * REGRA CENTRAL: registro que some na origem é MARCADO, nunca apagado. Apagar
 * levaria junto o vínculo com o cliente da matriz e o histórico do que já foi
 * faturado em cima dele.
 */
trait EspelhaSistema
{
    /** @var array<string, bool> colunas `ativo` já descobertas por tabela */
    private static array $temColunaAtivo = [];

    public function sistema(): BelongsTo
    {
        return $this->belongsTo(Sistema::class);
    }

    /** Só o que continua existindo no sistema de origem. */
    public function scopePresentes(Builder $consulta): Builder
    {
        return $consulta->whereNull('ausente_em_origem_em');
    }

    public function scopeDoSistema(Builder $consulta, Sistema|int $sistema): Builder
    {
        return $consulta->where('sistema_id', $sistema instanceof Sistema ? $sistema->id : $sistema);
    }

    public function ausenteNaOrigem(): bool
    {
        return $this->ausente_em_origem_em !== null;
    }

    /**
     * O registro deixou de aparecer na origem.
     *
     * Só deve ser chamado depois que a varredura daquele escopo terminou COM
     * SUCESSO — senão uma falha no meio da leitura marca como ausente tudo que
     * ainda não tinha sido lido.
     */
    public function marcarAusenteNaOrigem(): void
    {
        if ($this->ausenteNaOrigem()) {
            return;
        }

        $campos = ['ausente_em_origem_em' => now()];

        // Nem todo retrato tem situação liga/desliga: a licença é controlada
        // pelo `status`, não por um `ativo`. A ausência dela marca a data e
        // preserva a situação que o sistema declarou na última leitura.
        if ($this->temColunaAtivo()) {
            $campos['ativo'] = false;
        }

        $this->forceFill($campos)->save();
    }

    /** A tabela deste retrato tem a coluna `ativo` de verdade? */
    private function temColunaAtivo(): bool
    {
        $tabela = $this->getTable();

        return self::$temColunaAtivo[$tabela] ??= Schema::hasColumn($tabela, 'ativo');
    }

    /** O registro voltou a aparecer: a ausência era temporária. */
    public function marcarPresenteNaOrigem(): void
    {
        if (! $this->ausenteNaOrigem()) {
            return;
        }

        $this->forceFill(['ausente_em_origem_em' => null])->save();
    }
}
