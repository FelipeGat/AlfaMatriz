<?php

namespace App\Services\Suporte;

use App\Models\Documento;
use App\Models\DocumentoPasta;
use App\Models\SuporteConversa;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A base "Contexto do Suporte" (docs/specs/suporte-pedro-whatsapp/contexto-do-suporte.md,
 * T-476): notas em Markdown que a equipe escreve no OneDrive, no Obsidian, e que
 * chegam ao Matriz pelo espelho de Documentos. O Pedro lê a CÓPIA espelhada,
 * nunca o OneDrive: com a máquina desligada ele segue com a última leitura.
 *
 * Vai ao pedido só um recorte, com teto: o mapa, a pasta do cliente CONFIRMADO
 * na conversa (nunca a de outro: FR-C3) e as notas ligadas a ela ou que
 * combinam com o que ele escreveu. Cada trecho leva o caminho do arquivo,
 * para a resposta poder dizer de onde veio (FR-C5).
 */
class ContextoDoSuporte
{
    /** O ajuste da tela Suporte › Pedro com o id da pasta espelhada que é a base. */
    public const AJUSTE = 'base_pasta';

    public const MAPA = 'contexto do suporte.md';

    public const EMPRESA = '01 - Empresa/';

    public const PROCEDIMENTOS = '02 - Procedimentos/';

    public const CLIENTES = '03 - Clientes/';

    public const CONHECIMENTO = '04 - Conhecimento/';

    /** Teto do recorte inteiro, em caracteres (~3 mil tokens): a base cresce, o pedido não. */
    public const TETO = 12000;

    /** Teto de cada nota, pelo papel dela no recorte. */
    public const TETOS = ['mapa' => 2500, 'cliente' => 5000, 'aberto' => 3000, 'encerrado' => 700, 'nota' => 3000];

    /** Situações em que o atendimento já não está em curso (a nota diz qual). */
    public const ENCERRADAS = ['resolvido', 'encerrado sem confirmação', 'encerrado sem confirmacao', 'cancelado'];

    /** Arquivo maior que isso não é nota: fica de fora. */
    private const MAX_BYTES = 256 * 1024;

    /** @var array<int, string> leitura por documento, dentro de um mesmo pedido */
    private array $lidos = [];

    /** @var list<string>|null nomes e e-mails de quem pode validar (editores do Pedro e administradores) */
    private ?array $validadores = null;

    public function pasta(): ?DocumentoPasta
    {
        $id = (int) app(Conhecimento::class)->ajuste(self::AJUSTE);

        return $id ? DocumentoPasta::find($id) : null;
    }

