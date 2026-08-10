<?php

namespace App\Models;

use App\Concerns\ComOrigemExterna;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A contratação de um módulo por um cliente.
 *
 * Usa `origens_externas` para a âncora em vez de coluna própria: é o padrão
 * que a migration `2026_08_08_090000` estabeleceu, e a Fase 2 (ativar/suspender
 * módulo pela Matriz) precisa do id externo desta linha.
 */
class ClienteModulo extends Model
{
    use ComOrigemExterna, HasFactory;

    protected $table = 'cliente_modulo';

    protected $fillable = [
        'cliente_id', 'modulo_id', 'status',
        'data_inicio', 'data_fim', 'valor_mensal', 'observacao',
    ];

    protected function casts(): array
    {
        return [
            'data_inicio' => 'date',
            'data_fim' => 'date',
            'valor_mensal' => 'decimal:2',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(Modulo::class);
    }

    public function ativo(): bool
    {
        return $this->status === 'ativo';
    }

    /**
     * A contratação vale na competência informada (primeiro dia do mês).
     *
     * Começou até o fim do mês e não terminou antes do começo dele — é o que
     * decide se entra na cobrança daquele período.
     */
    public function vigenteEm(\Carbon\CarbonInterface $inicioDoMes): bool
    {
        $fimDoMes = $inicioDoMes->copy()->endOfMonth();

        if ($this->data_inicio && $this->data_inicio->gt($fimDoMes)) {
            return false;
        }

        return ! ($this->data_fim && $this->data_fim->lt($inicioDoMes));
    }
}
