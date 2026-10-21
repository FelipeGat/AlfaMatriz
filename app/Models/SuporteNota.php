<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Uma nota da base do suporte (T-476; docs/specs/suporte-pedro-whatsapp/base-do-suporte.md):
 * o que a equipe sabe da empresa, um procedimento, um conhecimento, a memória de
 * um cliente ou o registro de um atendimento. Mora no Matriz; quem a alimenta são
 * os atendimentos, que o Pedro propõe e alguém da equipe confirma.
 */
class SuporteNota extends Model
{
    public const TIPOS = ['atendimento' => 'Atendimento', 'cliente' => 'Cliente', 'procedimento' => 'Procedimento',
        'conhecimento' => 'Conhecimento', 'empresa' => 'Empresa'];

    /** As que valem para qualquer conversa e por isso passam pelo "Validar" (decisão do dono, 21/10/2026). */
    public const GERAIS = ['procedimento', 'conhecimento', 'empresa'];

    public const SITUACOES = ['em_curso' => 'Em curso', 'resolvido' => 'Resolvido',
        'encerrado_sem_confirmacao' => 'Encerrado sem confirmação', 'cancelado' => 'Cancelado'];

    /**
     * O texto com que uma nota nova começa, no lugar dos modelos da pasta que a
     * base tinha antes de morar aqui: diz o que vale escrever em cada uma.
     */
    public const MODELOS = [
        'empresa' => "O que a equipe precisa saber e o cliente não precisa ouvir (a apresentação ao cliente fica em Pedro: conhecimento).\n\nEmpresas e o que cada uma atende:\n- \n\nQuem faz o quê na equipe:\n- \n\nO que o suporte não faz:\n- ",
        'procedimento' => "Antes de começar (o que confirmar com o cliente):\n- \n\nPassos:\n1. \n\nSe não resolver:\n- \n\nQuando chamar um técnico:\n- ",
        'conhecimento' => "O que se aprendeu (um fato, uma causa, um jeito de resolver):\n\nEm que situação vale:\n\nDe onde veio (atendimento, fabricante, teste):\n",
        'cliente' => "Quem é quem (síndico, zelador, quem decide):\n- \n\nEquipamentos e sistemas:\n- \n\nCombinados e preferências:\n- \n\nCuidados (o que já deu problema):\n- ",
        'atendimento' => '',
    ];

    protected $table = 'suporte_notas';

    protected $fillable = ['tipo', 'estado', 'titulo', 'quando_usar', 'corpo', 'relato', 'verificado', 'solucao', 'situacao', 'codigo',
        'gestor_cliente_id', 'cliente_id', 'conversa_id', 'tarefa_id', 'origem_id', 'validacao', 'validado_por', 'validado_em',
        'confirmado_por', 'confirmado_em', 'autor_id', 'atualizado_por'];

    protected function casts(): array
    {
        return ['validado_em' => 'datetime', 'confirmado_em' => 'datetime', 'gestor_cliente_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(fn (SuporteNota $n) => $n->termos = self::indexar(implode(' ', [$n->titulo, $n->quando_usar, $n->corpo, $n->relato, $n->verificado, $n->solucao, $n->codigo])));
    }

    /** As palavras do texto, sem acento e entre espaços: ` portao garagem sensor `. */
    public static function indexar(string $texto): string
    {
        $palavras = array_unique(array_filter(preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($texto))), fn ($p) => strlen($p) >= 3));

        return ' '.implode(' ', $palavras).' ';
    }

    public function scopeAtivas(Builder $q): Builder
    {
        return $q->where('estado', 'ativa');
    }

    public function scopePropostas(Builder $q): Builder
    {
        return $q->where('estado', 'proposta');
    }

    public function geral(): bool
    {
        return in_array($this->tipo, self::GERAIS, true);
    }

    public function validada(): bool
    {
        return $this->geral() && $this->validacao === 'validado' && $this->validado_por !== null;
    }

    public function encerrada(): bool
    {
        return $this->tipo === 'atendimento' && $this->situacao !== null && $this->situacao !== 'em_curso';
    }

    /** "gestor-13 · matriz-5": os códigos do cliente, os mesmos que a pasta de cada cliente levava no nome. */
    public function codigosDoCliente(): string
    {
        return collect([$this->gestor_cliente_id ? 'gestor-'.$this->gestor_cliente_id : null, $this->cliente_id ? 'matriz-'.$this->cliente_id : null])->filter()->implode(' · ');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function conversa(): BelongsTo
    {
        return $this->belongsTo(SuporteConversa::class, 'conversa_id');
    }

    public function tarefa(): BelongsTo
    {
        return $this->belongsTo(Tarefa::class);
    }

    public function origem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origem_id');
    }

    public function validadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validado_por');
    }

    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }

    public function atualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atualizado_por');
    }
}
