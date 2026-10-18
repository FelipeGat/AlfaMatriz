<?php

namespace App\Models;

use App\Concerns\Auditavel;
use App\Concerns\ComOrigemExterna;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Revenda extends Model
{
    use Auditavel, ComOrigemExterna, SoftDeletes;

    protected string $recursoAuditoria = 'revendas';

    protected $fillable = [
        'nome', 'cnpj', 'contato_nome', 'contato_email', 'contato_telefone', 'ativo',
        'data_cadastro',
        // Cobrança por cliente com boleto (cobranca-revendas-boleto).
        'cobranca_por_cliente', 'dia_vencimento', 'email_financeiro', 'cep', 'logradouro', 'numero', 'bairro', 'cidade', 'uf',
        'conta_financeira_id',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'cobranca_por_cliente' => 'boolean',
            'dia_vencimento' => 'integer',
            'data_cadastro' => 'date',
        ];
    }

    /**
     * O dia em que a revenda entrou na base — ver `Cliente::data_entrada`, que
     * segue a mesma regra e existe pelo mesmo motivo: `created_at` é o dia da
     * importação para todo mundo de uma vez.
     */
    public function getDataEntradaAttribute(): ?\Illuminate\Support\Carbon
    {
        return $this->data_cadastro ?? $this->created_at;
    }

    /** A mesma regra em SQL, para filtro e ordenação. */
    public static function expressaoDeEntrada(): string
    {
        return 'COALESCE(revendas.data_cadastro, DATE(revendas.created_at))';
    }

    public function origensExternas(): MorphMany
    {
        return $this->morphMany(OrigemExterna::class, 'entidade');
    }

    public function clientes(): HasMany
    {
        return $this->hasMany(Cliente::class);
    }

    public function precosAtacado(): HasMany
    {
        return $this->hasMany(PrecoAtacado::class);
    }

    /** A conta do Caixa onde cai o pagamento do boleto (cobranca-revendas-boleto). */
    public function contaFinanceira(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class, 'conta_financeira_id');
    }

    /** O que falta para emitir boleto: lista vazia = pronto. @return list<string> */
    public function faltaParaBoleto(): array
    {
        $doc = preg_replace('/\D/', '', (string) $this->cnpj);

        return array_values(array_filter([
            strlen($doc) === 14 ? null : 'CNPJ',
            filter_var($this->email_financeiro, FILTER_VALIDATE_EMAIL) ? null : 'e-mail financeiro',
            strlen(preg_replace('/\D/', '', (string) $this->cep)) === 8 ? null : 'CEP',
            filled($this->logradouro) ? null : 'logradouro',
            filled($this->cidade) ? null : 'cidade',
            preg_match('/^[A-Z]{2}$/', (string) $this->uf) ? null : 'UF',
            $this->conta_financeira_id ? null : 'conta do Caixa',
        ]));
    }

    public function cobrancas(): HasMany
    {
        return $this->hasMany(Cobranca::class);
    }
}