    /**
     * A seção do prompt, ou null sem base escolhida (o Pedro segue como antes).
     *
     * @param  string  $recentes  as últimas mensagens do cliente, para achar os procedimentos que combinam
     */
    public function paraOPrompt(SuporteConversa $c, string $recentes): ?string
    {
        $pasta = $this->pasta();
        if (! $pasta) {
            return null;
        }
        $notas = $this->notas($pasta);
        if ($notas->isEmpty()) {
            return null;
        }
        $blocos = [];
        $usados = [];
        $incluir = function (Documento $d, string $papel, ?string $texto = null) use (&$blocos, &$usados) {
            if (isset($usados[$d->caminho])) {
                return;
            }
            $usados[$d->caminho] = true;
            $blocos[] = $this->bloco($d, $texto ?? $this->ler($d), self::TETOS[$papel]);
        };

        if ($mapa = $notas->firstWhere('caminho', self::MAPA)) {
            $incluir($mapa, 'mapa');
        }

        // O cliente: só o confirmado na conversa, pelo código no nome da pasta dele.
        $ligadas = [];
        $doCliente = $this->pastaDoCliente($notas, $c);
        if ($doCliente) {
            if ($contexto = $notas->firstWhere('caminho', $doCliente.'/contexto.md')) {
                $incluir($contexto, 'cliente');
                $ligadas = array_merge($ligadas, self::links($this->ler($contexto)));
            }
            $atendimentos = $notas->filter(fn (Documento $d) => str_starts_with($d->caminho, $doCliente.'/atendimentos/'))
                ->sortByDesc('nome')->values();
            [$abertos, $encerrados] = $atendimentos->partition(fn (Documento $d) => ! in_array(
                mb_strtolower(trim((string) (self::nota($this->ler($d))['meta']['situacao'] ?? ''))), self::ENCERRADAS, true));
            foreach ($abertos->take(3) as $d) {
                $incluir($d, 'aberto');
                $ligadas = array_merge($ligadas, self::links($this->ler($d)));
            }
            // Dos encerrados, só o cabeçalho e a solução: é o "o que já resolveu" que importa.
            foreach ($encerrados->take(3) as $d) {
                $incluir($d, 'encerrado', self::resumoDeEncerrado($this->ler($d)));
                $ligadas = array_merge($ligadas, self::links($this->ler($d)));
            }
        }

        // As notas gerais: as ligadas ao cliente primeiro, depois as que combinam com a conversa.
        $gerais = $notas->filter(fn (Documento $d) => self::geral($d->caminho));
        $porNome = $gerais->keyBy(fn (Documento $d) => mb_strtolower(Str::beforeLast($d->nome, '.')));
        foreach (array_unique(array_map('mb_strtolower', $ligadas)) as $nome) {
            if ($d = $porNome->get($nome)) {
                $incluir($d, 'nota');
            }
        }
        foreach ($this->queCombinam($gerais, $recentes) as $d) {
            $incluir($d, 'nota');
        }

        // Até o teto, na ordem de importância; o que não coube é dito, para o Pedro não achar que não existe.
        $saida = [];
        $total = 0;
        $fora = 0;
        foreach ($blocos as $b) {
            if ($total + mb_strlen($b) > self::TETO) {
                $fora++;

                continue;
            }
            $saida[] = $b;
            $total += mb_strlen($b);
        }
        if (! $saida) {
            return null;
        }

        return "## CONTEXTO DO SUPORTE (base de conhecimento da equipe; cada nota com o arquivo de onde veio)\n"
            .($doCliente ? '' : "- Cliente não confirmado: nenhuma pasta de cliente foi incluída.\n")
            .implode("\n\n", $saida)
            .($fora ? "\n\n- ({$fora} nota(s) relacionada(s) não couberam neste pedido.)" : '');
    }

    /**
     * Os caminhos que o Pedro diz ter usado e que existem de fato na base: o resto
     * (inventado ou de outra pasta) cai fora antes de ser gravado na mensagem.
     *
     * @param  list<string>  $fontes
     * @return list<string>
     */
    public function fontesValidas(array $fontes): array
    {
        $pasta = $this->pasta();
        if (! $pasta || ! $fontes) {
            return [];
        }
        $existentes = $pasta->documentos()->whereIn('caminho', $fontes)->where('extensao', 'md')->pluck('caminho')->all();

        return array_values(array_filter(array_unique($fontes), fn ($f) => in_array($f, $existentes, true)));
    }

    /**
     * O cabeçalho (Propriedades do Obsidian) e o corpo. Lê só o que a base usa —
     * `chave: valor`, listas `[a, b]` e `- item` —, sem depender de biblioteca:
     * nota malformada vira só corpo, nunca erro no pedido.
     *
     * @return array{meta: array<string, string|list<string>>, corpo: string}
     */
    public static function nota(string $texto): array
    {
        $texto = str_replace("\r\n", "\n", ltrim($texto, "\u{FEFF}"));
        if (! preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $texto, $m)) {
            return ['meta' => [], 'corpo' => $texto];
        }
        $meta = [];
        $chave = null;
        foreach (explode("\n", $m[1]) as $linha) {
            if (preg_match('/^([A-Za-z_][\w-]*):\s*(.*)$/u', $linha, $kv)) {
                $chave = $kv[1];
                $valor = self::semComentario($kv[2]);
                if (preg_match('/^\[(.*)\]$/', $valor, $lista)) {
                    $meta[$chave] = array_values(array_filter(array_map(fn ($v) => self::semAspas(trim($v)), self::separar($lista[1])), fn ($v) => $v !== ''));
                } else {
                    $meta[$chave] = $valor === '' ? [] : self::semAspas($valor);
                }
            } elseif ($chave && preg_match('/^\s+-\s+(.*)$/u', $linha, $item)) {
                $meta[$chave] = array_merge(is_array($meta[$chave]) ? $meta[$chave] : [], [self::semAspas(self::semComentario($item[1]))]);
            }
        }

