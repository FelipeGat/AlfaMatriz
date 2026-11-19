<?php

namespace App\Models;

use App\Models\Scopes\EscopoVixcard;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Acesso VixCard: quem tem o perfil só vê o que é da VixCard (docs/specs/acesso-vixcard).
#[ScopedBy([EscopoVixcard::class])]
class TarefaRelatorioTeste extends Model
{
    protected $table = 'tarefa_relatorios_teste';

    protected $fillable = [
        'tarefa_id', 'user_id', 'tarefa_evento_id', 'aprovado', 'notas',
    ];

    protected function casts(): array
    {
        return [
            'aprovado' => 'boolean',
        ];
    }

    /**
     * O relatório se carimba com a etapa em que a tarefa está.
     *
     * O carimbo é resolvido AQUI, e não em quem chama, porque ele não é um dado
     * que alguém escolhe: é sempre a etapa aberta da tarefa naquele instante. Se
     * dependesse de cada chamador lembrar de passá-lo, o dia em que alguém
     * esquecesse não daria erro nenhum — daria um relatório que serve para
     * qualquer passagem, que é exatamente o problema que ele existe para
     * resolver.
     *
     * Tarefa sem evento nenhum (nunca se moveu) fica com o vínculo nulo, e o
     * portão compara nulo com nulo: também é uma passagem, a primeira.
     */
    protected static function booted(): void
    {
        static::creating(function (TarefaRelatorioTeste $relatorio): void {
            if ($relatorio->tarefa_evento_id !== null) {
                return;
            }

            $relatorio->tarefa_evento_id = TarefaEvento::query()
                ->where('tarefa_id', $relatorio->tarefa_id)
                ->whereNull('saiu_em')
                ->latest('entrou_em')
                ->value('id');
        });
    }

    public function tarefa(): BelongsTo
    {
        return $this->belongsTo(Tarefa::class);
    }

    /**
     * Quem testou. `autor`, como no evento (`eventos.autor`): é a mesma
     * pergunta — quem assinou este registro — e dois nomes para ela fariam a
     * view errar exatamente onde a informação importa.
     */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * "Staging aprovado", "Produção reprovada" — o veredito com o ambiente.
     *
     * A etapa é a do evento a que o relatório se prende, e não a etapa atual
     * da tarefa: o veredito do staging continua sendo do staging depois que a
     * tarefa sobe. Relatório antigo, de Em testes ou sem evento, não finge
     * ambiente nenhum. "Produção" é feminino e "Staging" não — o mesmo cuidado
     * do card, que escrevia "Produção reprovado".
     */
    public function rotulo(): string
    {
        $etapa = $this->evento?->para_status;
        $resultado = $this->aprovado ? 'aprovad' : 'reprovad';

        return match ($etapa) {
            'em_staging' => 'Staging '.$resultado.'o',
            'em_producao' => 'Produção '.$resultado.'a',
            null => 'Teste '.$resultado.'o',
            default => 'Teste '.$resultado.'o ('.Tarefa::rotuloDaEtapa($etapa).')',
        };
    }

    /** A passagem por Em testes de que este relatório é a prova. */
    public function evento(): BelongsTo
    {
        return $this->belongsTo(TarefaEvento::class, 'tarefa_evento_id');
    }
}
