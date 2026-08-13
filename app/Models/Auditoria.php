<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * Uma linha do rastro: quem, o quê, sobre qual registro, quando.
 *
 * A escrita passa toda por `registrar()`. Não é cerimônia: é o único jeito de
 * o ator, o IP e o agente virem do mesmo lugar em todos os pontos de gravação —
 * são dez chamadores hoje, e o primeiro a montar o array à mão esquece o IP.
 */
class Auditoria extends Model
{
    protected $table = 'auditorias';

    /**
     * A linha não é alterada, então `updated_at` só saberia repetir o irmão.
     * Desligar aqui é o que impede o Eloquent de procurar uma coluna que a
     * migração não criou.
     */
    public const UPDATED_AT = null;

    /**
     * O que aparece no lugar do valor de um campo sensível.
     *
     * A senha é gravada em hash, e nem o hash entra aqui: ele é material para
     * quebra offline, e uma tabela que ninguém edita é o lugar mais confortável
     * do mundo para ele envelhecer. Guardar a MARCA e não o valor mantém a
     * única informação que a auditoria precisa dar — que o campo mudou.
     */
    public const OCULTO = '(oculto)';

    /**
     * Interruptor de gravação, para os dois casos em que a linha automática
     * atrapalha mais do que informa.
     *
     * O SEEDER. `db:seed` cria centenas de registros de uma vez, todos sem
     * ator, e a primeira coisa que a tela mostraria num ambiente novo seriam
     * 800 linhas de "Sistema criou cliente" — ruído que empurra para fora da
     * primeira página justamente o que se foi olhar. Em produção não muda nada:
     * lá o deploy roda só `migrate --force`.
     *
     * E quem tem uma linha MELHOR para escrever. A redefinição de senha, por
     * exemplo, é um `update` como outro qualquer para o Eloquent — o trait a
     * registraria como "alterou usuário", que é verdade e não é a informação.
     * Ali o controller cala o automático e grava o que a ação realmente foi.
     */
    protected static bool $registrando = true;

    protected $fillable = [
        'user_id', 'usuario_nome', 'recurso', 'acao',
        'auditavel_type', 'auditavel_id', 'descricao', 'alteracoes', 'ip', 'agente',
    ];

