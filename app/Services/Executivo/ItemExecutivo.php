<?php

namespace App\Services\Executivo;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * O item comum da API Executiva (spec `api-executiva-acompanhamentos`, FR-101).
 *
 * É o ÚNICO lugar que monta o item: `null` quando falta, e nenhum texto livre.
 * Todo campo passa por regra explícita — lista fechada (tipo, ator, situação,
 * prioridade, classe, motivos, critério, sentido), a lista de empresas do grupo
 * (`cliente_empresa`) ou formato (id e ligações `matriz:...`, `link` relativo
 * sem host). Valor fora da regra nunca sai cru — vira o padrão conservador,
 * porque o campo de origem pode ter recebido qualquer coisa. O `id` é o único
 * que não tem padrão: id malformado é erro de quem monta, e a exceção vira
 * `falha` no envelope em vez de publicar um identificador inventado.
 * Nome de pessoa só sai se for de conta ativa (primeiro nome); nome de cliente
 * nunca sai nesta versão (FR-110).
 */
class ItemExecutivo
{
    public const TIPOS = ['decisao', 'pergunta', 'validacao', 'retorno', 'prazo', 'ponto_gestao', 'alerta', 'proposta',
        'cobranca', 'chamado', 'proximo_passo', 'atividade', 'notificacao', 'email'];

    public const SITUACOES = ['aberta', 'em_andamento', 'aguardando_resposta', 'aguardando_aprovacao', 'vencida', 'parada',
        'sem_tecnico', 'em_triagem', 'visita_vencida', 'concluida', 'cancelada', 'desconhecida'];

    /** FR-105: `cliente_confirmado` está reservada e não é emitida nesta versão. */
    public const CLASSES = ['cliente_confirmado', 'indefinida'];

    /** Empresas do grupo (as de CentralDeGestao::EMPRESAS) que podem sair em `cliente_empresa` (nome de cliente final nunca). */
    public const EMPRESAS = ['Grupo Soluções', 'Invest', 'Delta'];

    /** `matriz:<recurso>:<id>[:<tipo>]` — o único formato de id e de ligação. */
    private const PADRAO_ID = '/^matriz:(tarefa:\d+:[a-z_]+|notificacao:\d+|ponto:\d+|alerta:\d+|email:\d+|atividade:[a-z]+:\d+)$/';

    /** Caminho relativo da própria Matriz, com query simples; nunca host, esquema ou `//`. */
    private const PADRAO_LINK = '#^/(?!/)[A-Za-z0-9/_.-]*(\?[A-Za-z0-9_=&%-]*)?$#';

    public const ATORES = ['felipe', 'rossini', 'alexandre', 'equipe', 'cliente', 'terceiro', 'indefinido'];

    public const PRIORIDADES = ['alta', 'normal', 'baixa'];

    public const MOTIVOS = ['visita_vencida', 'triagem', 'sem_empresa', 'sem_tecnico', 'parado'];

    public const CRITERIOS = ['obrigacao_aberta_nominal', 'proposta_total_estimado'];

    public const SENTIDOS = ['a_receber', 'a_pagar', 'potencial_receita'];

    /** FR-115 — prioridade da tarefa. Fora do mapa (inclusive `nao_definida`) = null. */
    public const PRIORIDADE_DA_TAREFA = ['critica' => 'alta', 'alta' => 'alta', 'media' => 'normal', 'baixa' => 'baixa'];

    /** FR-115 — situação da tarefa. */
    public const SITUACAO_DA_TAREFA = ['aberta' => 'aberta', 'backlog' => 'aberta', 'em_desenvolvimento' => 'em_andamento',
        'em_revisao' => 'em_andamento', 'em_staging' => 'em_andamento', 'em_producao' => 'em_andamento',
        'concluida' => 'concluida', 'cancelada' => 'cancelada'];

    /** @var array<int, string>|null id => primeiro nome, só contas ativas */
    private ?array $nomes = null;

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    public function montar(array $c): array
    {
        $aberta = (bool) ($c['aberta'] ?? false);

        return [
            'id' => $this->id($c['id'] ?? null),
            'origem' => 'matriz',
            'tipo' => $this->lista($c['tipo'] ?? null, self::TIPOS, 'alerta'),
            'classe' => $this->lista($c['classe'] ?? null, self::CLASSES, null),
            'ator_esperado' => $this->lista($c['ator_esperado'] ?? null, self::ATORES, 'indefinido'),
            'responsavel' => $this->nome($c['responsavel_id'] ?? null),
            'cliente_empresa' => $this->lista($c['cliente_empresa'] ?? null, self::EMPRESAS, null),
            'situacao' => $this->lista($c['situacao'] ?? null, self::SITUACOES, 'desconhecida'),
            'prioridade' => $this->lista($c['prioridade'] ?? null, self::PRIORIDADES, null),
            'prazo' => $this->data($c['prazo'] ?? null, 'Y-m-d'),
            'atualizado_em' => $this->data($c['atualizado_em'] ?? null, DATE_ATOM),
            'aberta' => $aberta,
            'historica' => ! $aberta,
            'possivel_duplicata_de' => $this->formato($c['possivel_duplicata_de'] ?? null, self::PADRAO_ID),
            'notificacao_relacionada' => $this->formato($c['notificacao_relacionada'] ?? null, self::PADRAO_ID),
            'link' => $this->formato($c['link'] ?? null, self::PADRAO_LINK),
            'motivos' => array_values(array_intersect(self::MOTIVOS, (array) ($c['motivos'] ?? []))),
            'valor_centavos' => isset($c['valor_centavos']) ? (int) $c['valor_centavos'] : null,
            'criterio_valor' => $this->lista($c['criterio_valor'] ?? null, self::CRITERIOS, null),
            'sentido_valor' => $this->lista($c['sentido_valor'] ?? null, self::SENTIDOS, null),
        ];
    }

    /** Primeiro nome de conta ativa; qualquer outra coisa é null (nunca texto gravado). */
    public function nome(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }
        $this->nomes ??= User::query()->where('ativo', true)->get(['id', 'name'])
            ->mapWithKeys(fn ($u) => [$u->id => (string) strtok(trim((string) $u->name), ' ')])->all();

        $nome = $this->nomes[$userId] ?? '';

        return $nome !== '' && preg_match('/^\p{L}{2,20}$/u', $nome) ? $nome : null;
    }

    /** @param  list<string>  $permitidos */
    private function lista(mixed $valor, array $permitidos, ?string $padrao): ?string
    {
        return is_string($valor) && in_array($valor, $permitidos, true) ? $valor : $padrao;
    }

    private function id(mixed $valor): string
    {
        $id = $this->formato($valor, self::PADRAO_ID);
        if ($id === null) {
            throw new \InvalidArgumentException('Identificador de item fora do contrato.');
        }

        return $id;
    }

    private function formato(mixed $valor, string $padrao): ?string
    {
        return is_string($valor) && strlen($valor) <= 200 && preg_match($padrao, $valor) === 1 ? $valor : null;
    }

    private function data(mixed $valor, string $formato): ?string
    {
        return $valor instanceof CarbonInterface ? $valor->format($formato) : null;
    }
}
