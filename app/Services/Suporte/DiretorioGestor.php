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

    public function gravar(string $maquina, string $lidoEm, array $clientes, array $atendimentos, array $assuntos = [], array $equipe = [], array $showroom = [], array $visitas = []): void
    {
        DB::table('suporte_gestor_retrato')->delete();
        DB::table('suporte_gestor_retrato')->insert(['maquina' => $maquina, 'lido_em' => $lidoEm, 'clientes' => json_encode(array_values($clientes), JSON_UNESCAPED_UNICODE),
            'atendimentos' => json_encode(array_values($atendimentos), JSON_UNESCAPED_UNICODE), 'assuntos' => json_encode(array_values($assuntos), JSON_UNESCAPED_UNICODE),
            'equipe' => json_encode(array_values($equipe), JSON_UNESCAPED_UNICODE),
            'showroom' => json_encode(array_values($showroom), JSON_UNESCAPED_UNICODE),
            'visitas' => json_encode(array_values($visitas), JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now()]);
        $this->retrato = null;
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
                'visitas' => $r ? (array) json_decode((string) ($r->visitas ?? '[]'), true) : []];
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
        // As palavras do próprio pedido não são nome de cliente.
        $doPedido = ['abrir', 'abra', 'chamado', 'atendimento', 'registrar', 'registra', 'manutencao', 'limpeza', 'apartamento', 'pedro', 'para', 'favor',
            'cliente', 'servico', 'condensadora', 'condensadoras', 'condicionado', 'urgente', 'visita', 'tecnica', 'tecnico', 'problema', 'preciso', 'pode'];
        $doTexto = array_flip(array_filter(self::palavras($texto), fn ($p) => mb_strlen($p) >= 4 && ! in_array($p, self::VAZIAS, true) && ! in_array($p, $doPedido, true)));
        if (! $doTexto) {
            return [];
        }

        // Grafia aproximada também vale: "Emanuelly" acha "Emanuelle" (21/10/2026, teste do Wallas).
        $parecida = function (string $a, string $b): bool {
            if ($a === $b) {
                return true;
            }
            // Pela pronúncia (22/10/2026: "revissa" é a "Revizza"): ss/zz/z viram s, y vira i, letra dobrada vira uma.
            $som = fn (string $p) => preg_replace('/(.)\1+/', '$1', strtr(str_replace(['ph', 'qu', 'ch'], ['f', 'c', 'x'], $p), ['z' => 's', 'y' => 'i', 'k' => 'c', 'w' => 'v']));
            [$a, $b] = [$som($a), $som($b)];
            $menor = min(strlen($a), strlen($b));

            return $a === $b || ($menor >= 5 && levenshtein($a, $b) <= ($menor >= 8 ? 2 : 1));
        };

        // Cada palavra pesa pela raridade (23/10/2026: em "revissa em vila velha", os clientes com
        // "(VILA VELHA)" no nome tomaram as 6 vagas e a Revizza de Vila Velha ficou de fora): a palavra
        // que só dois cadastros têm decide; a que dezenas têm quase não conta. A cidade citada desempata.
        $casados = $this->atendidos()->map(function ($c) use ($doTexto, $parecida) {
            $nome = array_unique(self::palavras(implode(' ', array_filter([$c['fantasia'] ?? null, $c['nome'] ?? null, $c['razao'] ?? null]))));
            $termos = array_values(array_filter(array_map('strval', array_keys($doTexto)),
                fn ($t) => collect($nome)->contains(fn ($p) => mb_strlen($p) >= 4 && $parecida($p, $t))));

            return [$c, $termos];
        })->filter(fn ($par) => $par[1] !== []);
        $quantos = $casados->flatMap(fn ($par) => $par[1])->countBy();
        // Com todas as palavras do pedido: "vila" é palavra vazia para o nome, mas é metade de "Vila Velha".
        $todas = self::palavras($texto);
        $cidadeCitada = fn ($c) => ($cidade = self::palavras((string) ($c['cidade'] ?? ''))) && ! array_diff($cidade, $todas) ? 0.25 : 0;

        return $casados->map(fn ($par) => [$par[0], collect($par[1])->sum(fn ($t) => 1 / $quantos[$t]) + $cidadeCitada($par[0])])
            ->sortByDesc(fn ($par) => $par[1])->take($limite)->map(fn ($par) => $par[0])->values()->all();
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
     * Quem da equipe (funcionário ativo do Gestor) escreve deste número.
     *
     * @return array{id: int, nome: string}|null
     */
    public function daEquipe(string $numero): ?array
    {
        $chave = CanalWhatsApp::chave($numero);
        if (! $chave) {
            return null;
        }
        $f = collect($this->retrato()['equipe'] ?? [])->first(fn ($p) => collect($p['telefones'] ?? [])->contains(fn ($t) => CanalWhatsApp::chave((string) $t) === $chave));

        return $f ? ['id' => (int) $f['id'], 'nome' => (string) $f['nome']] : null;
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
