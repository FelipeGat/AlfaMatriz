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
        'gestor_conversa_id', 'gestor_setor', 'fila_desde', 'fila_motivo', 'fila_lembrada_em',
        'solicitante_nome', 'solicitante_funcao', 'identificada_em'];

    protected function casts(): array
    {
        return ['identificada_em' => 'datetime', 'fila_desde' => 'datetime', 'fila_lembrada_em' => 'datetime', 'humano_ate' => 'datetime', 'ultima_entrada_em' => 'datetime', 'ultima_mensagem_em' => 'datetime', 'nao_lidas' => 'integer'];
    }

    /**
     * Cliente do Matriz traz o cliente dele no Gestor (T-488): todo chamado nasce no Gestor,
     * e a conversa ligada só pelo cadastro do Matriz ficava sem ter onde abrir — virava
     * tarefa no quadro. Aqui, e não em quem liga, porque são três caminhos (o telefone ao
     * criar a conversa, a confirmação do Pedro e o vínculo pela tela).
     */
    protected static function booted(): void
    {
        static::saving(function (self $c) {
            if ($c->cliente_id && ! $c->gestor_cliente_id) {
                $c->gestor_cliente_id = Cliente::whereKey($c->cliente_id)->value('gestor_cliente_id');
            }
        });
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(SuporteMensagem::class, 'conversa_id')->orderBy('id');
    }

    /**
     * A última mensagem do atendimento já encerrado (null: nenhum encerramento ainda).
     * A conversa é uma só por telefone; o encerramento é o que separa um atendimento do
     * outro. Corte pelo id, e não pela hora: no mesmo segundo do encerramento, comparar
     * horário deixaria a mensagem antiga do lado de cá.
     */
    public function fimDoAtendimentoAnterior(): ?int
    {
        $encerrou = SuporteAvaliacao::where('conversa_id', $this->id)->max('created_at');

        return $encerrou ? $this->mensagens()->reorder()->where('created_at', '<=', $encerrou)->max('id') : null;
    }

    /**
     * As mensagens do atendimento em curso: as que vieram desde o último encerramento.
     * Servem ao que é do ASSUNTO de agora (fotos, base do suporte, registro). O histórico
     * que o Pedro lê continua inteiro, com o atendimento encerrado marcado (Contexto::mensagens).
     */
    public function mensagensDoAtendimento(): HasMany
    {
        $fim = $this->fimDoAtendimentoAnterior();

        return $fim ? $this->mensagens()->where('id', '>', $fim) : $this->mensagens();
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

    /**
     * A pessoa já disse quem é e de onde fala (T-478, decisão do dono em 21/10/2026)?
     * As duas coisas: o cliente confirmado (Matriz ou Gestor) E o nome de quem escreve.
     * Até lá o Pedro só pede a identificação — chamado, roteiro e histórico esperam.
     */
    public function identificada(): bool
    {
        return ($this->cliente_id || $this->gestor_cliente_id) && filled($this->solicitante_nome);
    }

    /** Como o solicitante vai no atendimento: "Maria (porteira)", ou o nome do WhatsApp se ainda não disse. */
    public function solicitante(): string
    {
        $nome = $this->solicitante_nome ?: $this->nome_perfil ?: 'Contato pelo WhatsApp';

        return $this->solicitante_nome && $this->solicitante_funcao ? "{$nome} ({$this->solicitante_funcao})" : $nome;
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
