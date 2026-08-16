<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A licença de sistema que um cliente final tem contratada — o mesmo papel de
 * `ClienteModulo` (contratação de módulo), só que para o sistema em si.
 */
class ClienteContrato extends Model
{
    use Auditavel, HasFactory;

    protected string $recursoAuditoria = 'clientes';

    protected $table = 'cliente_contratos';

    protected $fillable = [
        'cliente_id', 'sistema_id', 'plano', 'valor_mensal',
        'status', 'data_inicio', 'data_fim', 'detalhamento',
    ];

    protected function casts(): array
    {
        return [
            'valor_mensal' => 'decimal:2',
            'data_inicio' => 'date',
            'data_fim' => 'date',
            'detalhamento' => 'array',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function sistema(): BelongsTo
    {
        return $this->belongsTo(Sistema::class);
    }

    public function ativo(): bool
    {
        return $this->status === 'ativo';
    }

    /**
     * A primeira competência de um contrato, pelo dia em que ele foi
     * fechado — regra do fechamento, 16/08/2026: até o dia 20, a revenda
     * paga a primeira ainda dentro do próprio mês; do dia 21 em diante, a
     * primeira cobrança rola pro mês seguinte.
     *
     * Devolve o primeiro dia do mês certo — `data_inicio` do contrato entra
     * sempre nesse dia 1, nunca no dia real do fechamento, porque
     * `vigenteEm()` decide vigência por mês inteiro, não por dia.
     */
    public static function competenciaInicialPara(CarbonInterface $fechamento): Carbon
    {
        $mes = Carbon::instance($fechamento)->startOfMonth();

        return $fechamento->day <= 20 ? $mes : $mes->addMonth();
    }

    /**
     * O contrato vale na competência informada (primeiro dia do mês) — mesma
     * regra de `ClienteModulo::vigenteEm()`: começou até o fim do mês e não
     * terminou antes do começo dele.
     */
    public function vigenteEm(CarbonInterface $inicioDoMes): bool
    {
        $fimDoMes = $inicioDoMes->copy()->endOfMonth();

        if ($this->data_inicio && $this->data_inicio->gt($fimDoMes)) {
            return false;
        }

        return ! ($this->data_fim && $this->data_fim->lt($inicioDoMes));
    }

    /**
     * Os contratos deste sistema vigentes na competência — origem única da
     * regra, para o fechamento e a prévia nunca discordarem sobre o mesmo
     * contrato.
     *
     * @return Collection<int, ClienteContrato>
     */
    public static function vigentesNaCompetencia(int $sistemaId, string $competencia): Collection
    {
        $inicioDoMes = Carbon::createFromFormat('Y-m', $competencia)->startOfMonth();

        return static::query()
            ->with('cliente')
            ->where('sistema_id', $sistemaId)
            ->where('status', 'ativo')
            ->get()
            ->filter(fn (self $c) => $c->vigenteEm($inicioDoMes))
            ->values();
    }

    /**
     * Quem contratou o quê — a mesma razão de `ClienteModulo::descricaoDeAuditoria()`.
     */
    public function descricaoDeAuditoria(): string
    {
        return trim(($this->cliente->nome ?? 'cliente ?').' · '.($this->sistema->nome ?? 'sistema ?'));
    }
}
