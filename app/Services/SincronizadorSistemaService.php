<?php

namespace App\Services;

use App\Models\Auditoria;
use App\Models\Cliente;
use App\Models\ClienteModulo;
use App\Models\Modulo;
use App\Models\Revenda;
use App\Models\Sistema;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Puxa o retrato de um sistema integrado pelo contrato /api/matriz/v1 e
 * preenche as tabelas existentes de revendas e clientes, sem criar estrutura
 * nova.
 *
 * Idempotência por (sistema, id_externo na âncora): rodar de novo não duplica.
 * As licenças moram no vínculo cliente_sistema (o retrato da vigência).
 */
class SincronizadorSistemaService
{
    private readonly ContratoMatriz $contrato;

    public function __construct(private readonly Sistema $sistema)
    {
        $this->contrato = new ContratoMatriz($sistema);
    }

    /**
     * @return array<string, mixed> resumo para o relatório do comando
     */
    public function sincronizar(): array
    {
        if (! $this->contrato->configurado()) {
            return ['ok' => false, 'motivo' => 'Sistema sem endereço ou sem chave configurada.'];
        }

        try {
            $resumo = [
                'revendas' => $this->sincronizarRevendas(),
                'clientes' => $this->sincronizarClientes(),
                'licencas' => $this->sincronizarLicencas(),
                'modulos' => $this->sincronizarModulos(),
            ];

            $this->registrarCiclo($resumo);

            return ['ok' => true, 'resumo' => $resumo];
        } catch (RequestException $e) {
            return ['ok' => false, 'motivo' => $this->contrato->mensagemDeLeitura($e)];
        } catch (ConnectionException) {
            return ['ok' => false, 'motivo' => $this->contrato->mensagemDeConexao()];
        }
    }

    /**
     * O rastro de um ciclo de sincronização.
     *
     * UMA linha por ciclo, e não uma por registro tocado. As mudanças de
     * cliente e de módulo já deixam a própria linha — os modelos são auditados
     * e a sincronização escreve por eles. O que faltava era o contexto: sem
     * esta linha, quem abre a auditoria vê trinta clientes alterados por
     * "Sistema" às 3 da manhã e não tem como saber que foi um ciclo só, nem
     * quantos vínculos de licença foram espelhados — esses moram no pivô
     * `cliente_sistema`, que não dispara evento nenhum.
     *
     * Ciclo que não mexeu em nada não registra. O comando roda de hora em hora
     * e quase sempre não acha novidade; uma linha por execução encheria a tela
     * de "Sistema sincronizou: 0, 0, 0" e enterraria o dia em que houve algo.
     *
     * @param  array<string, array<string, mixed>>  $resumo
     */
    private function registrarCiclo(array $resumo): void
    {
        $numeros = [];

        foreach ($resumo as $area => $contagens) {
            foreach ($contagens as $chave => $valor) {
                // 'ignoradas' é estado, não movimento: a revenda excluída
                // segue excluída a cada ciclo, e uma linha por hora sobre o
                // mesmo fato enterraria o dia em que algo de fato mudou.
                if ($chave === 'ignoradas') {
                    continue;
                }

                if (is_int($valor) && $valor > 0) {
                    $numeros[$area.' · '.$chave] = ['de' => null, 'para' => $valor];
                }
            }
        }

        if ($numeros === []) {
            return;
        }

        Auditoria::registrar(
            recurso: 'sistemas',
            acao: 'sincronizou',
            alvo: $this->sistema,
            descricao: $this->sistema->nome,
            alteracoes: $numeros,
        );
    }

