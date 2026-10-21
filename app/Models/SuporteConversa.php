<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma conversa do número de suporte (docs/specs/suporte-pedro-whatsapp): um
 * telefone, e quem está falando nela agora — o Pedro ou um humano.
 */
class SuporteConversa extends Model
{
    /** A Meta só aceita texto livre até 24 h depois da última mensagem do cliente. */
    public const JANELA_HORAS = 24;

    /** Quanto tempo o Pedro fica quieto depois que um humano responde. */
    public const HUMANO_HORAS = 24;

    protected $table = 'suporte_conversas';

    protected $fillable = ['telefone', 'nome_perfil', 'cliente_id', 'situacao', 'humano_id', 'humano_ate',
        'ultima_entrada_em', 'ultima_mensagem_em', 'nao_lidas', 'tarefa_id', 'gestor_cliente_id', 'gestor_atendimento',
        'gestor_conversa_id', 'gestor_setor'];

    protected function casts(): array
    {
        return ['humano_ate' => 'datetime', 'ultima_entrada_em' => 'datetime', 'ultima_mensagem_em' => 'datetime', 'nao_lidas' => 'integer'];
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(SuporteMensagem::class, 'conversa_id')->orderBy('id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function humano(): BelongsTo
    {
        return $this->belongsTo(User::class, 'humano_id');
    }

    public function tarefa(): BelongsTo
    {
        return $this->belongsTo(Tarefa::class);
    }

    /** O registro do atendimento em curso (prioridade, prazo e nº do Gestor). */
    public function atendimento(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SuporteAtendimento::class, 'tarefa_id', 'tarefa_id');
    }

    /** Ainda dá para responder com texto livre? */
    public function janelaAberta(): bool
    {
        return $this->ultima_entrada_em !== null && $this->ultima_entrada_em->gt(now()->subHours(self::JANELA_HORAS));
    }

    /** Um humano está com a conversa (o Pedro não fala). Passado o prazo, volta ao Pedro. */
    public function comHumano(): bool
    {
        return $this->situacao === 'humano' && (! $this->humano_ate || $this->humano_ate->isFuture());
    }

    /** "+55 (27) 99999-9999" para a tela, a partir do número da Meta (só dígitos). */
    public function telefoneLegivel(): string
    {
        $d = preg_replace('/\D/', '', $this->telefone);
        if (str_starts_with($d, '55') && strlen($d) >= 12) {
            $local = substr($d, 4);

            return '+55 ('.substr($d, 2, 2).') '.(strlen($local) > 8 ? substr($local, 0, 5).'-'.substr($local, 5) : substr($local, 0, 4).'-'.substr($local, 4));
        }

        return '+'.$d;
    }
}
