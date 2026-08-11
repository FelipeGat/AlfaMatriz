<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TarefaComentario extends Model
{
    protected $table = 'tarefa_comentarios';

    protected $fillable = ['tarefa_id', 'autor_id', 'corpo'];

    public function tarefa(): BelongsTo
    {
        return $this->belongsTo(Tarefa::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }

    /** O corpo pronto para a tela, com os marcadores já virados lista. */
    public function corpoEmHtml(): string
    {
        return self::marcadoresEmHtml($this->corpo);
    }

    /**
     * Texto do comentário como HTML seguro, com listas de marcador e numeradas.
     *
     * O comentário é onde a tarefa vira detalhe — "faltam três coisas para
     * fechar isso" é uma lista, e escrita como parágrafo corrido ninguém
     * consegue conferir item a item. Daí o mínimo de marcação, e só ele:
     *
     *  - `- item`, `* item` ou `• item` viram uma lista com marcador;
     *  - `1. item` (ou `1) item`) vira uma lista numerada, começando no número
     *    que a pessoa escreveu — quem retoma a contagem no "4." está falando
     *    do quarto item, e renumerar para 1 mudaria o que ela disse;
     *  - o resto é parágrafo, com a quebra de linha preservada.
     *
     * NÃO é markdown: não há negrito, link nem título. A conversão é uma
     * lista branca — o texto é escapado ANTES de qualquer tag entrar, então
     * `<script>` digitado no campo chega à tela como texto, não como script.
     * É o que permite imprimir isto com `{!! !!}` sem abrir XSS.
     */
    public static function marcadoresEmHtml(string $texto): string
    {
        $linhas = preg_split('/\r\n|\r|\n/', trim($texto)) ?: [];

        $html = '';
        $abertos = [];   // itens da lista em construção
        $tipoLista = null;
        $inicioLista = 1;
        $paragrafo = [];

        $fecharLista = function () use (&$html, &$abertos, &$tipoLista, &$inicioLista) {
            if ($abertos === []) {
                return;
            }

            $abertura = $tipoLista === 'ol' && $inicioLista !== 1
                ? '<ol start="'.$inicioLista.'">'
                : '<'.$tipoLista.'>';

            $html .= $abertura.'<li>'.implode('</li><li>', $abertos).'</li></'.$tipoLista.'>';
            $abertos = [];
            $tipoLista = null;
            $inicioLista = 1;
        };

        $fecharParagrafo = function () use (&$html, &$paragrafo) {
            if ($paragrafo === []) {
                return;
            }

            $html .= '<p>'.implode('<br>', $paragrafo).'</p>';
            $paragrafo = [];
        };

        foreach ($linhas as $linha) {
            $linha = trim($linha);

            if ($linha === '') {
                $fecharLista();
                $fecharParagrafo();

                continue;
            }

            if (preg_match('/^[-*•]\s+(.+)$/u', $linha, $item)) {
                $fecharParagrafo();
                // Trocar de tipo de lista no meio fecha a anterior: marcador e
                // numeração no mesmo <ul> perderiam a numeração.
                if ($tipoLista !== null && $tipoLista !== 'ul') {
                    $fecharLista();
                }
                $tipoLista = 'ul';
                $abertos[] = e($item[1]);

                continue;
            }

            if (preg_match('/^(\d{1,3})[.)]\s+(.+)$/u', $linha, $item)) {
                $fecharParagrafo();
                if ($tipoLista !== null && $tipoLista !== 'ol') {
                    $fecharLista();
                }
                if ($tipoLista === null) {
                    $inicioLista = (int) $item[1];
                }
                $tipoLista = 'ol';
                $abertos[] = e($item[2]);

                continue;
            }

            $fecharLista();
            $paragrafo[] = e($linha);
        }

        $fecharLista();
        $fecharParagrafo();

        return $html;
    }
}