    private function sincronizarRevendas(): array
    {
        $criadas = $atualizadas = $ignoradas = 0;

        foreach ($this->todasPaginas('/revendas') as $item) {
            // Com os excluídos: a âncora é a identidade primária, e uma
            // revenda excluída SEM documento só é reconhecível por ela — a
            // reconciliação lá embaixo não tem documento por onde achá-la.
            $revenda = Revenda::porOrigemExterna($this->sistema, $item['id_externo'], comExcluidos: true);

            $dados = [
                'nome' => $item['nome'] ?? 'Sem nome',
                'cnpj' => $this->normalizarDocumento($item['cnpj'] ?? null),
                'contato_email' => $item['email'] ?? null,
                'contato_telefone' => $item['telefone'] ?? null,
                'ativo' => $item['ativo'] ?? true,
            ];

            // Sem âncora, antes de criar: a mesma revenda pode já existir na
            // Matriz, vinda de outro sistema ou de cadastro manual. Criar de
            // novo faria um gêmeo e dobraria o faturamento dela.
            $revenda ??= $this->reconciliarPorDocumento(Revenda::query(), 'cnpj', $dados['cnpj']);

            // Excluída na Matriz é decisão de gente, e o sincronizador não a
            // desfaz — nem conseguiria recriar: o índice único de CNPJ ainda
            // enxerga a linha excluída, e o INSERT derrubava o ciclo INTEIRO
            // de hora em hora, levando junto clientes, licenças e módulos.
            // Ela fica como está, e o resumo do comando diz quantas ficaram.
            if ($revenda?->trashed()) {
                $ignoradas++;

                continue;
            }

            if ($revenda) {
                $revenda->update($dados);
                // Reconciliada agora ou já ancorada: ancorar é idempotente.
                $revenda->ancorarEm($this->sistema, $item['id_externo']);
                $atualizadas++;
            } else {
                $revenda = Revenda::create($dados);
                $revenda->ancorarEm($this->sistema, $item['id_externo']);
                $criadas++;
            }
        }

        return ['criadas' => $criadas, 'atualizadas' => $atualizadas, 'ignoradas' => $ignoradas];
    }

    private function sincronizarClientes(): array
    {
        $criados = $atualizados = 0;

        foreach ($this->todasPaginas('/clientes') as $item) {
            $cliente = Cliente::porOrigemExterna($this->sistema, $item['id_externo']);

            $dados = [
                'nome' => $item['nome'] ?? 'Sem nome',
                'razao_social' => $item['razao_social'] ?? null,
                'cpf_cnpj' => $this->normalizarDocumento($item['cpf_cnpj'] ?? null),
                // `email` e `telefone` NÃO entram aqui: a tabela clientes não
                // tem essas colunas e a atribuição em massa os descartava em
                // silêncio. O contato mora em cliente_emails/cliente_telefones,
                // gravado logo abaixo, depois de o cliente existir.
                'cidade' => $item['cidade'] ?? null,
                'uf' => $item['uf'] ?? null,
                'ativo' => $item['ativo'] ?? true,
                'revenda_id' => $this->revendaPorIdExterno($item['revenda_id_externo'] ?? null)?->id,
            ];

            $cliente ??= $this->reconciliarPorDocumento(Cliente::query(), 'cpf_cnpj', $dados['cpf_cnpj']);

            if ($cliente) {
                $cliente->update($dados);
                $cliente->ancorarEm($this->sistema, $item['id_externo']);
                $atualizados++;
            } else {
                $cliente = Cliente::create($dados);
                $cliente->ancorarEm($this->sistema, $item['id_externo']);
                $criados++;
            }

            // Vínculo com o sistema (retrato local de "quem usa o quê").
            // `bloqueia_acesso` DERIVA do status do cliente: o campo de mesmo
            // nome vindo do gym é a política da licença ("bloquear ao vencer"),
            // sempre verdadeira — usá-lo aqui marcaria todo mundo como bloqueado.
            $cliente?->sistemas()->syncWithoutDetaching([$this->sistema->id => [
                'ativo' => $item['ativo'] ?? true,
                'status_saas' => $item['status'] ?? null,
                'bloqueia_acesso' => ($item['status'] ?? null) === 'bloqueado' ? 1 : 0,
            ]]);

            if ($cliente) {
                $this->guardarContato($cliente, $item['email'] ?? null, $item['telefone'] ?? null);
            }
        }

        return ['criados' => $criados, 'atualizados' => $atualizados];
    }

