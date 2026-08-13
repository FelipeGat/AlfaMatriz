<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\Auditavel;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditavel, HasFactory, Notifiable, SoftDeletes;

    protected string $recursoAuditoria = 'usuarios';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'primeiro_acesso',
        'ativo',
        'revenda_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'primeiro_acesso' => 'boolean',
            'ativo' => 'boolean',
        ];
    }

    public function perfis(): BelongsToMany
    {
        return $this->belongsToMany(Perfil::class, 'perfil_user');
    }

    public function revenda(): BelongsTo
    {
        return $this->belongsTo(Revenda::class);
    }

    /**
     * O trabalho ligado a esta pessoa. Existem para responder a UMA pergunta:
     * a conta desativada ainda aparece em alguma tarefa? Se aparece, ela
     * continua nas listas do quadro — some de lá e o `select` da tarefa antiga
     * perde o valor escolhido.
     */
    public function tarefas(): HasMany
    {
        return $this->hasMany(Tarefa::class, 'responsavel_id');
    }

    public function tarefasComoInterlocutor(): HasMany
    {
        return $this->hasMany(Tarefa::class, 'interlocutor_id');
    }

    /**
     * A conta é `name`, e não `nome`: é o único modelo do painel que herda o
     * vocabulário do Laravel em vez do nosso. Sem este método, toda linha de
     * auditoria sobre uma conta se apresentaria como "User #7" — o padrão do
     * trait procura `nome` e não acha nada.
     */
    public function descricaoDeAuditoria(): string
    {
        return $this->name;
    }

    /**
     * Usuário da matriz com acesso irrestrito (revenda_id null) ou de uma
     * revenda específica (só enxerga o próprio portfólio).
     */
    public function temEscopoDeRevenda(): bool
    {
        return $this->revenda_id !== null;
    }

    /**
     * Aplica o filtro por revenda numa query quando o usuário tem escopo
     * restrito. `$campo` aponta a coluna da revenda na query (default:
     * a própria tabela de revendas).
     */
    public function escopoDeRevenda($query, string $campo = 'revenda_id')
    {
        if ($this->temEscopoDeRevenda()) {
            $query->where($campo, $this->revenda_id);
        }

        return $query;
    }

    public function canPermissao(string $recurso, string $acao): bool
    {
        return $this->perfis()
            ->whereHas('permissoes', function ($q) use ($recurso, $acao) {
                $q->where('recurso', $recurso)->where("perfil_permissao.{$acao}", true);
            })
            ->exists();
    }

    /**
     * A primeira tela que este usuário realmente alcança.
     *
     * O destino depois do login era fixo — o Centro de Controle —, e isso valia
     * enquanto todo perfil o enxergava. Perfis estreitos (quem só trabalha no
     * quadro de tarefas, a revenda) passavam a receber **403 logo depois de
     * entrar**: a senha certa levando a uma parede se lê como conta quebrada, e
     * não como tela que não é sua.
     *
     * Mora aqui porque a pergunta é feita em três lugares — o login, o desvio
     * de quem já está autenticado e a marca no topo da sidebar. Em listas
     * separadas, elas divergiriam.
     *
     * A ordem é a de "casa": o Centro de Controle para quem o tem, e depois as
     * telas que costumam ser o dia inteiro de quem só tem uma. Sem nenhuma,
     * cai no perfil, que toda conta enxerga.
     */
    public function telaInicial(): string
    {
        // A chave é o RECURSO da permissão, não o nome da rota — o Centro de
        // Controle é guardado por `permissao:dashboard`, e usar o nome da tela
        // aqui faria a checagem falhar em silêncio para todo mundo, mandando
        // até o administrador para a segunda opção da lista.
        // A revenda vai para a carteira dela (AC-118), e não para a lista de
        // revendas com uma linha só. A regra morava na raiz `/`, o que fazia o
        // login mandá-la para um lugar e a raiz para outro; aqui os três
        // chamadores dão a mesma resposta.
        if ($this->temEscopoDeRevenda()) {
            return route('revendas.index', ['aba' => 'clientes'], absolute: false);
        }

        $telas = [
            'dashboard' => 'centro-controle',
            'tarefas' => 'tarefas.index',
            // O funil vem ANTES de clientes: quem vende e não tem painel — o
            // perfil `comercial` — cairia na carteira já formada, e não na fila
            // que ele trabalha o dia inteiro. Não muda a casa de ninguém mais:
            // os outros perfis com `leads` também têm `dashboard`, e param na
            // primeira linha.
            'leads' => 'leads.index',
            'clientes' => 'clientes.index',
            'revendas' => 'revendas.index',
        ];

        foreach ($telas as $recurso => $rota) {
            if ($this->canPermissao($recurso, 'ler')) {
                return route($rota, absolute: false);
            }
        }

        return route('profile.edit', absolute: false);
    }

    /**
     * Pode organizar o trabalho dos outros no quadro de tarefas?
     *
     * Triagem é decidir a prioridade e escolher quem faz — e, por consequência,
     * mexer no trabalho que está com outra pessoa. Quem não tem a capacidade
     * continua abrindo, comentando, bloqueando e tocando as próprias tarefas:
     * a regra é sobre organizar, não sobre trabalhar.
     *
     * Método nomeado, e não um `canPermissao` espalhado pelas telas, porque
     * esta pergunta aparece em seis lugares — formulário, card, arraste, menu,
     * cadastro e movimento — e um deles esquecido é uma tela dizendo que pode
     * o que a rota vai recusar.
     */
    public function podeTriarTarefas(): bool
    {
        return $this->canPermissao('tarefas_triagem', 'incluir');
    }
}
