<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Um orçamento VixCard (docs/specs/orcamentos-vixcard). Cinco situações, e só
 * as da VENDA: produção e entrega ficam fora daqui. Enviado não se edita —
 * revisa-se, e a revisão nova substitui a anterior com o mesmo número.
 */
class Orcamento extends Model
{
    public const SITUACOES = [
        'rascunho' => 'Rascunho', 'enviado' => 'Enviado', 'aprovado' => 'Aprovado', 'recusado' => 'Recusado', 'substituido' => 'Substituído',
    ];

    public const VALIDADES = [7, 15, 30];

    public const PARCELAS = [1, 2, 3, 4, 5, 6, 10, 12];

    protected $table = 'orcamentos';

    protected $fillable = [
        'ano', 'numero', 'revisao', 'revisao_de_id', 'substituido_por_id', 'situacao', 'produto', 'cliente', 'contato', 'cidade',
        'titulo', 'destaque', 'lead', 'validade_dias', 'entrada', 'parcelas', 'desconto_avista', 'inclui', 'nao_inclui', 'passos',
        'aceite', 'observacoes', 'total_unico', 'total_mensal', 'user_id', 'pdf_arquivo', 'pdf_gerado_em', 'enviado_em', 'respondido_em',
    ];

    protected $attributes = ['situacao' => 'rascunho', 'revisao' => 1, 'validade_dias' => 15, 'parcelas' => 1, 'aceite' => true,
        'entrada' => 0, 'desconto_avista' => 0, 'total_unico' => 0, 'total_mensal' => 0];

    protected function casts(): array
    {
        return [
            'entrada' => 'float', 'desconto_avista' => 'float', 'total_unico' => 'float', 'total_mensal' => 'float', 'aceite' => 'boolean',
            'parcelas' => 'integer', 'validade_dias' => 'integer', 'revisao' => 'integer', 'numero' => 'integer', 'ano' => 'integer',
            'pdf_gerado_em' => 'datetime', 'enviado_em' => 'datetime', 'respondido_em' => 'datetime',
        ];
    }

    public function itens(): HasMany
    {
        return $this->hasMany(OrcamentoItem::class)->orderBy('ordem')->orderBy('id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** "015/2026", e "015/2026 · Rev. 02" a partir da segunda. */
    public function codigo(bool $comRevisao = true): string
    {
        $c = str_pad((string) $this->numero, 3, '0', STR_PAD_LEFT).'/'.$this->ano;

        return $comRevisao && $this->revisao > 1 ? $c.' · Rev. '.str_pad((string) $this->revisao, 2, '0', STR_PAD_LEFT) : $c;
    }

    public function editavel(): bool
    {
        return $this->situacao === 'rascunho';
    }

    /** A data de referência da proposta: o envio, ou hoje enquanto é rascunho. */
    public function data(): Carbon
    {
        return Carbon::parse($this->enviado_em ?? now());
    }

    public function validoAte(): Carbon
    {
        return $this->data()->copy()->addDays($this->validade_dias);
    }

    public function valorAVista(): float
    {
        return round($this->total_unico * (1 - $this->desconto_avista / 100), 2);
    }

    public function valorParcela(): float
    {
        return $this->parcelas > 0 ? round(max(0, $this->total_unico - $this->entrada) / $this->parcelas, 2) : 0;
    }

    /**
     * A frase de pagamento que vai no PDF, pronta — a ideia boa do Gestor
     * (`condicoes_pagamento_texto`), sem os sete botões de rádio.
     */
    public function condicoes(): string
    {
        $brl = fn (float $v) => 'R$ '.number_format($v, 2, ',', '.');
        if ($this->total_unico <= 0) {
            $frase = '';
        } elseif ($this->parcelas <= 1) {
            $frase = 'À vista: '.$brl($this->valorAVista()).($this->desconto_avista > 0 ? ' ('.self::pct($this->desconto_avista).' de desconto).' : '.');
        } else {
            $frase = ($this->entrada > 0 ? 'Entrada de '.$brl($this->entrada).' e saldo' : 'Total').' em '.$this->parcelas.'x de '.$brl($this->valorParcela()).' sem juros'
                .($this->desconto_avista > 0 ? ', ou à vista com '.self::pct($this->desconto_avista).' de desconto: '.$brl($this->valorAVista()) : '').'.';
        }
        if ($this->total_mensal > 0) {
            $frase = trim($frase.' Mensalidade de '.$brl($this->total_mensal).' a partir da instalação.');
        }

        return $frase;
    }

    /** Uma linha por item de texto (inclui, não inclui, passos). @return list<string> */
    public function linhas(string $campo): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->{$campo}) ?: [])));
    }

    /** Nome do arquivo do PDF: PROPOSTA_015-2026_REV01_MARMORARIA_LORENZO.pdf */
    public function nomeDoPdf(): string
    {
        $cliente = strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string) \Illuminate\Support\Str::ascii($this->cliente)), '_'));

        return sprintf('PROPOSTA_%03d-%d_REV%02d_%s.pdf', $this->numero, $this->ano, $this->revisao, substr($cliente, 0, 60) ?: 'CLIENTE');
    }

    private static function pct(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',').'%';
    }
}
