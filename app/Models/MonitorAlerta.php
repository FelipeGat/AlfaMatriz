<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * O estado de uma coisa que o AlfaMonitor vigia (#394): um servidor, um site,
 * uma catraca, uma VPS da Hostinger. Uma linha por (kind, entity).
 *
 * Sem `Auditavel`, como o `VigiaErro`: quem escreve aqui é o monitor, a cada
 * alerta, e cada mudança já fica no histórico (`MonitorEvento`). Auditar
 * repetiria o histórico numa tela que não é para isso.
 */
class MonitorAlerta extends Model
{
    protected $table = 'monitor_alertas';

    protected $guarded = ['id'];

    /**
     * Os kinds que o Matriz mostra, na ordem das abas do filtro. Sonda, rede,
     * DVR e câmeras ficam de FORA por decisão do dono (17/10/2026), e os
     * resumos (`digest`, `summary`) não são evento. O monitor já os barra nas
     * regras dele; esta lista é a segunda trava.
     */
    public const KINDS = [
        'monitor' => 'Disponibilidade',
        'metric' => 'Servidor',
        'device' => 'Dispositivo de acesso',
        'source' => 'Fonte de dispositivos',
        'hostinger' => 'Hostinger',
    ];

    public const SEVERIDADES = [
        'critical' => 'Crítico',
        'warning' => 'Atenção',
        'info' => 'Info',
    ];

    protected function casts(): array
    {
        return [
            'aberto' => 'boolean',
            'voltou_sem_queda' => 'boolean',
            'quedas' => 'integer',
            'caiu_em' => 'datetime',
            'voltou_em' => 'datetime',
            'ultimo_evento_em' => 'datetime',
            'ultimo_aviso_em' => 'datetime',
        ];
    }

    /**
     * Tira os alertas que são da ASSINATURA da Hostinger, para quem não tem
     * `monitor_hostinger` (decisão do dono: ver o monitoramento não dá o
     * contrato com o fornecedor). A regra é o prefixo do `entity`, fixo no
     * monitor (combinado com ele em 17/10/2026): `venc:domain:…` e
     * `venc:subscription:…` são vencimento, `integracao` é a API da Hostinger
     * fora. `vps:` e `banda:` são o servidor — monitoramento, e ficam.
     */
    public function scopeSemAssinaturasDaHostinger(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('kind', '!=', 'hostinger')
            ->orWhere(fn (Builder $h) => $h->where('entity', 'not like', 'venc:%')
                ->where('entity', '!=', 'integracao')));
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(MonitorEvento::class);
    }

    public function clienteDoMatriz(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    /** O nome que a tela mostra: o legível, se o monitor mandou. */
    public function nome(): string
    {
        return $this->entity_name ?: $this->title;
    }

    public static function rotuloDoKind(string $kind): string
    {
        return self::KINDS[$kind] ?? $kind;
    }

    public static function rotuloDaSeveridade(string $severity): string
    {
        return self::SEVERIDADES[$severity] ?? $severity;
    }

    /** O tom do `x-badge` para a gravidade. */
    public static function tomDaSeveridade(string $severity): string
    {
        return match ($severity) {
            'critical' => 'critico',
            'warning' => 'atencao',
            default => 'neutro',
        };
    }

    /**
     * O endereço na web do monitor. O `link` vem relativo ("/devices"); sem a
     * base configurada (`ALFAMONITOR_URL`), a tela não oferece o link em vez
     * de oferecer um que não abre.
     */
    public function url(): ?string
    {
        $base = config('services.alfamonitor.url');

        if (blank($base) || blank($this->link)) {
            return null;
        }

        return rtrim($base, '/').'/'.ltrim($this->link, '/');
    }
}
