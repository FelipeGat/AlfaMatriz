<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma imagem anexada à tarefa.
 *
 * Vale para a tarefa inteira, e não para um comentário: ver a migração.
 */
class TarefaImagem extends Model
{
    use Auditavel;

    protected string $recursoAuditoria = 'tarefas';

    protected $table = 'tarefa_imagens';

    protected $fillable = ['tarefa_id', 'autor_id', 'nome_original', 'nome_arquivo', 'caminho', 'tamanho'];

    /**
     * O que a tela precisa saber sobre a imagem, e nada mais.
     *
     * A galeria é desenhada em JavaScript a partir desta lista, então o que
     * NÃO estiver aqui não existe para ela — e `caminho` fica de fora de
     * propósito: é o lugar do arquivo no disco, que só o servidor tem o que
     * fazer com.
     */
    protected $appends = ['tamanho_formatado', 'url', 'autor_nome'];

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
     * O endereço da imagem passa por rota, e não pelo `/storage` do disco.
     *
     * O arquivo mora no disco `public` porque é o único que sobrevive à
     * publicação azul/verde (ver `config/filesystems.php`) — mas o caminho
     * pelo qual a tela o pede é este, que passa por `auth` e por
     * `permissao:tarefas` como o resto do quadro.
     */
    public function getUrlAttribute(): string
    {
        return route('tarefas.imagens.ver', $this->id);
    }

    /**
     * O nome de quem anexou, já resolvido para a legenda.
     *
     * A galeria é desenhada em JavaScript e não tem como seguir uma relação;
     * sem isto, a legenda teria de escolher entre um id cru e uma consulta a
     * mais por miniatura.
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