    /**
     * O retrato da licença — só para quem a Matriz sabe ler com segurança.
     *
     * Um sistema pode ter cadastro em ordem e licença não. Puxar o retrato de
     * uma base onde renovar encadeia licenças ativas faria a Matriz espelhar o
     * defeito e faturar em cima dele. Sem a capacidade, a licença fica
     * intocada: o cliente aparece na lista sem estado de licença, que é
     * honesto, em vez de aparecer com um estado errado.
     */
    private function sincronizarLicencas(): array
    {
        if (! $this->sistema->suporta('sincroniza_licencas')) {
            // Sem a capacidade, o retrato que sobrou de uma leitura anterior
            // fica congelado na tela para sempre — um estado que a Matriz não
            // consegue mais confirmar nem corrigir. Apagar é o honesto: é dado
            // derivado, e volta inteiro no primeiro ciclo depois de religar.
            return ['ignorado' => true, 'atualizadas' => 0, 'limpos' => $this->limparRetratoDeLicenca()];
        }

        $atualizadas = 0;

        foreach ($this->todasPaginas('/licencas') as $item) {
            $cliente = Cliente::porOrigemExterna($this->sistema, $item['cliente_id_externo'] ?? null);

            if (! $cliente) {
                continue;
            }

            $cliente->sistemas()->syncWithoutDetaching([$this->sistema->id => [
                'licenca_status' => $item['status'] ?? null,
                'plano' => $item['plano'] ?? null,
                // Nem todo sistema informa valor: o contrato do AlfaGym ainda
                // não expõe `valor`, e null aqui é o estado normal dele.
                'licenca_valor' => $item['valor'] ?? null,
                'licenca_inicio_em' => $item['inicio_em'] ?? null,
                'licenca_fim_em' => $item['fim_em'] ?? null,
                'licenca_id_externo' => $item['id_externo'] ?? null,
            ]]);

            $atualizadas++;
        }

        return ['atualizadas' => $atualizadas];
    }

    /**
     * Grava o contato que o AlfaGym informou, sem apagar nada.
     *
     * Ao contrário do formulário da tela (que regrava a lista inteira a cada
     * save), aqui o sync é um convidado: pode acrescentar o que a origem
     * conhece, nunca varrer o que o time cadastrou na Matriz — um e-mail
     * financeiro anotado aqui não pode sumir na próxima hora cheia.
     */
    private function guardarContato(Cliente $cliente, ?string $email, ?string $telefone): void
    {
        $email = trim((string) $email);
        $telefone = trim((string) $telefone);

        if ($email !== '') {
            $this->acrescentarContato(
                fn () => $cliente->emails(),
                'email',
                $email,
                fn (bool $principal) => ['email' => $email, 'principal' => $principal, 'financeiro' => false]
            );
        }

        if ($telefone !== '') {
            $this->acrescentarContato(
                fn () => $cliente->telefones(),
                'telefone',
                $telefone,
                fn (bool $principal) => ['telefone' => $telefone, 'principal' => $principal]
            );
        }
    }

    /**
     * Acrescenta um contato se ele ainda não existir, casando pelo VALOR.
     *
     * Principal só quando o cliente ainda não tem nenhum: se alguém já escolheu
     * um principal na Matriz, o do gym entra como adicional. O sincronizador
     * não desfaz decisão tomada por gente.
     *
     * O primeiro parâmetro é uma FÁBRICA de relação, não a relação: `where()`
     * muta o construtor de consulta, então reusar o mesmo objeto faria a
     * segunda pergunta herdar a condição da primeira — e ela responderia
     * "não existe principal" para todo cliente.
     *
     * @param  callable(): \Illuminate\Database\Eloquent\Relations\HasMany<\Illuminate\Database\Eloquent\Model, Cliente>  $relacao
     * @param  callable(bool): array<string, mixed>  $novo
     */
    private function acrescentarContato(callable $relacao, string $campo, string $valor, callable $novo): void
    {
        if ($relacao()->where($campo, $valor)->exists()) {
            return;
        }

        $ehOPrimeiroPrincipal = ! $relacao()->where('principal', true)->exists();

        $relacao()->create($novo($ehOPrimeiroPrincipal));
    }

