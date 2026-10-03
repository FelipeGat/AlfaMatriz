<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Um changelog publicado (#225): o mesmo texto que foi ao Telegram, por
 * sistema e data, ligado à versão e às tarefas que entraram nela.
 *
 * Ver `App\Services\RegistroDeAtualizacoes`, por onde ele entra.
 */
class Atualizacao extends Model
{
    protected $table = 'atualizacoes';

    protected $guarded = ['id'];

    /**
     * As tags que o Telegram aceita no `parse_mode=HTML` e que a tela repete.
     * O resto do texto é escapado: ele chega de um script, e HTML cru de fora
     * renderizado no painel seria uma porta de injeção.
     */
    private const TAGS_DO_TELEGRAM = ['b', 'strong', 'i', 'em', 'u', 's', 'code', 'pre'];

    protected function casts(): array
    {
        return ['data' => 'date'];
    }

    public function sistema(): BelongsTo
    {
        return $this->belongsTo(Sistema::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por_id');
    }

    /** As tarefas que o script achou nos commits da versão (`T-N`). */
    public function tarefas(): BelongsToMany
    {
        return $this->belongsToMany(Tarefa::class, 'atualizacao_tarefa')->withTrashed();
    }

    /**
     * As tarefas da versão: as que o script achou nos commits e as que o
     * quadro registrou como publicadas nela (`versao_producao`).
     *
     * As duas fontes, porque cada uma enxerga um pedaço: o commit sem `T-N`
     * não chega pela primeira, e a tarefa só ganha a versão quando alguém a
     * move para Em produção — às vezes depois de o changelog sair.
     *
     * @return Collection<int, Tarefa>
     */
    public function tarefasDaVersao(): Collection
    {
        $doQuadro = $this->versao
            ? Tarefa::query()->where('versao_producao', $this->versao)->where('sistema_id', $this->sistema_id)->get()
            : collect();

        return $this->tarefas->concat($doQuadro)->unique('id')->sortBy('id')->values();
    }

    /**
     * A hora do envio ao Telegram, quando ela é conhecida (#235).
     *
     * O script registra logo depois de o Telegram aceitar, então a hora do
     * registro É a hora do envio — desde que ele tenha acontecido no mesmo dia
     * do changelog. O importado (`--importar`) só tem a data do arquivo: a hora
     * dele seria a da importação, e mostrá-la inventaria um horário que nunca
     * existiu. O mesmo vale para o registro feito em outro dia (`--so-registrar`
     * de um changelog antigo).
     */
    public function horaDoEnvio(): ?\Illuminate\Support\Carbon
    {
        if ($this->origem !== 'script' || ! $this->created_at || ! $this->created_at->isSameDay($this->data)) {
            return null;
        }

        return $this->created_at;
    }

    /** As partes como foram ao Telegram — separadas por uma linha `---`. */
    public function partes(): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/^---$/m', $this->texto)),
            fn ($parte) => $parte !== '',
        ));
    }

    /**
     * O texto pronto para a tela: escapado inteiro, e só as tags do Telegram
     * voltam a valer. Link vira texto com o endereço — no painel, um <a> de
     * origem externa é o tipo de coisa que se clica sem ler.
     */
    public static function htmlSeguro(string $texto): HtmlString
    {
        $seguro = e($texto);

        foreach (self::TAGS_DO_TELEGRAM as $tag) {
            $seguro = preg_replace('/&lt;(\/?)'.$tag.'&gt;/i', '<$1'.$tag.'>', $seguro);
        }

        $seguro = preg_replace('/&lt;a href=&quot;(.*?)&quot;&gt;(.*?)&lt;\/a&gt;/is', '$2 ($1)', $seguro);

        return new HtmlString(nl2br($seguro, false));
    }
}
