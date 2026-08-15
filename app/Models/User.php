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

    /** Os leads onde esta conta é a vendedora — o funil que `temEscopoComercial()` restringe. */
    public function leadsComoVendedor(): HasMany
    {
        return $this->hasMany(Lead::class, 'vendedor_id');
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
     * Vendedor sem visão gerencial: só os PRÓPRIOS leads no funil, e só o
     * PRÓPRIO desempenho no Dashboard Comercial — não o da empresa inteira.
     *
     * A pergunta não é "tem o perfil Comercial?" — é "não tem `dashboard`?".
     * Escopo de PESSOA e escopo de REVENDA são eixos diferentes: quem tem
     * `dashboard` (admin, financeiro, operação) vê o funil de todo mundo
     * mesmo se por acaso também tivesse o perfil comercial: a checagem por
     * PERFIL exigiria excluir esse caso à mão, e cresceria a cada perfil novo
     * que combinasse os dois. Checando a AUSÊNCIA de `dashboard`, quem já não
     * vê o dinheiro da casa também não vê o funil de quem não é ele — é a
     * mesma régua, perguntada uma vez só.
     */
    public function temEscopoComercial(): bool
    {
        return ! $this->temEscopoDeRevenda() && ! $this->canPermissao('dashboard', 'ler');
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

    /**
     * As quatro ações que o modelo de acesso conhece — as colunas de
     * `perfil_permissao`. Lista fixa porque ela vira nome de coluna: o método
     * antigo interpolava `$acao` direto no `where`, e uma ação escrita errado
     * derrubava a consulta em vez de simplesmente responder "não pode".
     */
    private const ACOES = ['ler', 'incluir', 'editar', 'imprimir', 'excluir'];

    /**
     * O que esta conta pode, achatado em `recurso:acao` — resolvido UMA vez.
     *
     * @var array<string, true>|null
     */
    private ?array $permissoesConcedidas = null;

    public function canPermissao(string $recurso, string $acao): bool
    {
        return isset($this->permissoesConcedidas()[$recurso.':'.$acao]);
    }

    /**
     * Joga fora o que esta conta pode, para ser perguntado de novo.
     *
     * Chamado no começo de toda requisição por `PermissoesDaRequisicao` — é o
     * que fixa o tempo de vida do cache em uma requisição. Também serve a quem
     * mexe na grade e precisa reler no MESMO processo, como a tela de perfis.
     */
    public function esquecerPermissoes(): void
    {
        $this->permissoesConcedidas = null;
    }

    /**
     * Tudo que esta conta pode, numa consulta só.
     *
     * A pergunta é a MESMA o tempo todo e era feita ao banco a cada vez: a
     * sidebar decide 14 links, o sino e a linha do tempo perguntam pelos seus,
     * e o quadro repete três vezes por card. Medido em 14/08/2026, eram 17
     * consultas em qualquer tela e 379 num quadro com 120 tarefas — 95% da
     * tela era a mesma pergunta.
     *
     * O cache vive na INSTÂNCIA do modelo, e não no cache da aplicação: o
     * guard devolve o mesmo objeto durante a requisição inteira, então isto
     * cobre o carregamento; e permissão que sobrevivesse ao request seria
     * permissão que não se revoga — quem perde acesso continuaria entrando até
     * o cache expirar.
     *
     * Um `join` e não o `whereHas` de antes: nem `Perfil` nem `Permissao` têm
     * exclusão reversível, então as duas formas enxergam exatamente as mesmas
     * linhas. Ainda sai pela relação `perfis()` para que o nome da tabela de
     * ligação continue morando num lugar só.
     *
     * @return array<string, true>
     */
    private function permissoesConcedidas(): array
    {
        if ($this->permissoesConcedidas !== null) {
            return $this->permissoesConcedidas;
        }

        $concedidas = [];

        $linhas = $this->perfis()
            ->join('perfil_permissao', 'perfil_permissao.perfil_id', '=', 'perfis.id')
            ->join('permissoes', 'permissoes.id', '=', 'perfil_permissao.permissao_id')
            ->get([
                'permissoes.recurso as recurso',
                'perfil_permissao.ler as ler',
                'perfil_permissao.incluir as incluir',
                'perfil_permissao.editar as editar',
                'perfil_permissao.imprimir as imprimir',
                'perfil_permissao.excluir as excluir',
            ]);

        foreach ($linhas as $linha) {
            foreach (self::ACOES as $acao) {
                // Duas contas podem conceder o mesmo recurso por perfis
                // diferentes: basta UMA linha permitir, que é o que o `exists`
                // de antes respondia.
                if ($linha->$acao) {
                    $concedidas[$linha->recurso.':'.$acao] = true;
                }
            }
        }

        return $this->permissoesConcedidas = $concedidas;
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
            // O painel PRÓPRIO do perfil `comercial` (15/08/2026) — antes ele
            // não tinha painel nenhum e caía direto no funil (comentário
            // antigo abaixo de `leads`, ainda válido para quem só tem `leads`
            // sem `dashboard_comercial`, caso raro hoje). Fica ANTES de
            // `leads` pela mesma razão de `dashboard` vir primeiro: é a casa
            // de quem a tem, não uma parada no caminho para outra tela.
            'dashboard_comercial' => 'comercial',
            // O funil vem ANTES de clientes: quem vende e não tem painel —
            // hoje só um `leads` avulso sem `dashboard_comercial` — cairia na
            // carteira já formada, e não na fila que trabalha o dia inteiro.
            // Não muda a casa de ninguém mais: os outros perfis com `leads`
            // também têm `dashboard`, e param na primeira linha.
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
     * Esta conta tem o perfil Administrador?
     *
     * A pergunta era feita solta, com `perfis->contains('slug', 'admin')`
     * repetido em três pontos de `UsuarioController` — e um deles escrito
     * errado (`'admin'` vs `'administrador'`, digamos) falharia calado, sem o
     * PHP acusar nada. Nomeando o método, o erro de digitação vira erro de
     * sintaxe em vez de buraco de segurança.
     */
    public function ehAdmin(): bool
    {
        return $this->perfis->contains('slug', 'admin');
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