    private function revendaPorIdExterno(?string $idExterno): ?Revenda
    {
        if (! $idExterno) {
            return null;
        }

        return Revenda::porOrigemExterna($this->sistema, $idExterno);
    }

    /**
     * Percorre todas as páginas de uma coleção paginada.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function todasPaginas(string $endereco): \Generator
    {
        yield from $this->contrato->paginas($endereco);
    }

    /**
     * Zera o retrato de licença deste sistema nos vínculos.
     *
     * Só mexe em quem tem algo gravado, para não escrever à toa a cada ciclo.
     */
    private function limparRetratoDeLicenca(): int
    {
        return \Illuminate\Support\Facades\DB::table('cliente_sistema')
            ->where('sistema_id', $this->sistema->id)
            ->where(fn ($q) => $q
                ->whereNotNull('licenca_status')
                ->orWhereNotNull('licenca_id_externo')
                ->orWhereNotNull('licenca_fim_em')
                ->orWhereNotNull('licenca_valor'))
            ->update([
                'licenca_status' => null,
                'plano' => null,
                'licenca_valor' => null,
                'licenca_inicio_em' => null,
                'licenca_fim_em' => null,
                'licenca_id_externo' => null,
            ]);
    }

    /**
     * Catálogo de módulos e o que cada cliente contratou.
     *
     * Só para quem declara `sincroniza_modulos`: o AlfaGym não tem módulos, e
     * consultar endereços que ele não serve daria 404 a cada ciclo.
     *
     * @return array<string, mixed>
     */
    private function sincronizarModulos(): array
    {
        if (! $this->sistema->suporta('sincroniza_modulos')) {
            return ['ignorado' => true];
        }

        return [
            'catalogo' => $this->sincronizarCatalogoDeModulos(),
            'contratados' => $this->sincronizarModulosContratados(),
        ];
    }

    /** @return array<string, int> */
    private function sincronizarCatalogoDeModulos(): array
    {
        $vistos = [];
        $gravados = 0;

        foreach ($this->todasPaginas('/modulos') as $item) {
            $codigo = $item['codigo'] ?? null;

            if (! $codigo) {
                continue;
            }

            Modulo::updateOrCreate(
                ['sistema_id' => $this->sistema->id, 'codigo' => $codigo],
                [
                    'nome' => $item['nome'] ?? $codigo,
                    'descricao' => $item['descricao'] ?? null,
                    'ativo' => $item['ativo'] ?? true,
                ]
            );

            $vistos[] = $codigo;
            $gravados++;
        }

        // Módulo que saiu do catálogo da origem vira inativo — nunca apagado:
        // remover levaria junto, em cascata, o histórico de contratações.
        $desativados = Modulo::where('sistema_id', $this->sistema->id)
            ->when($vistos !== [], fn ($q) => $q->whereNotIn('codigo', $vistos))
            ->where('ativo', true)
            ->update(['ativo' => false]);

        return ['gravados' => $gravados, 'desativados' => $desativados];
    }

