<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A hora marcada com outras pessoas.
 *
 * A Agenda tem duas fontes que nunca se misturam visualmente: o PRAZO, que sai
 * de `tarefas.prazo` e diz quando algo precisa estar pronto, e o COMPROMISSO,
 * que é este modelo e diz quando as pessoas se encontram. São perguntas
 * diferentes, e por isso a tela as rotula diferente ("Tarefa" e "Compromisso")
 * em toda visão.
 *
 * O compromisso tem botão de SALVAR explícito, ao contrário do espaço pessoal
 * de notas: ele envolve outras pessoas, e salvar sozinho a cada tecla mandaria
 * meia reunião para a agenda de quem participa.
 */
class Compromisso extends Model
{
    use HasFactory;

    protected $table = 'compromissos';

    protected $fillable = [
        'titulo', 'descricao', 'categoria', 'data', 'hora', 'data_fim', 'hora_fim',
        'duracao_modo', 'duracao_horas', 'criado_por_id', 'tarefa_id',
        'lembrete_enviado_em',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'date',
            'data_fim' => 'date',
            'duracao_modo' => 'boolean',
            'duracao_horas' => 'decimal:2',
            'lembrete_enviado_em' => 'datetime',
        ];
    }

    /**
     * A duração mínima que o formulário aceita, em horas.
     *
     * Quinze minutos: é o passo do campo (`step=0.25`) e também o piso. Zero
     * seria um compromisso sem intervalo — um instante na agenda, que nenhuma
     * visão consegue desenhar e nenhuma sobreposição consegue detectar.
     */
    public const DURACAO_MINIMA = 0.25;

    /**
     * Quantos minutos antes do início o lembrete é enviado.
     *
     * Trinta: perto o bastante para ser "já vai começar", longe o bastante
     * para dar tempo de fechar o que se está fazendo e abrir a chamada. O
     * comando que varre a agenda roda a cada cinco minutos, então o aviso real
     * cai entre 25 e 30 minutos antes — a janela é maior que o passo do
     * agendador de propósito, senão um compromisso escaparia entre duas
     * passadas.
     */
    public const LEMBRETE_MINUTOS = 30;

    /**
     * As categorias do compromisso — e a cor de cada uma.
     *
     * A chave é o que fica no banco; `rotulo` é o nome na tela; `tom` é o TOKEN
     * de cor do sistema (não um valor cru: `exame`, `good`, `warn`, `pergunta`,
     * `triagem` já existem no `app.css`, com distância perceptual medida). Cor
     * inventada é proibida por regra do repo — daí reusar a paleta.
     *
     * `interna` é a primeira porque é o padrão (o azul de hoje): compromisso sem
     * categoria escolhida, ou de antes desta mudança, é reunião interna.
     */
    public const CATEGORIAS = [
        'interna' => ['rotulo' => 'Reunião interna', 'tom' => 'exame'],
        'cliente' => ['rotulo' => 'Com cliente', 'tom' => 'good'],
        'deploy' => ['rotulo' => 'Deploy / manutenção', 'tom' => 'warn'],
        'externo' => ['rotulo' => 'Externo / evento', 'tom' => 'pergunta'],
        'foco' => ['rotulo' => 'Foco / pessoal', 'tom' => 'triagem'],
    ];

    /**
     * O token de cor deste compromisso — o nome da variável CSS, direto.
     *
     * Categoria desconhecida (dado antigo, chave removida) cai no `exame`, o
     * padrão: a tela nunca fica sem cor por causa de um valor que saiu do mapa.
     */
    public function corToken(): string
    {
        return self::CATEGORIAS[$this->categoria]['tom'] ?? 'exame';
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por_id');
    }

    public function tarefa(): BelongsTo
    {
        return $this->belongsTo(Tarefa::class);
    }

    public function participantes(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'compromisso_participantes')
            ->withPivot('data')
            ->orderBy('name');
    }

    /**
     * O início e o término como instantes, e não como quatro colunas soltas.
     *
     * Tudo o que compara compromisso com compromisso — sobreposição, ordenação
     * dentro do dia, "termina no dia seguinte" — precisa dos dois pontos na
     * linha do tempo. Montá-los aqui, uma vez, é o que impede cada chamador de
     * recombinar data e hora do seu jeito: `data` é `date` e vem com 00:00
     * grudado, então um `->format()` desatento produz "10:00" a partir de um
     * compromisso das 14h.
     */
    public function comecaEm(): Carbon
    {
        return $this->instante($this->data, $this->hora);
    }

    public function terminaEm(): Carbon
    {
        return $this->instante($this->data_fim, $this->hora_fim);
    }

    private function instante(mixed $data, mixed $hora): Carbon
    {
        return Carbon::parse(
            Carbon::parse($data)->format('Y-m-d').' '.Carbon::parse($hora)->format('H:i:s')
        );
    }

    /** "09:00–10:00" — o intervalo como a tela o mostra. */
    public function intervalo(): string
    {
        return $this->comecaEm()->format('H:i').'–'.$this->terminaEm()->format('H:i');
    }

    /**
     * Este compromisso atravessa a meia-noite?
     *
     * A tela precisa saber para acrescentar "(dia seguinte)" ao término
     * calculado. Sem isso, um compromisso das 23h com duas horas anuncia que
     * termina "à 01:00" — antes do próprio começo, pelo que está escrito.
     */
    public function viraODia(): bool
    {
        return $this->terminaEm()->toDateString() !== $this->comecaEm()->toDateString();
    }

    /**
     * Calcula o término a partir do início e das horas de duração.
     *
     * Vive no modelo, e não na tela, porque a mesma conta é feita em dois
     * lugares com donos diferentes: o Alpine a refaz a cada tecla para mostrar
     * "1,5 horas · termina às 11:30" enquanto a pessoa digita, e o servidor a
     * refaz ao salvar. Duas implementações divergiriam no primeiro caso de
     * borda — e o primeiro caso de borda aqui é a meia-noite.
     */
    public static function terminoPorDuracao(string $data, string $hora, float $horas): Carbon
    {
        return Carbon::parse($data.' '.$hora)->addMinutes((int) round($horas * 60));
    }

    /**
     * Grava os participantes e a data que eles repetem.
     *
     * A `data` existe duplicada em `compromisso_participantes` para que a carga
     * por pessoa não precise de join (ver a migração). Duplicata só é segura
     * enquanto UM lugar a mantém: é este método, e é por isso que ninguém mais
     * escreve naquela tabela — remarcar o compromisso sem passar por aqui
     * deixaria a carga apontando para o dia antigo.
     *
     * @param  array<int, int>  $ids
     */
    public function sincronizarParticipantes(array $ids): void
    {
        $data = Carbon::parse($this->data)->toDateString();

        $this->participantes()->sync(
            collect($ids)->unique()->mapWithKeys(fn (int $id) => [$id => ['data' => $data]])->all()
        );
    }

    /**
     * Os compromissos de uma FAIXA de dias — a consulta das três visões.
     *
     * Semana, Mês e Lista mudam só o tamanho da faixa; o recorte é o mesmo, e
     * um escopo só é o que garante que as três concordem sobre o que existe
     * naquele intervalo.
     */
    public function scopeNaFaixa(Builder $query, Carbon $de, Carbon $ate): Builder
    {
        // SOBREPOSIÇÃO, não "começa na faixa": um compromisso que começa antes
        // da faixa e termina dentro dela precisa aparecer — senão o que dura
        // vários dias some do meio e do fim, e só a coluna do início o mostra.
        // O intervalo [data, data_fim] cruza [de, ate] quando começa até `ate` e
        // termina em `de` ou depois.
        //
        // `whereDate` pelo mesmo motivo do `AgendaService::prazos`: o cast
        // `date` grava `Y-m-d H:i:s`, que o MySQL trunca e o SQLite não.
        return $query
            ->whereDate('data', '<=', $ate->toDateString())
            ->whereDate('data_fim', '>=', $de->toDateString());
    }

    /** Só os compromissos de que estas pessoas participam — o filtro de chips. */
    public function scopeDeParticipantes(Builder $query, array $ids): Builder
    {
        if ($ids === []) {
            return $query;
        }

        return $query->whereExists(
            fn ($sub) => $sub->select(DB::raw(1))
                ->from('compromisso_participantes')
                ->whereColumn('compromisso_participantes.compromisso_id', 'compromissos.id')
                ->whereIn('compromisso_participantes.user_id', $ids)
        );
    }

    /**
     * Os compromissos que estão para começar e ainda não avisaram.
     *
     * A janela é [agora, agora + LEMBRETE_MINUTOS], e o início tem de ser no
     * futuro: um compromisso criado depois de já ter começado não manda
     * "começa em -3 min". `lembrete_enviado_em` nulo é o que evita a repetição
     * — quem já avisou não entra de novo.
     *
     * O filtro de janela é por `data` (que tem índice) antes da comparação fina
     * do instante em PHP, pelo mesmo motivo de `AgendaService::conflitos`: o
     * início mora em duas colunas, e recompô-lo em SQL custaria o índice.
     */
    public function scopeALembrar(Builder $query, Carbon $agora): Builder
    {
        return $query
            ->whereNull('lembrete_enviado_em')
            ->whereDate('data', '>=', $agora->toDateString())
            ->whereDate('data', '<=', $agora->copy()->addMinutes(self::LEMBRETE_MINUTOS)->toDateString());
    }

    /**
     * Quem pode mexer neste compromisso?
     *
     * Quem o criou, e quem faz triagem. A régua do membro é ter MARCADO, e não
     * participar: reunião com quatro pessoas daria a quatro pessoas o poder de
     * remarcá-la, e quem só frequenta não é quem combinou. Triagem entra pela
     * mesma porta que abre no quadro — organizar o trabalho dos outros é o que
     * aquela capacidade significa.
     *
     * Devolve booleano e não frase, ao contrário de `motivoParaNaoMover`: aqui
     * a recusa tem uma razão só, e o modal a diz de uma vez ("somente leitura").
     */
    public function podeSerEditadoPor(?User $usuario): bool
    {
        if ($usuario === null) {
            return false;
        }

        return $this->criado_por_id === $usuario->id || $usuario->podeTriarTarefas();
    }
}
