<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O que foi entregue para a revisão numa passagem (#210): o que foi feito,
 * como testar e onde está o código.
 *
 * Nasce SÓ pelo motor (`FluxoTarefaService::mover`), no mesmo movimento que
 * leva a tarefa de Em andamento para Em revisão — é ele que cobra os campos,
 * e por isso a tela e o agente chegam aqui pela mesma porta.
 */
class TarefaEntrega extends Model
{
    protected $table = 'tarefa_entregas';

    protected $fillable = [
        'tarefa_id', 'user_id', 'tarefa_evento_id', 'o_que_foi_feito', 'como_testar', 'pr_commits',
    ];

    /**
     * O número da entrega se resolve AQUI, e não em quem cria, pelo mesmo
     * motivo do carimbo do relatório de teste: não é dado que alguém escolhe.
     * "2ª entrega" é o que diz a quem revisa que esta já voltou uma vez — e um
     * número passado à mão erraria justamente no dia em que alguém esquecesse.
     */
    protected static function booted(): void
    {
        static::creating(function (TarefaEntrega $entrega): void {
            $entrega->numero = (int) static::query()
                ->where('tarefa_id', $entrega->tarefa_id)
                ->max('numero') + 1;
        });
    }

    public function tarefa(): BelongsTo
    {
        return $this->belongsTo(Tarefa::class);
    }

    /** Quem entregou. `autor`, como no relatório e no evento: é a mesma pergunta. */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** A passagem por Em revisão em que esta entrega nasceu. */
    public function evento(): BelongsTo
    {
        return $this->belongsTo(TarefaEvento::class, 'tarefa_evento_id');
    }

    /**
     * O campo de PR e commits em pedaços, com os endereços separados do resto.
     *
     * Quem lê precisa CLICAR no PR — copiar a URL de um texto corrido no
     * celular é o que fazia o link nunca ser aberto. Mas o texto é livre e vem
     * de quem move (e do agente), então nada dele vira HTML: a view escapa cada
     * pedaço e só monta o `<a>` em volta do que é http/https. Outro esquema
     * (`javascript:`, `data:`) continua texto.
     *
     * @return list<array{texto: string, url: ?string}>
     */
    public function trechosDoPr(): array
    {
        $texto = (string) $this->pr_commits;

        if ($texto === '') {
            return [];
        }

        $partes = preg_split('~(https?://[^\s<>"\']+)~i', $texto, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $trechos = [];

        foreach ($partes ?: [] as $parte) {
            if (! preg_match('~^https?://~i', $parte)) {
                $trechos[] = ['texto' => $parte, 'url' => null];

                continue;
            }

            // A pontuação que fecha a frase não é do endereço: "veja o PR
            // https://…/42." levaria o ponto para dentro do link, quebrado.
            $url = rtrim($parte, '.,;:!?)');
            $trechos[] = ['texto' => $url, 'url' => $url];

            if ($url !== $parte) {
                $trechos[] = ['texto' => substr($parte, strlen($url)), 'url' => null];
            }
        }

        return $trechos;
    }
}
