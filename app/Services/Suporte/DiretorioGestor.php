<?php

namespace App\Services\Suporte;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * O retrato do Gestor que a máquina do Felipe manda a cada 10 min (só leitura):
 * os clientes ativos, com telefones, e os atendimentos recentes. É dele que o
 * Pedro reconhece cliente do Grupo Soluções pelo número ou pelo nome citado.
 */
class DiretorioGestor
{
    /** Palavras que não identificam ninguém ("Condomínio Residencial das …"). */
    private const VAZIAS = ['condominio', 'residencial', 'edificio', 'ed', 'cond', 'ltda', 'me', 'eireli', 'sa', 'de', 'da', 'do', 'das', 'dos', 'e',
        'empresa', 'comercio', 'servicos', 'associacao', 'the', 'parque', 'jardim', 'jardins', 'vila', 'solar', 'residence', 'res'];

    private ?array $retrato = null;

    public function gravar(string $maquina, string $lidoEm, array $clientes, array $atendimentos, array $assuntos = [], array $equipe = [], array $showroom = [], array $visitas = [], array $orcamentos = [], ?array $empresas = null): void
    {
        DB::table('suporte_gestor_retrato')->delete();
        DB::table('suporte_gestor_retrato')->insert(['maquina' => $maquina, 'lido_em' => $lidoEm, 'clientes' => json_encode(array_values($clientes), JSON_UNESCAPED_UNICODE),
            'atendimentos' => json_encode(array_values($atendimentos), JSON_UNESCAPED_UNICODE), 'assuntos' => json_encode(array_values($assuntos), JSON_UNESCAPED_UNICODE),
            'equipe' => json_encode(array_values($equipe), JSON_UNESCAPED_UNICODE),
            'showroom' => json_encode(array_values($showroom), JSON_UNESCAPED_UNICODE),
            'visitas' => json_encode(array_values($visitas), JSON_UNESCAPED_UNICODE),
            'orcamentos' => json_encode(array_values($orcamentos), JSON_UNESCAPED_UNICODE),
            // Null: a máquina ainda não manda as empresas (pedro-orcamento); a prévia sai sem o percentual de NF.
            'empresas' => $empresas === null ? null : json_encode(array_values($empresas), JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now()]);
        $this->retrato = null;
        $this->indice = null;
    }

    /** @return array{lido_em: ?string, clientes: list<array>, atendimentos: list<array>} */
    public function retrato(): array
    {
        if ($this->retrato === null) {
            $r = DB::table('suporte_gestor_retrato')->orderByDesc('id')->first();
            $this->retrato = ['lido_em' => $r?->lido_em, 'clientes' => $r ? (array) json_decode($r->clientes, true) : [], 'atendimentos' => $r ? (array) json_decode($r->atendimentos, true) : [],
                'assuntos' => $r ? (array) json_decode((string) $r->assuntos, true) : [],
                'equipe' => $r ? (array) json_decode((string) ($r->equipe ?? '[]'), true) : [],
                'showroom' => $r ? (array) json_decode((string) ($r->showroom ?? '[]'), true) : [],
                'visitas' => $r ? (array) json_decode((string) ($r->visitas ?? '[]'), true) : [],
                'orcamentos' => $r ? (array) json_decode((string) ($r->orcamentos ?? '[]'), true) : [],
                'empresas' => $r && ($r->empresas ?? null) !== null ? (array) json_decode((string) $r->empresas, true) : null];
        }

        return $this->retrato;
    }

    /**
     * Os itens do Showroom comercial que mais combinam com o que o cliente
     * escreveu (palavras em comum no título, na descrição e no texto, título
     * pesando mais). Mandar a biblioteca inteira custaria ~5 mil tokens por
     * mensagem; assim vai só o que interessa.
     *
     * @return list<array{categoria: string, titulo: string, descricao: string, texto: string, url: string, video: string}>
     */
    public function showroomPara(string $texto, int $quantos = 4): array
    {
        $palavras = $this->termos($texto);
        if (! $palavras) {
            return [];
        }

        return collect($this->retrato()['showroom'] ?? [])
            ->map(function ($item) use ($palavras) {
                $titulo = $this->termos(($item['titulo'] ?? '').' '.($item['categoria'] ?? ''));
                $corpo = $this->termos(($item['descricao'] ?? '').' '.($item['texto'] ?? ''));

                return ['item' => $item, 'pontos' => 3 * count(array_intersect($palavras, $titulo)) + count(array_intersect($palavras, $corpo))];
            })
            ->filter(fn ($x) => $x['pontos'] >= 2)->sortByDesc('pontos')->take($quantos)->pluck('item')->values()->all();
    }

    /** @return list<string> as palavras que dizem algo (sem acento, 4+ letras, sem as genéricas), sem repetição */
    private function termos(string $texto): array
    {
        $genericas = ['para', 'como', 'qual', 'quais', 'quero', 'gostaria', 'saber', 'mais', 'sobre', 'voces', 'vocês', 'tenho', 'preciso', 'esta', 'esse', 'essa',
            'isso', 'aqui', 'onde', 'quando', 'tambem', 'ainda', 'muito', 'pode', 'podem', 'fazer', 'favor', 'obrigado', 'obrigada', 'tudo', 'bom', 'dia', 'tarde', 'noite', 'olá', 'ola'];

        return array_values(array_unique(array_filter(preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($texto))),
            fn ($p) => strlen($p) >= 4 && ! in_array($p, $genericas, true))));
    }

    /**
     * Os clientes que o Pedro atende (T-488, dono em 22/10/2026): os das empresas Invest, GW e
     * DELTA do Gestor. Cliente de outra empresa (a ALFA, cujos sistemas a Alfa atende como
     * N2/N3, e não pelo suporte da Invest) não é reconhecido pelo telefone nem pelo nome.
     * Cliente sem empresa no retrato continua: a lista diz quem fica de fora, não quem entra.
     *
     * @return \Illuminate\Support\Collection<int, array>
     */
    private function atendidos(): \Illuminate\Support\Collection
    {
        $empresas = array_map(fn ($e) => mb_strtoupper(trim((string) $e)), (array) config('services.suporte_pedro.empresas'));

        return collect($this->retrato()['clientes'])->filter(fn ($c) => ! $empresas || trim((string) ($c['empresa'] ?? '')) === ''
            || in_array(mb_strtoupper(trim((string) $c['empresa'])), $empresas, true))->values();
    }

    public function cliente(int $id): ?array
    {
        return collect($this->retrato()['clientes'])->first(fn ($c) => (int) ($c['id'] ?? 0) === $id);
    }

    /**
     * As empresas do grupo com o percentual de NF do orçamento novo (pedro-orcamento). Sem a lista no
     * retrato (máquina antiga), as que aparecem nos clientes, sem percentual.
     *
     * @return array<int, array{id: int, nome: string, imposto_nf: ?float}>
     */
    public function empresas(): array
    {
        $r = $this->retrato();
        $lista = $r['empresas'] ?? null;
        if ($lista === null) {
            $lista = collect($r['clientes'] ?? [])->filter(fn ($c) => ! empty($c['empresa_id']) && ! empty($c['empresa']))
                ->map(fn ($c) => ['id' => (int) $c['empresa_id'], 'nome' => (string) $c['empresa'], 'imposto_nf' => null])->all();
        }

        return collect($lista)->filter(fn ($e) => ! empty($e['id']) && filled($e['nome'] ?? null))
            ->mapWithKeys(fn ($e) => [(int) $e['id'] => ['id' => (int) $e['id'], 'nome' => (string) $e['nome'],
                'imposto_nf' => isset($e['imposto_nf']) && is_numeric($e['imposto_nf']) ? (float) $e['imposto_nf'] : null]])->sortKeys()->all();
    }

    /** Os orçamentos em aberto do cliente (id negativo = pré-cliente). @return list<array> */
    public function orcamentosDe(int $clienteId): array
    {
        return collect($this->retrato()['orcamentos'] ?? [])->filter(fn ($o) => (int) ($o['cliente_id'] ?? 0) === $clienteId)->values()->all();
    }

    /** O atendimento do retrato pelo id do Gestor. */
    public function atendimentoPorId(int $id): ?array
    {
        return collect($this->retrato()['atendimentos'] ?? [])->first(fn ($a) => (int) ($a['id'] ?? 0) === $id);
    }

    /** A visita do retrato pelo id. */
    public function visita(int $id): ?array
    {
        return collect($this->retrato()['visitas'] ?? [])->first(fn ($v) => (int) ($v['id'] ?? 0) === $id);
    }

    /**
     * As pessoas que se identificaram ao Pedro com este número (contatos do Gestor, T-479),
     * dos clientes que o Pedro atende. Separado de porTelefone de propósito: contato não
     * libera pedido sensível, só identifica.
     *
     * @return list<array{cliente_id: int, nome: string, papel: ?string}>
     */
    public function contatoPorChave(string $chave): array
    {
        $achados = [];
        foreach ($this->atendidos() as $cl) {
            foreach ((array) ($cl['contatos'] ?? []) as $p) {
                if (trim((string) ($p['nome'] ?? '')) !== '' && (($p['chave'] ?? null) ?: ContatosDoGestor::chave((string) ($p['telefone'] ?? ''))) === $chave) {
                    $achados[] = ['cliente_id' => (int) $cl['id'], 'nome' => trim((string) $p['nome']), 'papel' => trim((string) ($p['papel'] ?? '')) ?: null];
                }
            }
        }

        return $achados;
    }

    /** @return list<array> */
    public function porTelefone(string $numero): array
    {
        $chave = CanalWhatsApp::chave($numero);

        return $chave ? $this->atendidos()->filter(fn ($c) => collect($c['telefones'] ?? [])->contains(fn ($t) => CanalWhatsApp::chave((string) $t) === $chave))->values()->all() : [];
    }

    /**
     * Clientes cujo nome aparece no texto: todas as palavras que identificam (fora
     * "condomínio", "residencial"…) precisam estar lá. "parque das castanheiras" acha
     * "CONDOMINIO RESIDENCIAL PARQUE DAS CASTANHEIRAS" pela palavra "castanheiras".
     *
     * @return list<array>
     */
    public function porNome(string $texto, int $limite = 3): array
    {
        $palavrasDoTexto = array_flip(self::palavras($texto));
        if (! $palavrasDoTexto) {
            return [];
        }

        return $this->atendidos()
            ->filter(fn ($c) => collect([$c['fantasia'] ?? null, $c['nome'] ?? null, $c['razao'] ?? null])->contains(fn ($n) => self::citado((string) $n, $palavrasDoTexto)))
            ->take($limite)->values()->all();
    }

    /**
     * Busca larga, para quem é da casa (acesso-interno.md): "apartamento da Emanuelly"
     * acha a cliente Emanuelly Souza, mesmo sem o nome inteiro. Vale palavra do nome
     * com 4+ letras citada no texto; mais palavras em comum vêm primeiro. Quem
     * confirma o cliente, antes de registrar, é a pessoa.
     *
     * @return list<array>
     */
    public function buscarClientes(string $texto, int $limite = 6): array
    {
        // Com todas as palavras do pedido: "vila" é palavra vazia para o nome, mas é metade de "Vila Velha".
        $todas = self::palavras($texto);
        $cidadeCitada = fn ($c) => ($cidade = self::palavras((string) ($c['cidade'] ?? ''))) && ! array_diff($cidade, $todas) ? 0.25 : 0;

        return $this->casados($texto)->map(fn ($x) => [$x['cliente'], $x['pontos'] + $cidadeCitada($x['cliente'])])
            ->sortByDesc(fn ($par) => $par[1])->take($limite)->map(fn ($par) => $par[0])->values()->all();
    }

    /**
     * Os clientes e pré-clientes com estes CNPJ ou CPF, para quem é da casa (Felipe, 03/11/2026: o Pedro não
     * achou a POWER-FIT ACADEMIA, e o CNPJ que o Felipe mandou não ajudou, porque a busca da casa era só pelo
     * nome). Só entre os que o Pedro atende, como a busca pelo nome.
     *
     * @param  list<string>  $documentos  só os dígitos
     * @return list<array>
     */
    public function porDocumento(array $documentos): array
    {
        return $documentos ? $this->atendidos()->filter(fn ($c) => in_array(self::digitos($c['documento'] ?? ''), $documentos, true))->values()->all() : [];
    }

    /** O cliente com este documento que é de empresa que o Pedro não atende (a ALFA): é o motivo de ele não achar. */
    public function deOutraEmpresa(string $documento): ?array
    {
        $atendidos = $this->atendidos()->map(fn ($c) => (int) ($c['id'] ?? 0))->all();

        return collect($this->retrato()['clientes'])->first(fn ($c) => self::digitos($c['documento'] ?? '') === $documento && ! in_array((int) ($c['id'] ?? 0), $atendidos, true));
    }

    private static function digitos($v): string
    {
        return (string) preg_replace('/\D/', '', (string) $v);
    }

    /**
     * A busca do cliente NOVO, de número fora do cadastro (Felipe, 02/11/2026: o Pedro não achou o "Duque de
     * Caxias", cadastrado como "CDC COLEGIO DUQUE DE CAXIAS", porque o porNome exige o nome inteiro). É a busca
     * larga da casa, com travas que a da casa não precisa, porque quem escreve é desconhecido e o Pedro não
     * pode pôr na boca dele o nome de um cliente que ele não disse:
     * - sem nome dito por inteiro (o porNome de antes), o parecido só é citado com MAIS da metade do peso das
     *   palavras que o identificam, cada uma pesando pela raridade: "duque de caxias" traz o CDC, e "minha
     *   academia" não traz a "ACADEMIA KORPUS" ("academia" e a cidade do próprio cadastro quase não pesam);
     * - citado um, entra o cadastro que casou com as mesmas palavras e tem nelas ao menos metade do nome:
     *   "fit club" traz a de Vitória e a "ACADEMIA FIT CLUB (CARIACICA)"; o que casou com outras palavras fica
     *   de fora: em "sou a Zuleica do Residencial Atlântico", a cliente "ZULEICA MORAES" é a pessoa, e não o cliente;
     * - no fim, só quem pesa ao menos metade do melhor: "Vila Velha" citado não traz os cadastros "(VILA VELHA)"
     *   ao lado da Revizza, "colégio duque de caxias" não traz um "COLEGIO UP" pela palavra comum, e
     *   "Barão de Mauá" traz o colégio, e não o "CONDOMINIO RESIDENCIAL MAUA", que é inteiro só por "Mauá".
     * - e o melhor precisa ter palavra de até 10 cadastros: "minha academia" não é nome de ninguém.
     * Sobrar mais de um é nome parecido, e quem decide o que fazer (pedir o CNPJ) é o Contexto.
     *
     * @return list<array{cliente: array, inteiro: bool}> inteiro = todas as palavras do nome foram ditas, como no porNome
     */
    public function candidatosPorNome(string $texto): array
    {
        $palavras = self::palavrasDe($texto);
        $casados = $this->casados($texto)->map(fn ($x) => $x + ['inteiro' => collect([$x['cliente']['fantasia'] ?? null, $x['cliente']['nome'] ?? null, $x['cliente']['razao'] ?? null])
            ->contains(fn ($n) => self::citado((string) $n, $palavras))]);
        // Quem a pessoa citou: o nome dito por inteiro, ou, sem nenhum, o parecido com MAIS da metade do nome.
        // Citado um, é nome parecido o cadastro que casou com as mesmas palavras e tem nelas ao menos METADE do
        // nome: com a régua cheia, "academia" deixava de ser rara num retrato pequeno, a "ACADEMIA POWER GYM
        // (CARIACICA)" (metade) ficava de fora ao lado da "POWER GYM", e a de Vitória ia ao Pedro com nome e
        // cidade (bateria de 02/11/2026); sem régua nenhuma, "residencial mauá" trazia o "XPT COLEGIO BARAO
        // DE MAUA", em que "Mauá" é a menor parte. Entre os que ficam, quem decide é o peso, abaixo.
        $inteiros = $casados->where('inteiro', true);
        $citados = $inteiros->isNotEmpty() ? $inteiros : $casados->filter(fn ($x) => $x['cobertura'] > 0.5);
        $ditos = $citados->flatMap(fn ($x) => $x['termos'])->unique()->all();
        $todos = self::semOMesmoNoFunil($casados->filter(fn ($x) => $x['inteiro'] || ($x['cobertura'] >= 0.5 && array_intersect($x['termos'], $ditos) !== [])));
        if ($citados->isEmpty() || $todos->isEmpty()) {
            return [];
        }
        $melhor = $todos->max('pontos');
        // Palavra que mais de 10 cadastros têm não identifica ninguém ("minha academia", 41 "ACADEMIA …" na
        // cópia do Gestor): é como não ter candidato, e o Pedro pede o nome, e não o CNPJ.
        if ($melhor < 0.1) {
            return [];
        }

        return $todos->filter(fn ($x) => $x['pontos'] >= $melhor / 2)->sortByDesc('pontos')
            ->map(fn ($x) => ['cliente' => $x['cliente'], 'inteiro' => $x['inteiro']])->values()->all();
    }

    /**
     * O pré-cliente que é o próprio cliente, ainda no funil, não é outro cadastro (Felipe, 07/11/2026: o inquilino
     * novo de uma sala disse por inteiro o nome do condomínio, igual à razão social do cliente, e o Pedro pediu o
     * CNPJ, porque o mesmo condomínio estava também como pré-cliente, sem CNPJ e nunca convertido; o CNPJ que a
     * pessoa mandou não conferia, e a conversa foi para um colega). No Gestor, 28 dos 141 clientes têm um
     * pré-cliente não convertido de mesmo nome. É o mesmo com o mesmo CNPJ; sem CNPJ no pré-cliente, com o mesmo
     * nome (as palavras que identificam, em qualquer dos três) e sem cidade diferente. CNPJ ou cidade diferentes
     * continuam dois cadastros, e o Pedro pede o CNPJ, como antes. O cliente fica com a marca dele: se só o nome do
     * funil foi dito por inteiro, o do cadastro continua só parecido, e o Pedro pergunta se é ele.
     *
     * @param  \Illuminate\Support\Collection<int, array{cliente: array, inteiro: bool}>  $achados
     * @return \Illuminate\Support\Collection<int, array{cliente: array, inteiro: bool}>
     */
    private static function semOMesmoNoFunil(\Illuminate\Support\Collection $achados): \Illuminate\Support\Collection
    {
        $clientes = $achados->filter(fn ($x) => (int) ($x['cliente']['id'] ?? 0) > 0);

        return $achados->reject(fn ($x) => (int) ($x['cliente']['id'] ?? 0) < 0
            && $clientes->contains(fn ($c) => self::mesmoCadastro($x['cliente'], $c['cliente'])))->values();
    }

    private static function mesmoCadastro(array $pre, array $cliente): bool
    {
        if (($documento = self::digitos($pre['documento'] ?? '')) !== '') {
            return $documento === self::digitos($cliente['documento'] ?? '');
        }
        $cidade = fn (array $c) => implode(' ', self::palavras((string) ($c['cidade'] ?? '')));
        if ($cidade($pre) !== '' && $cidade($cliente) !== '' && $cidade($pre) !== $cidade($cliente)) {
            return false;
        }
        $nomes = fn (array $c) => collect([$c['fantasia'] ?? null, $c['nome'] ?? null, $c['razao'] ?? null])
            ->map(fn ($n) => collect(self::chaveDoNome(self::palavras((string) $n)))->sort()->implode(' '))->filter()->all();

        return array_intersect($nomes($pre), $nomes($cliente)) !== [];
    }

    /** @var list<array{cliente: array, nomes: list<list<string>>, palavras: list<string>, juntas: list<string>}>|null os nomes já quebrados em palavras, uma vez por retrato */
    private ?array $indice = null;

    /** @var array<string, int> em quantos cadastros cada palavra que identifica aparece no nome */
    private array $frequencia = [];

    /** @return list<array{cliente: array, nomes: list<list<string>>, palavras: list<string>, juntas: list<string>}> */
    private function indice(): array
    {
        if ($this->indice === null) {
            $this->indice = $this->atendidos()->map(function ($c) {
                $nomes = collect([$c['fantasia'] ?? null, $c['nome'] ?? null, $c['razao'] ?? null])->filter()->map(fn ($n) => self::palavras((string) $n))->values()->all();
                $quatro = fn (array $ws) => array_values(array_unique(array_filter($ws, fn ($w) => mb_strlen($w) >= 4)));

                return ['cliente' => $c, 'nomes' => $nomes, 'palavras' => $quatro(array_merge(...$nomes)),
                    'juntas' => $quatro(array_merge(...array_map(fn ($p) => array_column(self::juntas($p), 0), $nomes)))];
            })->all();
            $this->frequencia = collect($this->indice)->flatMap(fn ($i) => array_unique(array_merge(...array_map(fn ($p) => self::chaveDoNome($p), $i['nomes']))))->countBy()->all();
        }

        return $this->indice;
    }

    /** @param list<string> $p @return list<string> as palavras que identificam o nome (4+ letras, fora "condomínio", "residencial"…) */
    private static function chaveDoNome(array $p): array
    {
        return array_values(array_unique(array_filter($p, fn ($w) => ! in_array($w, self::VAZIAS, true) && mb_strlen($w) >= 4)));
    }

    /**
     * Os clientes com palavra do nome no texto, com o peso de cada um e quanto do nome foi dito.
     *
     * @return \Illuminate\Support\Collection<int, array{cliente: array, termos: list<string>, pontos: float, cobertura: float}>
     */
    private function casados(string $texto): \Illuminate\Support\Collection
    {
        // As palavras do próprio pedido não são nome de cliente.
        $doPedido = ['abrir', 'abra', 'chamado', 'atendimento', 'registrar', 'registra', 'manutencao', 'limpeza', 'apartamento', 'pedro', 'para', 'favor',
            'cliente', 'servico', 'condensadora', 'condensadoras', 'condicionado', 'urgente', 'visita', 'tecnica', 'tecnico', 'problema', 'preciso', 'pode',
            // Nem as do problema e do cumprimento, que o cliente novo diz em toda conversa (02/11/2026: "o portão
            // está travado" trazia o pré-cliente "Portal…", a uma letra de "portão").
            'portao', 'portoes', 'portaria', 'porteiro', 'camera', 'cameras', 'interfone', 'interfones', 'alarme', 'alarmes', 'cerca', 'cercas', 'catraca', 'catracas', 'motor', 'motores', 'garagem',
            // 06/11/2026: "as catracas... o visor 'Apresente o cartão'... não libera a entrada" trouxe três cadastros de nome
            // parecido com "catraca", "cartão" e "libera", e o Pedro pediu o CNPJ a um colégio por causa deles.
            'problemas', 'cartao', 'cartoes', 'visor', 'mensagem', 'apresente', 'libera', 'liberam', 'liberar', 'liberando', 'entrada', 'passar',
            // E o registro da escolha no menu do Gestor (CanalWhatsApp::ESCOLHEU_SUPORTE_NO_MENU).
            'escolheu', 'suporte', 'menu',
            // "Bom dia as catracas…": a junção "diaas" soa como "dias", e trazia um cadastro com "Dias" no nome.
            'diaas',
            'travado', 'travada', 'travou', 'quebrado', 'quebrada', 'quebrou', 'parado', 'parada', 'parou', 'funciona', 'funcionando',
            'abre', 'abrindo', 'fecha', 'fechando', 'desligado', 'desligada', 'apagou', 'apagada', 'apagado', 'imagem', 'defeito', 'conserto',
            'controle', 'acesso', 'instalar', 'instalacao', 'orcamento', 'financeiro', 'boleto', 'pagamento', 'sindico', 'sindica', 'predio', 'casa', 'nome',
            // E as da conversa, como no termos() do showroom: "quero" trazia o "SO QUERO 10 CONCURSOS", e "falar", a "FALLER".
            'quero', 'queria', 'gostaria', 'falar', 'saber', 'sobre', 'minha', 'minhas', 'meus', 'nossa', 'nosso', 'aqui', 'esta', 'estou', 'esse', 'essa',
            'isso', 'onde', 'quando', 'tambem', 'ainda', 'muito', 'podem', 'fazer', 'tudo', 'voces', 'tenho', 'temos', 'agora', 'hoje', 'ontem', 'amanha',
            'ajuda', 'ajudar', 'tarde', 'noite', 'obrigado', 'obrigada'];
        $palavras = self::palavras($texto);
        $juntasDoTexto = array_column(self::juntas($palavras), 0);
        $doTexto = array_values(array_map('strval', array_keys(array_flip(array_filter([...$palavras, ...$juntasDoTexto],
            fn ($p) => mb_strlen($p) >= 4 && ! in_array($p, self::VAZIAS, true) && ! in_array($p, $doPedido, true))))));
        if (! $doTexto) {
            return collect();
        }
        // Junção só vale com o mesmo som, sem letra de diferença: com a diferença, "academia esta" virava a
        // "ACADEMIA VITA", "minha academia" a "PENHA ACADEMIA" e "a câmera" a "ACQUA SERRA" (02/11/2026, na
        // cópia do Gestor) — a palavra longa em comum fazia a junção parecer quase igual.
        $junta = array_flip(array_diff($juntasDoTexto, $palavras));

        // Cada palavra pesa pela raridade (23/10/2026: em "revissa em vila velha", os clientes com
        // "(VILA VELHA)" no nome tomaram as 6 vagas e a Revizza de Vila Velha ficou de fora): a palavra
        // que só dois cadastros têm decide; a que dezenas têm quase não conta. A cidade citada desempata.
        // Cada palavra de nome é comparada ao texto uma vez só, e não uma vez por cliente que a tem
        // (02/11/2026: com 4 mil cadastros, a busca levava ~1 s, e o cliente novo a faz a cada mensagem).
        $bate = [];
        $termosDe = function (string $w, bool $daJunta = false) use (&$bate, $doTexto, $junta): array {
            return $bate[($daJunta ? '+' : '').$w] ??= array_values(array_filter($doTexto, fn ($t) => self::parecida($w, $t, $daJunta || isset($junta[$t]))));
        };
        $casados = [];
        foreach ($this->indice() as $i) {
            $termos = array_values(array_unique(array_merge(...array_map(fn ($w) => $termosDe($w), $i['palavras']), ...array_map(fn ($w) => $termosDe($w, true), $i['juntas']))));
            if ($termos === []) {
                continue;
            }
            // Quanto do nome foi dito, no melhor dos três (fantasia, nome, razão): a palavra que identifica
            // vale se a pessoa a disse, ou disse ela junto da vizinha ("Marazul"), e pesa pela raridade, como
            // na pontuação: "academia" quase não conta, e o nome raro decide. A cidade do próprio cadastro
            // no nome não conta: "ACADEMIA FIT CLUB (CARIACICA)" é a Fit Club, e quem diz "fit club" disse o nome.
            $cidade = self::palavras((string) ($i['cliente']['cidade'] ?? ''));
            $cobertura = 0.0;
            foreach ($i['nomes'] as $p) {
                $chave = self::chaveDoNome($p);
                $chave = array_values(array_diff($chave, $cidade)) ?: $chave;
                if ($chave === []) {
                    continue;
                }
                $ditas = array_filter($chave, fn ($w) => $termosDe($w) !== []);
                foreach (self::juntas($p) as [$juntas, $partes]) {
                    if (mb_strlen($juntas) >= 4 && $termosDe($juntas, true) !== []) {
                        $ditas = [...$ditas, ...$partes];
                    }
                }
                $peso = fn (array $ws) => array_sum(array_map(fn ($w) => 1 / max(1, $this->frequencia[$w] ?? 1), $ws));
                $cobertura = max($cobertura, $peso(array_intersect($chave, $ditas)) / $peso($chave));
            }
            $casados[] = ['cliente' => $i['cliente'], 'termos' => $termos, 'cobertura' => $cobertura];
        }
        $casados = collect($casados);
        $quantos = $casados->flatMap(fn ($x) => $x['termos'])->countBy();

        return $casados->map(fn ($x) => ['cliente' => $x['cliente'], 'termos' => $x['termos'], 'pontos' => (float) collect($x['termos'])->sum(fn ($t) => 1 / $quantos[$t]), 'cobertura' => $x['cobertura']]);
    }

    /**
     * Duas palavras seguidas juntas também valem, nos dois sentidos (02/11/2026: o Whisper ouviu "Mar Azul"
     * e escreveu "Marazul", e o pedido por áudio ficou sem cliente). Só palavras de nome: "vila velha" junto
     * daria peso a todo cadastro "(VILA VELHA)" (teste da Revizza, 23/10).
     *
     * @param  list<string>  $p
     * @return list<array{0: string, 1: array{0: string, 1: string}}> a junção e as duas palavras
     */
    private static function juntas(array $p): array
    {
        $juntas = [];
        for ($i = 0; $i < count($p) - 1; $i++) {
            if (! in_array($p[$i], self::VAZIAS, true) && ! in_array($p[$i + 1], self::VAZIAS, true)) {
                $juntas[] = [$p[$i].$p[$i + 1], [$p[$i], $p[$i + 1]]];
            }
        }

        return $juntas;
    }

    /** Grafia aproximada também vale: "Emanuelly" acha "Emanuelle" (21/10/2026, teste do Wallas). Junção, só pelo som. */
    private static function parecida(string $a, string $b, bool $junta = false): bool
    {
        if ($a === $b) {
            return true;
        }
        [$a, $b] = [self::som($a), self::som($b)];
        $menor = min(strlen($a), strlen($b));

        return $a === $b || (! $junta && $menor >= 5 && levenshtein($a, $b) <= ($menor >= 8 ? 2 : 1));
    }

    /** @var array<string, string> o som de cada palavra já vista: a busca compara milhares de nomes a cada mensagem */
    private static array $sons = [];

    /** Pela pronúncia (22/10/2026: "revissa" é a "Revizza"): ss/zz/z viram s, y vira i, letra dobrada vira uma. */
    private static function som(string $p): string
    {
        if (count(self::$sons) > 50000) {
            self::$sons = [];
        }

        return self::$sons[$p] ??= (string) preg_replace('/(.)\1+/', '$1', strtr(str_replace(['ph', 'qu', 'ch'], ['f', 'c', 'x'], $p), ['z' => 's', 'y' => 'i', 'k' => 'c', 'w' => 'v']));
    }

    /** O nome aparece no texto? Todas as palavras que identificam o nome precisam estar nele. @param array<string, int> $palavrasDoTexto */
    public static function citado(string $nome, array $palavrasDoTexto): bool
    {
        $chave = array_values(array_filter(self::palavras($nome), fn ($p) => ! in_array($p, self::VAZIAS, true) && mb_strlen($p) >= 4));

        return $chave !== [] && ! array_diff($chave, array_keys($palavrasDoTexto));
    }

    /** @return array<string, int> */
    public static function palavrasDe(string $texto): array
    {
        return array_flip(self::palavras($texto));
    }

    /**
     * Os assuntos que servem para abrir atendimento deste cliente: os de serviço da
     * empresa dele (e os sem empresa), com os que ele mais usa primeiro.
     *
     * @return list<array{id: int, nome: string, categoria: string}>
     */
    public function assuntosPara(array $cliente): array
    {
        $uso = collect($this->retrato()['atendimentos'])->filter(fn ($a) => (int) ($a['cliente_id'] ?? 0) === (int) $cliente['id'])->countBy('assunto_id');
        $empresa = $cliente['empresa_id'] ?? null;

        return collect($this->retrato()['assuntos'] ?? [])
            ->filter(fn ($a) => mb_strtoupper((string) ($a['tipo'] ?? '')) === 'SERVICO' && ($empresa === null || ($a['empresa_id'] ?? null) === null || (int) $a['empresa_id'] === (int) $empresa))
            ->sortByDesc(fn ($a) => $uso[$a['id']] ?? 0)->values()
            ->map(fn ($a) => ['id' => (int) $a['id'], 'nome' => (string) $a['nome'], 'categoria' => (string) ($a['categoria'] ?? '')])->all();
    }

    /**
     * Os e-mails de login do portal do cliente, só se o número que escreve é um
     * telefone cadastrado DELE no Gestor. @return list<string>
     */
    public function loginsDoPortal(int $clienteId, string $telefone): array
    {
        $deste = collect($this->porTelefone($telefone))->contains(fn ($c) => (int) ($c['id'] ?? 0) === $clienteId);

        return $deste ? array_values(array_filter(array_map('strval', (array) ($this->cliente($clienteId)['portal'] ?? [])))) : [];
    }

    /** O atendimento do Gestor pelo número, do retrato. */
    public function atendimentoPorNumero(string $numero): ?array
    {
        return collect($this->retrato()['atendimentos'])->first(fn ($a) => (string) ($a['numero'] ?? '') === trim($numero));
    }

    /**
     * Quem da equipe (funcionário ativo do Gestor) escreve deste número, com o e-mail do usuário
     * dele no Gestor, quando o funcionário tem um (e só um) usuário ativo. Dois funcionários com o
     * mesmo número não são ninguém: escolher um seria adivinhar.
     *
     * @return array{id: int, nome: string, usuario: ?string}|null
     */
    public function daEquipe(string $numero): ?array
    {
        $chave = CanalWhatsApp::chave($numero);
        if (! $chave) {
            return null;
        }
        $deles = collect($this->retrato()['equipe'] ?? [])->filter(fn ($p) => collect($p['telefones'] ?? [])->contains(fn ($t) => CanalWhatsApp::chave((string) $t) === $chave))->values();
        if ($deles->count() !== 1) {
            return null;
        }
        $f = $deles->first();
        $usuario = mb_strtolower(trim((string) ($f['usuario'] ?? '')));

        return ['id' => (int) $f['id'], 'nome' => (string) $f['nome'], 'usuario' => filter_var($usuario, FILTER_VALIDATE_EMAIL) !== false ? $usuario : null];
    }

    /** @return list<array> os atendimentos ABERTOS em nome do funcionário (responsável ou técnico), mais antigos primeiro */
    public function abertosDoFuncionario(int $funcionarioId): array
    {
        return collect($this->retrato()['atendimentos'])
            ->filter(fn ($a) => in_array($funcionarioId, (array) ($a['funcionarios'] ?? []), true) && ! in_array($a['status'] ?? '', ['concluido', 'cancelado'], true))
            ->sortBy(fn ($a) => (string) ($a['criado_em'] ?? ''))->values()->all();
    }

    /** @return list<array> os atendimentos do cliente, mais recentes primeiro */
    public function atendimentosDe(int $clienteId, int $limite = 6): array
    {
        return collect($this->retrato()['atendimentos'])->filter(fn ($a) => (int) ($a['cliente_id'] ?? 0) === $clienteId)
            ->sortByDesc(fn ($a) => (string) ($a['criado_em'] ?? ''))->take($limite)->values()->all();
    }

    /** @return list<string> */
    private static function palavras(string $texto): array
    {
        return array_values(array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower(Str::ascii($texto))) ?: []));
    }
}
