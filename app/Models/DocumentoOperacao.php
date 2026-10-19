<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma alteração pedida pelo Matriz para a pasta do OneDrive
 * (docs/specs/documentos-mao-dupla). Quem aplica é a máquina do dono; até lá
 * fica pendente, e o arquivo enviado espera no disco privado.
 */
class DocumentoOperacao extends Model
{
    public const TIPOS = ['criar_pasta', 'enviar', 'apagar', 'mover'];

    public const ROTULOS = ['criar_pasta' => 'Criar pasta', 'enviar' => 'Enviar', 'apagar' => 'Apagar', 'mover' => 'Mover'];

    protected $table = 'documento_operacoes';

    protected $fillable = ['pasta_id', 'user_id', 'tipo', 'caminho', 'destino', 'arquivo', 'tamanho', 'sha256', 'situacao', 'erro', 'feita_em'];

    protected function casts(): array
    {
        return ['feita_em' => 'datetime', 'tamanho' => 'integer'];
    }

    public function pasta(): BelongsTo
    {
        return $this->belongsTo(DocumentoPasta::class, 'pasta_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Uma frase para o painel: "Enviar Clientes/Lorenzo/orcamento.pdf". */
    public function descricao(): string
    {
        return match ($this->tipo) {
            'criar_pasta' => 'Criar a pasta '.$this->caminho,
            'enviar' => 'Enviar '.$this->caminho,
            'apagar' => 'Apagar '.$this->caminho,
            'mover' => 'Mover '.$this->caminho.' para '.$this->destino,
            default => $this->tipo.' '.$this->caminho,
        };
    }
}