    /** @return array<string, int> */
    private function sincronizarModulosContratados(): array
    {
        $catalogo = Modulo::where('sistema_id', $this->sistema->id)->get()->keyBy('codigo');
        $vistos = [];
        $gravados = 0;

        foreach ($this->todasPaginas('/modulos-contratados') as $item) {
            $modulo = $catalogo->get($item['modulo_codigo'] ?? null);
            $cliente = Cliente::porOrigemExterna($this->sistema, (string) ($item['cliente_id_externo'] ?? ''));

            // Sem cliente ancorado ou sem módulo no catálogo não há onde
            // pendurar a contratação — mesma postura do sync de licenças.
            if (! $modulo || ! $cliente) {
                continue;
            }

            $contratacao = ClienteModulo::updateOrCreate(
                ['cliente_id' => $cliente->id, 'modulo_id' => $modulo->id],
                [
                    'status' => $item['status'] ?? 'ativo',
                    'data_inicio' => $item['inicio_em'] ?? null,
                    'data_fim' => $item['fim_em'] ?? null,
                    'valor_mensal' => $item['valor_mensal'] ?? null,
                    'observacao' => $item['observacao'] ?? null,
                ]
            );

            if (filled($item['id_externo'] ?? null)) {
                $contratacao->ancorarEm($this->sistema, (string) $item['id_externo']);
            }

            $vistos[] = $contratacao->id;
            $gravados++;
        }

        // Contratação que sumiu da origem foi encerrada lá: registra o fim em
        // vez de apagar, senão o faturamento perde a memória do período.
        $encerrados = ClienteModulo::whereIn('modulo_id', $catalogo->pluck('id'))
            ->when($vistos !== [], fn ($q) => $q->whereNotIn('id', $vistos))
            ->where('status', 'ativo')
            ->update(['status' => 'inativo', 'data_fim' => now()->toDateString()]);

        return ['gravados' => $gravados, 'encerrados' => $encerrados];
    }

    /**
     * Casa um registro que já existe na Matriz pelo documento, para a primeira
     * carga de um sistema novo não duplicar a base.
     *
     * A âncora `origens_externas` é por sistema: a revenda que veio do AlfaGym
     * não é encontrada quando o AlfaControl a envia com outro id externo. O
     * documento é o único identificador estável entre os dois.
     *
     * Três guardas contra casar errado — duplicar é ruim, mas fundir dois
     * clientes distintos é pior e não tem desfazer:
     *  - documento precisa ter 11 (CPF) ou 14 (CNPJ) dígitos;
     *  - o resultado precisa ser único na base;
     *  - quem já está ancorado em OUTRO id externo deste mesmo sistema fica de
     *    fora, senão dois registros de lá colidiriam num só aqui.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function reconciliarPorDocumento($query, string $coluna, ?string $documento): ?object
    {
        if ($documento === null || ! in_array(strlen($documento), [11, 14], true)) {
            return null;
        }

        // Compara NORMALIZADO dos dois lados. O documento que chega já vem só
        // com dígitos, mas o que está gravado aqui pode ter máscara: cadastro
        // feito à mão guarda "52.638.029/0001-05". Comparar cru contra
        // normalizado nunca casa — e o efeito é justamente a duplicação que
        // esta função existe para evitar. Pego no ensaio da primeira carga
        // real, com a revenda que existia nos dois lados.
        $normalizado = "REPLACE(REPLACE(REPLACE(REPLACE({$coluna}, '.', ''), '/', ''), '-', ''), ' ', '')";

        // Os excluídos ENTRAM na busca quando o modelo os guarda: a linha
        // soft-deletada continua ocupando o documento no índice único, e o
        // escopo padrão a escondia daqui — o fluxo seguia para o create() e
        // colidia com ela. Foi o que parou a sincronização em produção.
        if (in_array(SoftDeletes::class, class_uses_recursive($query->getModel()), true)) {
            $query = $query->withTrashed();
        }

        $candidatos = $query->whereRaw("{$normalizado} = ?", [$documento])->limit(2)->get();

        if ($candidatos->count() !== 1) {
            return null;
        }

        $candidato = $candidatos->first();

        // Excluído volta como está, ANTES da guarda de âncora: quem chama
        // precisa saber que o documento está tomado — um null aqui mandaria
        // o fluxo direto para o create() que colide.
        if (method_exists($candidato, 'trashed') && $candidato->trashed()) {
            return $candidato;
        }

        return $candidato->idExternoNoSistema($this->sistema) === null ? $candidato : null;
    }

    private function normalizarDocumento(?string $documento): ?string
    {
        if ($documento === null) {
            return null;
        }

        $soDigitos = preg_replace('/\D/', '', $documento);

        return $soDigitos === '' ? null : $soDigitos;
    }
}