    protected function casts(): array
    {
        return [
            'alteracoes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * A linha é imutável, e a recusa é uma exceção — não um `return false`.
     *
     * O evento do Eloquent aceita `false` como "cancele em silêncio", e silêncio
     * aqui seria o pior dos dois mundos: quem tentasse corrigir uma linha veria
     * `save()` retornar sem erro e concluiria que corrigiu.
     *
     * `deleting` NÃO é bloqueado de propósito. A tabela só cresce, e um dia
     * alguém vai precisar descartar o que passou do prazo de guarda; travar a
     * exclusão aqui só empurraria essa limpeza para um `DELETE` cru, longe de
     * qualquer regra. O que a auditoria precisa garantir é que ninguém REESCREVA
     * o que ela diz.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Linha de auditoria não se altera.');
        });
    }

    /**
     * Grava um fato.
     *
     * `$ator` é para quem o `auth()` não sabe responder, e são dois casos
     * opostos. A CONTA, quando a sessão não a tem mais: a saída do painel é
     * registrada no momento em que o guard já desmontou a sessão, e ler o
     * usuário dali é apostar numa ordem interna do Laravel. E o TEXTO, quando
     * não há conta nenhuma: na senha recusada, o e-mail digitado é tudo o que
     * existe de identidade — e pode nem corresponder a uma conta.
     *
     * @param  array<string, array{de: mixed, para: mixed}>|null  $alteracoes
     */
    public static function registrar(
        string $recurso,
        string $acao,
        ?Model $alvo = null,
        ?string $descricao = null,
        ?array $alteracoes = null,
        User|string|null $ator = null,
    ): ?self {
        if (! static::$registrando) {
            return null;
        }

        $usuario = $ator instanceof User ? $ator : auth()->user();

        // A requisição pode não existir — comando de console, fila. Ler o IP
        // nesse caso devolve o endereço da máquina, que é uma informação falsa
        // sobre "de onde veio": ninguém veio de lugar nenhum.
        $requisicao = app()->runningInConsole() ? null : request();

        return static::create([
            'user_id' => $usuario?->id,
            'usuario_nome' => is_string($ator) ? $ator : ($usuario?->name ?? 'Sistema'),
            'recurso' => $recurso,
            'acao' => $acao,
            'auditavel_type' => $alvo?->getMorphClass(),
            'auditavel_id' => $alvo?->getKey(),
            'descricao' => $descricao,
            'alteracoes' => $alteracoes ?: null,
            'ip' => $requisicao?->ip(),
            // O agente é texto de terceiro e não tem limite: navegador com
            // barra de ferramentas passa de 500 caracteres e estoura a coluna.
            // Cortar aqui, e não confiar no banco, porque o MySQL em modo
            // estrito recusaria a linha inteira — e perder o fato para salvar o
            // nome do navegador é a troca errada.
            'agente' => $requisicao ? Str::limit((string) $requisicao->userAgent(), 250, '') : null,
        ]);
    }

    /**
     * Roda o bloco sem gravar nada.
     *
     * @template T
     *
     * @param  callable(): T  $bloco
     * @return T
     */
    public static function semRegistro(callable $bloco)
    {
        $antes = static::$registrando;
        static::$registrando = false;

        try {
            return $bloco();
        } finally {
            // No `finally` porque o seeder que estoura no meio deixaria a
            // gravação desligada para o resto do processo — e o sintoma seria
            // uma auditoria que simplesmente não registra mais nada, sem erro
            // nenhum apontando para cá.
            static::$registrando = $antes;
        }
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function auditavel(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * O rastro de UM registro, para a linha do tempo dentro da tela dele.
     *
     * Consulta por tipo e id, e não pela relação: o registro pode ter sido
     * excluído, e é aí que a pergunta mais aparece.
     *
     * @param  Builder<Auditoria>  $query
     */
    public function scopeDoRegistro(Builder $query, Model $registro): Builder
    {
        return $query
            ->where('auditavel_type', $registro->getMorphClass())
            ->where('auditavel_id', $registro->getKey());
    }

    /**
     * Rótulo humano da ação. Mora no modelo porque a tela de auditoria e a
     * linha do tempo do registro mostram o mesmo verbo — em dois lugares, eles
     * divergiriam na primeira ação nova.
     */
    public function rotuloDaAcao(): string
    {
        return static::rotuloDe($this->acao);
    }

    /**
     * A mesma tradução a partir da chave crua — é como o filtro da tela monta a
     * lista de opções, sem precisar inventar uma linha de auditoria só para
     * perguntar o nome de uma ação.
     */
    public static function rotuloDe(string $acao): string
    {
        return match ($acao) {
            'criou' => 'Criou',
            'alterou' => 'Alterou',
            'excluiu' => 'Excluiu',
            'restaurou' => 'Restaurou',
            'entrou' => 'Entrou',
            'saiu' => 'Saiu',
            'recusado' => 'Senha recusada',
            'bloqueado' => 'Bloqueado por tentativas',
            'permissoes' => 'Mudou permissões',
            'senha' => 'Redefiniu senha',
            'gerou' => 'Gerou',
            'baixou' => 'Baixou arquivo',
            'provisionou' => 'Provisionou',
            'licenca' => 'Licença',
            'sincronizou' => 'Sincronizou',
            default => Str::ucfirst($acao),
        };
    }

    /**
     * O nome de um campo como gente lê. `valor_mensal` vira `valor mensal`, e
     * nada mais: inventar rótulo bonito por campo exigiria um dicionário de
     * duzentas entradas que ficaria desatualizado na primeira coluna nova — e
     * um campo sem entrada apareceria em branco, que é pior que o nome cru.
     */
    public static function rotuloDoCampo(string $campo): string
    {
        return str_replace('_', ' ', $campo);
    }

    /**
     * Um valor gravado, como gente lê.
     *
     * O antes/depois é JSON e chega aqui como o banco o devolveu: booleano
     * virou 1 ou 0, decimal virou string, ausência virou nulo. Sem esta
     * tradução a tela mostraria "ativo: 1 → 0", que é verdade e não é resposta.
     */
    public static function valorLegivel(mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }

        if (is_bool($valor)) {
            return $valor ? 'sim' : 'não';
        }

        // O JSON não distingue o booleano do número: `ativo` volta como 1 e
        // `dia_vencimento` também. Arriscar a tradução para todo 0/1 faria o
        // dia 1 do mês virar "sim", então ela é feita fora daqui — na coluna,
        // não no valor — e este ramo só trata o que já veio como booleano.
        if (is_array($valor)) {
            return json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—';
        }

        return (string) $valor;
    }

    /**
     * O tom do selo. Vermelho é reservado ao que destrói ou ao que foi negado:
     * se toda ação sensível fosse vermelha, a lista inteira ficaria vermelha e
     * o vermelho pararia de significar alguma coisa.
     */
    public function tomDaAcao(): string
    {
        return match ($this->acao) {
            'excluiu', 'recusado', 'bloqueado' => 'critico',
            'criou', 'restaurou' => 'bom',
            'permissoes', 'senha', 'provisionou', 'licenca' => 'ambar',
            'gerou', 'baixou' => 'marca',
            default => 'neutro',
        };
    }
}
