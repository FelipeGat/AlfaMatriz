<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Decisão, tarefa ou pendência que a análise propôs, com as evidências (trechos). */
class SecretarioReuniaoProposta extends Model
{
    public const CATEGORIAS = ['decisao', 'tarefa', 'pendencia'];
    public const CLASSIFICACOES = ['decisao', 'compromisso', 'sugestao', 'hipotese', 'pendencia', 'informacao'];
    /** Fora do "aprovar em lote": a pessoa decide uma por uma (spec US2, cenário 5). */
    public const FORA_DO_LOTE = ['sugestao', 'hipotese'];

    protected $table = 'secretario_reuniao_propostas';

    protected $fillable = ['reuniao_id', 'user_id', 'categoria', 'texto', 'responsavel', 'prazo', 'prioridade', 'classificacao', 'evidencias', 'status', 'item_id'];

    protected $attributes = ['status' => 'pendente', 'prioridade' => 'normal'];

    protected function casts(): array
    {
        return ['evidencias' => 'array'];
    }
}
