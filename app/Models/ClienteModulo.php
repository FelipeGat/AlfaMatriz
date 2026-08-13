<?php

namespace App\Models;

use App\Concerns\Auditavel;
use App\Concerns\ComOrigemExterna;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A contratação de um módulo por um cliente.
 *
 * Usa `origens_externas` para a âncora em vez de coluna própria: é o padrão
 * que a migration `2026_08_08_090000` estabeleceu, e a Fase 2 (ativar/suspender
 * módulo pela Matriz) precisa do id externo desta linha.
 */
class ClienteModulo extends Model
{
    use Auditavel;

    protected string $recursoAuditoria = 'clientes';

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
    /**
     * As contratações de um sistema, entre estes clientes, vigentes na
     * competência.
     *
     * Origem única da regra: ela decide receita em três lugares — a fatura que
     * o fechamento gera, a prévia que a tela de Faturamento mostra antes de
     * gerar, e o MRR de atacado dos painéis. Enquanto morava só dentro do
     * motor de faturamento, os outros dois simplesmente não somavam módulo, e
     * a prévia mostrava menos do que o fechamento ia cobrar.
     *
     * Só `status = ativo`: contratação suspensa ou encerrada não é receita
     * corrente.
     *
     * @param  Collection<int, int>|array<int, int>  $clienteIds
     * @return Collection<int, ClienteModulo>
     */
    public static function vigentesNaCompetencia(int $sistemaId, $clienteIds, string $competencia): Collection
    {
        $clienteIds = collect($clienteIds);

        if ($clienteIds->isEmpty()) {
            return collect();
        }

        $inicioDoMes = Carbon::createFromFormat('Y-m', $competencia)->startOfMonth();

        return static::query()
            ->with('modulo')
            ->whereIn('cliente_id', $clienteIds)
            ->where('status', 'ativo')
            ->whereHas('modulo', fn ($q) => $q->where('sistema_id', $sistemaId))
            ->get()
            ->filter(fn (self $c) => $c->vigenteEm($inicioDoMes))
            ->values();
    }

    public function vigenteEm(CarbonInterface $inicioDoMes): bool
    {
        $fimDoMes = $inicioDoMes->copy()->endOfMonth();

        if ($this->data_inicio && $this->data_inicio->gt($fimDoMes)) {
            return false;
        }

        return ! ($this->data_fim && $this->data_fim->lt($inicioDoMes));
    }

    /**
     * Quem contratou o quê. Sem os dois nomes a linha diria "ClienteModulo
     * #412", e a pergunta que se faz aqui é sempre sobre o par.
     */
    public function descricaoDeAuditoria(): string
    {
        return trim(($this->cliente->nome ?? 'cliente ?').' · '.($this->modulo->nome ?? 'módulo ?'));
    }
}
