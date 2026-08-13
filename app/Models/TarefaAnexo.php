<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected $fillable = ['tarefa_id', 'autor_id', 'nome_original', 'nome_arquivo', 'mime', 'caminho', 'tamanho'];

    /**
     * O que a tela precisa saber sobre o anexo, e nada mais.
     *
     * A seção é desenhada em JavaScript a partir desta lista, então o que NÃO
     * estiver aqui não existe para ela — e `caminho` fica de fora de propósito:
     * é o lugar do arquivo no disco, que só o servidor tem o que fazer com.
     */
    protected $appends = ['tamanho_formatado', 'url', 'autor_nome', 'eh_imagem'];

    protected $hidden = ['caminho', 'nome_arquivo'];

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
