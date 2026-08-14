<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Um arquivo anexado à tarefa — print, log, planilha ou PDF.
 *
 * Vale para a tarefa inteira, e não para um comentário: ver a migração que
 * criou a tabela.
 *
 * Nasceu guardando só imagem e passou a guardar o resto no mesmo dia: são a
 * mesma coisa — a prova que o texto da tarefa não dá — e quem anexa não
 * distingue os dois no gesto. O que ainda os separa é como aparecem
 * (`eh_imagem`): figura vira miniatura, o resto vira linha com nome e tamanho.
 */
class TarefaAnexo extends Model
{
    use Auditavel;

    protected string $recursoAuditoria = 'tarefas';

    protected $table = 'tarefa_anexos';

    /**
     * O que a validação aceita — por extensão DEDUZIDA DO CONTEÚDO.
     *
     * A regra `mimes:` do Laravel não lê a extensão do nome que veio do
     * navegador: ela detecta o mime do conteúdo e pergunta qual extensão
     * corresponde a ele. Por isso `log` NÃO está nesta lista e mesmo assim um
     * `erro.log` entra — texto puro é `text/plain`, que corresponde a `txt`.
     * Listar `log` aqui seria decoração: não casaria com nada, porque nenhum
     * mime do mundo deduz para `log`.
     */
    public const EXTENSOES_VALIDADAS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv', 'xls', 'xlsx'];

    /**
     * O que o seletor de arquivo do navegador oferece.
     *
     * Lista diferente da de cima de propósito: aqui `.log` PRECISA aparecer,
     * senão o seletor esconde justamente o arquivo que a pessoa veio anexar —
     * ele só não aparece lá porque lá se fala de mime, e não de nome.
     */
    public const ACEITE_DO_SELETOR = '.jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.log,.csv,.xls,.xlsx';

    protected $fillable = ['tarefa_id', 'autor_id', 'nome_original', 'nome_arquivo', 'mime', 'caminho', 'caminho_miniatura', 'tamanho'];

    /**
     * O que a tela precisa saber sobre o anexo, e nada mais.
     *
     * A seção é desenhada em JavaScript a partir desta lista, então o que NÃO
     * estiver aqui não existe para ela — e `caminho` fica de fora de propósito:
     * é o lugar do arquivo no disco, que só o servidor tem o que fazer com.
     */
    protected $appends = ['tamanho_formatado', 'url', 'url_miniatura', 'autor_nome', 'eh_imagem'];

    protected $hidden = ['caminho', 'caminho_miniatura', 'nome_arquivo'];

    /**
     * A linha que morre leva o arquivo do disco junto.
     *
     * No EVENTO, e não só na rota que remove um anexo por vez: o `excluirAnexo`
     * fazia as duas metades na mão, e qualquer caminho novo que apagasse a
     * linha sem repetir o gesto deixaria o arquivo para trás. Arquivo órfão não
     * dá erro nenhum — nada aponta para ele, ninguém o procura, e ele só se
     * apresenta como disco cheio anos depois, quando já não se sabe de quem é.
     *
     * NÃO alcança a exclusão da tarefa inteira: lá as linhas somem pelo cascade
     * do banco, que o Eloquent não vê passar. Quem cobre esse caso é o
     * `forceDeleting` do próprio `Tarefa`.
     */
    protected static function booted(): void
    {
        static::deleting(function (TarefaAnexo $anexo): void {
            // As duas metades: original e miniatura. `array_filter` porque a
            // segunda é nula na maioria das linhas — anexo que não é figura
            // nunca teve uma, e a figura pequena não ganhou.
            Storage::disk('public')->delete(array_filter([$anexo->caminho, $anexo->caminho_miniatura]));
        });
    }

    public function tarefa(): BelongsTo
    {
        return $this->belongsTo(Tarefa::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }

    /** Mesma régua dos anexos de cobrança e de conta a pagar. */
    public function getTamanhoFormatadoAttribute(): string
    {
        if ($this->tamanho < 1024) {
            return $this->tamanho.' B';
        }

        if ($this->tamanho < 1048576) {
            return round($this->tamanho / 1024, 1).' KB';
        }

        return round($this->tamanho / 1048576, 1).' MB';
    }

    /**
     * Figura ou arquivo — é isto que decide como o anexo aparece e como sai.
     *
     * Na tela: miniatura na grade, ou linha com ícone, nome e tamanho. Um log
     * de 800 KB não tem miniatura que se olhe, e um print reduzido a uma linha
     * de texto perderia justamente o que ele veio mostrar.
     *
     * Na rota: só figura sai embutida (`inline`), porque a grade precisa dela
     * dentro de um `<img>`. O resto sai como download — ver `verAnexo`.
     *
     * O mime vem do CONTEÚDO no envio, não da extensão do nome. Nulo só
     * aconteceria em linha anterior à migração que criou a coluna, e ela
     * preencheu todas — o `?? ''` é cinto de segurança, não caso esperado.
     */
    public function getEhImagemAttribute(): bool
    {
        return str_starts_with($this->mime ?? '', 'image/');
    }

    /**
     * O endereço do anexo passa por rota, e não pelo `/storage` do disco.
     *
     * O arquivo mora no disco `public` porque é o único que sobrevive à
     * publicação azul/verde (ver `config/filesystems.php`) — mas o caminho
     * pelo qual a tela o pede é este, que passa por `auth` e por
     * `permissao:tarefas` como o resto do quadro.
     */
    public function getUrlAttribute(): string
    {
        return route('tarefas.anexos.ver', $this->id);
    }

    /**
     * O endereço da versão pequena — o que a GRADE pinta.
     *
     * Cai no original quando não há miniatura, e isso não é falha: a coluna é
     * nula de propósito para quem não é figura, para a figura que já é menor
     * que a grade e para o formato que o GD não leu. O `<img>` continua
     * funcionando, exatamente como funcionava antes desta coluna existir.
     *
     * O link do card NÃO usa este endereço: clicar abre o original em aba nova,
     * que é onde se lê o print. A miniatura só existe para a grade não baixar
     * 12 MB para pintar 140 pixels.
     */
    public function getUrlMiniaturaAttribute(): string
    {
        return $this->caminho_miniatura
            ? route('tarefas.anexos.ver', ['anexo' => $this->id, 'min' => 1])
            : $this->url;
    }

    /**
     * O nome de quem anexou, já resolvido para a legenda.
     *
     * A seção é desenhada em JavaScript e não tem como seguir uma relação; sem
     * isto, a legenda teria de escolher entre um id cru e uma consulta a mais
     * por anexo.
     */
    public function getAutorNomeAttribute(): string
    {
        return $this->autor?->name ?? 'Autor removido';
    }

    /**
     * Ver `CobrancaAnexo::descricaoDeAuditoria()` — mesmo motivo: o arquivo se
     * apresenta pelo nome que tinha ao ser enviado.
     */
    public function descricaoDeAuditoria(): string
    {
        return $this->nome_original;
    }
}
