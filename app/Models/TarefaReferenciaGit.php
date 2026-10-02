<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um commit ou PR que o GitHub ligou à tarefa por citar `T-N` (#211).
 *
 * Nasce SÓ pelo webhook (`ReferenciasDoGitHub`): é registro do que aconteceu
 * no repositório, e não algo que alguém digita no quadro — por isso nenhuma
 * tela o edita.
 */
class TarefaReferenciaGit extends Model
{
    protected $table = 'tarefa_referencias_git';

    protected $fillable = [
        'tarefa_id', 'tipo', 'repositorio', 'chave', 'numero', 'titulo', 'url', 'autor_github', 'estado', 'branch',
    ];

    public const TIPO_COMMIT = 'commit';

    public const TIPO_PR = 'pr';

    public const ESTADOS_DO_PR = [
        'aberto' => 'Aberto',
        'mesclado' => 'Mesclado',
        'fechado' => 'Fechado',
    ];

    public function tarefa(): BelongsTo
    {
        return $this->belongsTo(Tarefa::class);
    }

    public function ehPr(): bool
    {
        return $this->tipo === self::TIPO_PR;
    }

    /** Os sete primeiros caracteres do sha — o que o próprio GitHub mostra. */
    public function shaCurto(): string
    {
        return substr($this->chave, 0, 7);
    }

    public function rotuloDoEstado(): string
    {
        return self::ESTADOS_DO_PR[$this->estado] ?? (string) $this->estado;
    }

    /**
     * O endereço, só se for http/https — ou null.
     *
     * O corpo vem assinado pelo GitHub, mas é texto de fora gravado no banco e
     * devolvido num `href`: um `javascript:` que chegasse aqui por qualquer
     * caminho viraria clique perigoso no modal. A view escapa, e isto barra o
     * esquema.
     */
    public function urlSegura(): ?string
    {
        return preg_match('~^https?://~i', (string) $this->url) ? $this->url : null;
    }
}
