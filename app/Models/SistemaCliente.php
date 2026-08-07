<?php

namespace App\Models;

use App\Models\Concerns\EspelhaSistema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um cliente como ele existe DENTRO de um sistema — a academia no AlfaGym, o
 * condomínio no AlfaControl, a família no AlfaHome, a clínica no AlfaMed.
 *
 * Não confundir com `Cliente`, que é o cliente da matriz. A ponte é
 * `cliente_id`, e enquanto ela não existe o cliente aparece na conferência.
 */
class SistemaCliente extends Model
{
    use EspelhaSistema, HasFactory;

    protected $table = 'sistema_clientes';

    protected $fillable = [
        'sistema_id', 'id_externo', 'cliente_id', 'sistema_revenda_id', 'vinculo_origem',
        'nome', 'razao_social', 'cpf_cnpj', 'email', 'telefone', 'cidade', 'uf',
        'ativo', 'status', 'revenda_id_externo', 'unidades_ativas',
        'criado_em_origem', 'atualizado_em_origem',
        'ausente_em_origem_em', 'payload', 'sincronizado_em',
    ];

    protected $attributes = [
        'ativo' => true,
        'status' => 'ativo',
        'unidades_ativas' => 0,
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'unidades_ativas' => 'integer',
            'payload' => 'array',
            'criado_em_origem' => 'datetime',
            'atualizado_em_origem' => 'datetime',
            'ausente_em_origem_em' => 'datetime',
            'sincronizado_em' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function sistemaRevenda(): BelongsTo
    {
        return $this->belongsTo(SistemaRevenda::class);
    }

    public function licencas(): HasMany
    {
        return $this->hasMany(SistemaLicenca::class);
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(SistemaUsuario::class);
    }

    public function vinculado(): bool
    {
        return $this->cliente_id !== null;
    }

    public function vinculoEhManual(): bool
    {
        return $this->vinculo_origem === 'manual';
    }

    /** Os que ainda esperam alguém dizer a quem correspondem na matriz. */
    public function scopeSemVinculo(Builder $consulta): Builder
    {
        return $consulta->whereNull('cliente_id');
    }

    public function scopeAtivos(Builder $consulta): Builder
    {
        return $consulta->where('ativo', true)->whereNull('ausente_em_origem_em');
    }
}
