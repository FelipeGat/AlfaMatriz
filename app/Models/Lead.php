<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use Auditavel, SoftDeletes;

    protected string $recursoAuditoria = 'leads';

    /** Ordem do Kanban. */
    public const ESTAGIOS = [
        'lead' => 'Lead',
        'qualificacao' => 'Qualificação',
        'proposta' => 'Proposta',
        'contrato' => 'Contrato',
        'implantacao' => 'Implantação',
        'cliente_ativo' => 'Cliente Ativo',
        'perdido' => 'Perdido',
    ];

    public const ESTAGIOS_TERMINAIS = ['cliente_ativo', 'perdido'];

    public const ORIGENS = ['Google', 'Facebook', 'Instagram', 'Indicação', 'Site', 'WhatsApp', 'Outro'];

    public const TIPOS_INTERESSE = [
        'saas' => 'Sistema (SaaS)',
        'site' => 'Site',
        'app' => 'App',
        'consultoria' => 'Consultoria',
        'marketing' => 'Marketing',
        'outro' => 'Outro',
    ];

    public const MOTIVOS_PERDA = [
        'preco' => 'Preço',
        'concorrente' => 'Foi pra concorrente',
        'sem_retorno' => 'Sem retorno',
        'fora_do_momento' => 'Fora do momento',
        'nao_qualificado' => 'Não qualificado',
        'outro' => 'Outro',
    ];

    protected $fillable = [
        'nome', 'cpf_cnpj', 'email', 'telefone', 'revenda_id', 'sistema_id',
        'tipo_interesse', 'origem', 'estagio', 'estagio_atualizado_em',
        'valor_estimado', 'motivo_perda', 'observacoes', 'vendedor_id', 'cliente_id',
    ];

    protected function casts(): array
    {
        return [
            'valor_estimado' => 'decimal:2',
            'estagio_atualizado_em' => 'datetime',
        ];
    }

    public function revenda(): BelongsTo
    {
        return $this->belongsTo(Revenda::class);
    }

    public function sistema(): BelongsTo
    {
        return $this->belongsTo(Sistema::class);
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function isAberto(): bool
    {
        return ! in_array($this->estagio, self::ESTAGIOS_TERMINAIS);
    }

    public function moverEstagio(string $novoEstagio, ?string $motivoPerda = null): void
    {
        $this->update([
            'estagio' => $novoEstagio,
            'estagio_atualizado_em' => now(),
            'motivo_perda' => $novoEstagio === 'perdido' ? $motivoPerda : null,
        ]);
    }

    public function diasNoEstagio(): int
    {
        $desde = $this->estagio_atualizado_em ?? $this->created_at;

        return (int) $desde->diffInDays(now());
    }

    /**
     * 'quente' < 7 dias, 'esfriando' 7-15, 'frio' > 15. Null pra estágios terminais.
     */
    public function temperatura(): ?string
    {
        if (! $this->isAberto()) {
            return null;
        }

        $dias = $this->diasNoEstagio();

        return match (true) {
            $dias >= 15 => 'frio',
            $dias >= 7 => 'esfriando',
            default => 'quente',
        };
    }

    /**
     * Converte o lead num Cliente de verdade. Se tipo_interesse=saas e tiver
     * sistema definido, já vincula o cliente a esse sistema.
     */
    public function converterParaCliente(): Cliente
    {
        $cliente = Cliente::create([
            'revenda_id' => $this->revenda_id,
            'nome' => $this->nome,
            'razao_social' => $this->nome,
            'cpf_cnpj' => $this->cpf_cnpj,
            'tipo_pessoa' => 'PJ',
            'ativo' => true,
            'data_cadastro' => now()->toDateString(),
        ]);

        if ($this->email) {
            $cliente->emails()->create(['email' => $this->email, 'principal' => true, 'financeiro' => true]);
        }
        if ($this->telefone) {
            $cliente->telefones()->create(['telefone' => $this->telefone, 'principal' => true]);
        }
        if ($this->tipo_interesse === 'saas' && $this->sistema_id) {
            $cliente->sistemas()->attach($this->sistema_id, ['ativo' => true, 'ativado_em' => now()->toDateString()]);
        }

        $this->update([
            'cliente_id' => $cliente->id,
            'estagio' => 'cliente_ativo',
            'estagio_atualizado_em' => now(),
        ]);

        return $cliente;
    }
}
