<?php

namespace App\Models;

use App\Models\Concerns\EspelhaSistema;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um administrador do cliente, DENTRO de um sistema.
 *
 * Nenhuma credencial trafega nem é guardada: o contrato proíbe o sistema de
 * mandar senha, resumo de senha ou token de sessão.
 */
class SistemaUsuario extends Model
{
    use EspelhaSistema, HasFactory;

    protected $table = 'sistema_usuarios';

    protected $fillable = [
        'sistema_id', 'sistema_cliente_id', 'id_externo',
        'nome', 'email', 'papel', 'ativo', 'ultimo_acesso_em',
        'ausente_em_origem_em', 'payload', 'sincronizado_em',
    ];

    protected $attributes = [
        'ativo' => true,
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'ultimo_acesso_em' => 'datetime',
            'payload' => 'array',
            'ausente_em_origem_em' => 'datetime',
            'sincronizado_em' => 'datetime',
        ];
    }

    public function sistemaCliente(): BelongsTo
    {
        return $this->belongsTo(SistemaCliente::class);
    }
}
