<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um padrão de erro que o time decidiu não acompanhar (#219).
 *
 * Texto comum casa por trecho, sem diferença de maiúsculas; entre barras
 * (`/Connection reset.*Redis/`) é expressão regular. Sem sistema, vale para
 * todos. O erro que casa continua sendo CONTADO — se um dia ele explodir, o
 * número está lá —, só não abre tarefa nem avisa.
 *
 * Auditado desde que a tela ganhou o botão (#224): calar um erro é decisão
 * de alguém, e quando ele voltar a fazer falta a pergunta vai ser quem o
 * calou e quando.
 */
class VigiaIgnorado extends Model
{
    use Auditavel;

    protected string $recursoAuditoria = 'manutencao';

    protected $table = 'vigia_ignorados';

    protected $fillable = ['sistema_id', 'padrao'];

    public function sistema(): BelongsTo
    {
        return $this->belongsTo(Sistema::class);
    }

    public function descricaoDeAuditoria(): string
    {
        return 'Vigia ignora «'.$this->padrao.'»'.($this->sistema ? ' no '.$this->sistema->nome : ' em todos os sistemas');
    }

    public function ehRegex(): bool
    {
        return strlen($this->padrao) > 2 && str_starts_with($this->padrao, '/') && str_ends_with($this->padrao, '/');
    }

    /**
     * O texto casa com este padrão?
     *
     * Regex inválida não casa — e não derruba o recebimento: um padrão mal
     * digitado na linha de comando não pode fazer o vigia parar de vigiar.
     * O `alfa:vigia-ignorar` já recusa a inválida na entrada; isto é a rede.
     */
    public function casaCom(string $texto): bool
    {
        if (! $this->ehRegex()) {
            return mb_stripos($texto, $this->padrao) !== false;
        }

        return @preg_match($this->padrao.'iu', $texto) === 1;
    }
}