        return ['meta' => $meta, 'corpo' => $m[2]];
    }

    /** @return list<string> os destinos dos links `[[Nota]]`, `[[Nota|rótulo]]` e `[[Nota#seção]]` */
    public static function links(string $texto): array
    {
        preg_match_all('/\[\[([^\]\|#]+)(?:#[^\]\|]*)?(?:\|[^\]]*)?\]\]/u', $texto, $m);

        return array_values(array_unique(array_map(fn ($l) => trim(Str::afterLast(trim($l), '/')), $m[1])));
    }

    /** As notas .md da pasta, sem os rascunhos de modelo (`_modelos/`, `_alguma coisa.md`). */
    private function notas(DocumentoPasta $pasta): Collection
    {
        return $pasta->documentos()->where('extensao', 'md')->where('tamanho', '<=', self::MAX_BYTES)
            ->get(['id', 'pasta_id', 'caminho', 'nome', 'arquivo', 'modificado_em'])
            ->reject(fn (Documento $d) => collect(explode('/', $d->caminho))->contains(fn ($p) => str_starts_with($p, '_')))
            ->values();
    }

    /**
     * A pasta do cliente da conversa: `03 - Clientes/<nome> · gestor-13` (ou
     * `matriz-5`; um cliente das duas casas leva os dois códigos no nome).
     */
    private function pastaDoCliente(Collection $notas, SuporteConversa $c): ?string
    {
        $codigos = array_filter([$c->gestor_cliente_id ? 'gestor-'.$c->gestor_cliente_id : null, $c->cliente_id ? 'matriz-'.$c->cliente_id : null]);
        if (! $codigos) {
            return null;
        }
        foreach ($notas as $d) {
            if (! str_starts_with($d->caminho, self::CLIENTES)) {
                continue;
            }
            $pasta = explode('/', substr($d->caminho, strlen(self::CLIENTES)))[0];
            preg_match_all('/·\s*((?:gestor|matriz)-\d+)/u', $pasta, $m);
            if (array_intersect($codigos, $m[1])) {
                return self::CLIENTES.$pasta;
            }
        }

        return null;
    }

    private static function geral(string $caminho): bool
    {
        return str_starts_with($caminho, self::PROCEDIMENTOS) || str_starts_with($caminho, self::CONHECIMENTO) || str_starts_with($caminho, self::EMPRESA);
    }

    /**
     * Até 3 notas gerais que combinam com o que o cliente escreveu, pelo título e
     * pelo "quando usar" — a mesma régua do Showroom (`DiretorioGestor::showroomPara`).
     */
    private function queCombinam(Collection $gerais, string $recentes): Collection
    {
        $palavras = self::termos($recentes);
        if (! $palavras) {
            return collect();
        }

        return $gerais->map(function (Documento $d) use ($palavras) {
            $meta = self::nota($this->ler($d))['meta'];
            $titulo = self::termos(Str::beforeLast($d->nome, '.').' '.self::texto($meta['titulo'] ?? ''));
            $quando = self::termos(self::texto($meta['quando_usar'] ?? ''));

            return ['doc' => $d, 'pontos' => 3 * count(array_intersect($palavras, $titulo)) + count(array_intersect($palavras, $quando))];
        })->filter(fn ($x) => $x['pontos'] >= 2)->sortByDesc('pontos')->take(3)->pluck('doc');
    }

    /** O trecho de uma nota no prompt: arquivo, tipo, validação e o texto, cortado no teto. */
    private function bloco(Documento $d, string $texto, int $teto): string
    {
        ['meta' => $meta, 'corpo' => $corpo] = self::nota($texto);
        $tipo = self::texto($meta['tipo'] ?? '') ?: 'nota';
        $etiqueta = '';
        if (self::geral($d->caminho)) {
            $etiqueta = $this->validada($meta)
                ? ' · VALIDADO por '.self::texto($meta['validado_por'])
                : ' · RASCUNHO (não validado: cite só como possibilidade a confirmar, nunca como instrução)';
        }
        $cabeca = collect(['situacao', 'atualizado', 'responsavel', 'data'])->filter(fn ($k) => self::texto($meta[$k] ?? '') !== '')
            ->map(fn ($k) => $k.': '.self::texto($meta[$k]))->implode(' · ');

        return "### [arquivo: {$d->caminho}] ({$tipo}{$etiqueta})".($cabeca !== '' ? "\n{$cabeca}" : '')."\n"
            .Str::limit(trim($corpo), $teto, ' …(nota cortada)');
    }

    /**
     * Validado de verdade: marca `validado` e quem validou é editor do Pedro ou
     * administrador (decisão do dono em 21/10/2026). Quem mexe no OneDrive pode
     * escrever qualquer nome; por isso o nome tem de ser de uma dessas pessoas.
     */
    private function validada(array $meta): bool
    {
        if (mb_strtolower(self::texto($meta['validacao'] ?? '')) !== 'validado') {
            return false;
        }
        $quem = mb_strtolower(trim(self::texto($meta['validado_por'] ?? '')));
        if ($quem === '') {
            return false;
        }
        $this->validadores ??= User::where('ativo', true)
            ->where(fn ($q) => $q->whereIn('email', (array) config('services.suporte_pedro.editores'))->orWhereHas('perfis', fn ($p) => $p->where('slug', 'admin')))
            ->get(['name', 'email'])->flatMap(fn ($u) => [mb_strtolower($u->name), mb_strtolower($u->email)])->all();

        return in_array($quem, $this->validadores, true);
    }

    /** Do atendimento encerrado: o cabeçalho, o título e a seção da solução. */
    private static function resumoDeEncerrado(string $texto): string
    {
        $n = self::nota($texto);
        $titulo = preg_match('/^#\s+(.+)$/mu', $n['corpo'], $t) ? $t[0] : '';
        $solucao = preg_match('/^##\s+Solução[^\n]*\n(.*?)(?=^##\s|\z)/msu', $n['corpo'], $s) ? "## Solução e resultado\n".trim($s[1]) : '(sem a seção "Solução e resultado")';
        $frente = preg_match('/\A---\n.*?\n---\n?/s', str_replace("\r\n", "\n", $texto), $f) ? $f[0] : '';

        return $frente.trim($titulo."\n".$solucao);
    }

    private function ler(Documento $d): string
    {
        if (! isset($this->lidos[$d->id])) {
            try {
                $this->lidos[$d->id] = (string) Storage::disk(Documento::DISCO)->get($d->arquivo);
            } catch (\Throwable) {
                $this->lidos[$d->id] = '';
            }
        }

        return $this->lidos[$d->id];
    }

    /** @return list<string> */
    private static function termos(string $texto): array
    {
        $genericas = ['para', 'como', 'qual', 'quais', 'quero', 'gostaria', 'saber', 'mais', 'sobre', 'voces', 'tenho', 'preciso', 'esta', 'esse', 'essa',
            'isso', 'aqui', 'onde', 'quando', 'tambem', 'ainda', 'muito', 'pode', 'podem', 'fazer', 'favor', 'obrigado', 'obrigada', 'tudo', 'nao', 'cliente'];

        return array_values(array_unique(array_filter(preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($texto))),
            fn ($p) => strlen($p) >= 4 && ! in_array($p, $genericas, true))));
    }

    private static function texto(mixed $v): string
    {
        return is_array($v) ? implode(', ', $v) : (string) $v;
    }

    private static function semAspas(string $v): string
    {
        return preg_match('/^(["\'])(.*)\1$/s', $v, $m) ? $m[2] : $v;
    }

    private static function semComentario(string $v): string
    {
        // `# comentário` fora de aspas, como no YAML.
        return trim(preg_match('/^(["\']).*?\1/', trim($v)) ? trim($v) : preg_replace('/\s+#.*$/', '', $v));
    }

    /** @return list<string> os itens de `a, "b, c", d` */
    private static function separar(string $lista): array
    {
        preg_match_all('/\s*("[^"]*"|\'[^\']*\'|[^,]+)\s*(?:,|$)/', $lista, $m);

        return $m[1];
    }
}
