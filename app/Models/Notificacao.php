<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um evento que alguém precisa saber sem estar olhando para o quadro.
 *
 * Aqui vivem os EVENTOS — o que aconteceu num instante e tem dono. As
 * CONDIÇÕES ("3 receitas em atraso", "tarefa travada há 6 dias") continuam na
 * fila de ação do Centro de Controle, onde são recalculadas: elas valem
 * enquanto durarem, e gravá-las viraria uma linha nova a cada dia repetindo o
 * mesmo aviso. É por isso que o rodapé do painel leva para lá — o sino conta o
 * que mudou, a fila mostra o que exige ação.
 */
class Notificacao extends Model
{
    use HasFactory;

    protected $table = 'notificacoes';

    protected $fillable = [
        'destinatario_id', 'tipo', 'nivel', 'sonora', 'icone', 'titulo', 'meta', 'rota', 'tarefa_id',
    ];

    /**
     * Os avisos que tocam som: o que DEPENDE de quem recebe (#312).
     *
     * Ganhou a tarefa, tem de validar, recebeu pergunta ou a resposta à sua,
     * a tarefa voltou para corrigir ou travou, o compromisso marcado com ela.
     * Fica de fora o que é só notícia (concluiu, arquivou, PR aberto, triagem):
     * o som que toca para tudo ensina a desligá-lo, e aí ele não toca para o
     * que importa. A triagem fica muda de propósito também por causa do vigia
     * de logs: uma rajada de erros viraria sirene para quem triaga.
     *
     * É o PADRÃO por tipo; quem avisa pode decidir caso a caso passando
     * `sonora` — o veredito só toca quando reprova, o comentário só para o
     * responsável e o validador.
     */
    public const TIPOS_SONOROS = [
        'direcionamento', 'apontamento', 'pergunta', 'resposta', 'retorno',
        'bloqueio', 'destravamento', 'compromisso',
    ];

    /**
     * Quanto cabe em `titulo` e `meta` — as duas são `string` (255) na tabela.
     *
     * Quem avisa junta nomes numa linha só ("os títulos das tarefas", "as
     * receitas de amanhã"), e um dia com muitos itens passava do limite: o
     * insert falhava e o comando agendado parava no meio, sem avisar ninguém
     * daquele dia (#331). O sino mostra uma linha só e corta o resto na tela,
     * então TEXT não ganharia nada; cortar aqui, antes de gravar, protege
     * todos os caminhos que avisam, inclusive os que ainda vão nascer.
     */
    public const LIMITE_TEXTO = 255;

    protected function casts(): array
    {
        return ['lida_em' => 'datetime', 'sonora' => 'boolean'];
    }

    /**
     * O texto cortado para caber em `$limite` caracteres, com "…" no fim.
     *
     * Conta caracteres, e não bytes (mb_): o MySQL mede o `varchar` em
     * caracteres, e cortar no meio de um acento gravaria um byte inválido.
     */
    public static function caber(?string $texto, int $limite = self::LIMITE_TEXTO): ?string
    {
        if ($texto === null || mb_strlen($texto) <= $limite) {
            return $texto;
        }

        return rtrim(mb_substr($texto, 0, max(0, $limite - 1))).'…';
    }

    protected function titulo(): Attribute
    {
        return Attribute::make(set: fn (?string $valor) => self::caber($valor));
    }

    protected function meta(): Attribute
    {
        return Attribute::make(set: fn (?string $valor) => self::caber($valor));
    }

    /**
     * O padrão do som entra no `creating`, e não só em `avisar`: os lembretes
     * do sistema gravam por `create` direto, e o tipo deles tem de valer igual.
     */
    protected static function booted(): void
    {
        static::creating(function (Notificacao $notificacao): void {
            if ($notificacao->sonora === null) {
                $notificacao->sonora = in_array($notificacao->tipo, self::TIPOS_SONOROS, true);
            }
        });
    }

    public function destinatario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destinatario_id');
    }

    public function tarefa(): BelongsTo
    {
        return $this->belongsTo(Tarefa::class);
    }

    public function naoLida(): bool
    {
        return $this->lida_em === null;
    }

    /**
     * Avisa alguém — menos ele mesmo.
     *
     * Quem age já sabe o que fez: notificar o autor da própria ação enche o
     * sino de eco e ensina a ignorá-lo. Devolve null nesse caso, e também
     * quando não há destinatário — tarefa sem responsável não tem a quem
     * avisar, e inventar um leitor seria pior do que não avisar.
     */
    public static function avisar(?int $destinatarioId, ?int $autorId, array $atributos): ?self
    {
        if ($destinatarioId === null || $destinatarioId === $autorId) {
            return null;
        }

        return self::create(['destinatario_id' => $destinatarioId] + $atributos);
    }

    /** As não lidas desta pessoa, mais recentes primeiro. */
    public function scopeNaoLidasDe($query, ?int $usuarioId)
    {
        return $query->where('destinatario_id', $usuarioId)->whereNull('lida_em')->latest('id');
    }
}
