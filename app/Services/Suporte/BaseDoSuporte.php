<?php

namespace App\Services\Suporte;

use App\Models\SuporteConversa;
use App\Models\SuporteMensagem;
use App\Models\SuporteNota;
use App\Models\SuportePedido;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A base do suporte (T-476; docs/specs/suporte-pedro-whatsapp/base-do-suporte.md).
 * Mora no Matriz e cresce com os atendimentos: o Pedro propõe o registro, quem
 * atendeu confirma e ele vale para a próxima conversa na hora. Procedimento,
 * conhecimento e empresa só valem como instrução depois do "Validar".
 *
 * Ao Pedro vai só um recorte com teto (a base cresce, o pedido não): a empresa,
 * a memória do cliente CONFIRMADO na conversa (nunca a de outro), os atendimentos
 * dele e até três notas gerais que combinam com o que ele escreveu. Cada trecho
 * leva o número da nota, para a resposta dizer de onde veio.
 */
class BaseDoSuporte
{
    /** Teto do recorte inteiro, em caracteres (~3 mil tokens). */
    public const TETO = 12000;

    /** Teto de cada nota, pelo papel dela no recorte. */
    public const TETOS = ['empresa' => 2500, 'cliente' => 5000, 'aberto' => 3000, 'encerrado' => 700, 'nota' => 3000];

    /** Atendimentos do cliente que entram: os em curso inteiros, dos encerrados só a solução. */
    public const ATENDIMENTOS = 3;

    public const GERAIS = 3;

    /** @var list<string>|null */
    private ?array $validadores = null;

    /**
     * A seção do prompt, ou null com a base vazia (o Pedro segue como antes).
     *
     * @param  string  $recentes  as últimas mensagens do cliente, para achar as notas gerais que combinam
     */
    public function paraOPrompt(SuporteConversa $c, string $recentes): ?string
    {
        $blocos = [];
        foreach (SuporteNota::ativas()->where('tipo', 'empresa')->with('validadoPor:id,name')->orderBy('id')->get() as $n) {
            $blocos[] = $this->bloco($n, self::TETOS['empresa']);
        }

        $cliente = $this->doCliente($c);
        if ($cliente) {
            foreach ((clone $cliente)->where('tipo', 'cliente')->get() as $n) {
                $blocos[] = $this->bloco($n, self::TETOS['cliente']);
            }
        }
        // Os atendimentos do cliente e os desta conversa (o mesmo número, mesmo antes de o cliente ser confirmado).
        $atendimentos = SuporteNota::ativas()->where('tipo', 'atendimento')
            ->where(fn (Builder $q) => $q->where('conversa_id', $c->id)->when($cliente, fn ($q) => $q->orWhere(fn ($q) => $this->filtroDoCliente($q, $c))))
            ->orderByDesc('id')->limit(30)->get();
        [$encerrados, $abertos] = $atendimentos->partition(fn (SuporteNota $n) => $n->encerrada());
        foreach ($abertos->take(self::ATENDIMENTOS) as $n) {
            $blocos[] = $this->bloco($n, self::TETOS['aberto']);
        }
        foreach ($encerrados->take(self::ATENDIMENTOS) as $n) {
            $blocos[] = $this->bloco($n, self::TETOS['encerrado'], true);
        }
        foreach ($this->queCombinam($recentes) as $n) {
            $blocos[] = $this->bloco($n, self::TETOS['nota']);
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

        return "## BASE DO SUPORTE (notas da equipe; cada uma com o número dela)\n"
            .($cliente ? '' : "- Cliente não confirmado: nenhuma nota de cliente foi incluída.\n")
            .implode("\n\n", $saida)
            .($fora ? "\n\n- ({$fora} nota(s) relacionada(s) não couberam neste pedido.)" : '');
    }

    /**
     * Os números que o Pedro diz ter usado e que existem de fato na base: o resto
     * (inventado, descartado) cai fora antes de ser gravado na mensagem.
     *
     * @param  list<int>  $fontes
     * @return list<int>
     */
    public function fontesValidas(array $fontes): array
    {
        if (! $fontes) {
            return [];
        }
        $existentes = SuporteNota::ativas()->whereIn('id', $fontes)->pluck('id')->all();

        return array_values(array_filter(array_unique($fontes), fn ($f) => in_array($f, $existentes, true)));
    }

    /**
     * O registro que o Pedro propõe (decisão do dono, 21/10/2026): fica como
     * PROPOSTA até alguém confirmar. Enquanto não é confirmado, uma conversa tem
     * uma proposta só, que as respostas seguintes completam.
     *
     * @param  array{titulo?: ?string, relato?: ?string, verificado?: ?string, solucao?: ?string, situacao?: ?string}  $r
     */
    public function proporRegistro(SuporteConversa $c, array $r): SuporteNota
    {
        $limpo = fn ($v, int $max) => filled($v) ? Str::limit(trim((string) $v), $max, '…') : null;
        $dados = array_filter([
            'titulo' => $limpo($r['titulo'] ?? null, 160),
            'relato' => $limpo($r['relato'] ?? null, 4000),
            'verificado' => $limpo($r['verificado'] ?? null, 4000),
            'solucao' => $limpo($r['solucao'] ?? null, 4000),
        ], fn ($v) => $v !== null);
        $situacao = in_array($r['situacao'] ?? null, array_keys(SuporteNota::SITUACOES), true) ? $r['situacao'] : 'em_curso';
        // "Resolvido" só com a solução escrita: sem ela, o atendimento continua em curso (FR-B7).
        if ($situacao === 'resolvido' && blank($dados['solucao'] ?? null)) {
            $situacao = 'em_curso';
        }
        $tarefa = $c->tarefa_id && ! in_array(Tarefa::whereKey($c->tarefa_id)->value('status'), Tarefa::STATUS_TERMINAIS, true) ? $c->tarefa_id : null;
        $ligacao = ['gestor_cliente_id' => $c->gestor_cliente_id, 'cliente_id' => $c->cliente_id, 'tarefa_id' => $tarefa];

        $n = SuporteNota::propostas()->where('conversa_id', $c->id)->latest('id')->first();
        if ($n) {
            $n->fill($dados + ['situacao' => $situacao] + array_filter($ligacao))->save();

            return $n;
        }

        return SuporteNota::create($dados + ['titulo' => 'Atendimento pelo WhatsApp'] + $ligacao + [
            'tipo' => 'atendimento', 'estado' => 'proposta', 'situacao' => $situacao, 'conversa_id' => $c->id, 'codigo' => $this->codigo($c)]);
    }

    /**
     * O cliente sumiu (decisão do dono, 21/10/2026): a conversa que o Pedro tocou
     * sozinho, sem atendimento aberto, e que ficou sem resposta do cliente além da
     * janela de 24 h vira "encerrado sem confirmação", para alguém do suporte
     * conferir. Sem IA: o resumo é o que o próprio Pedro anotou em cada resposta.
     */
    public function encerrarSemResposta(): int
    {
        $n = 0;
        $conversas = SuporteConversa::where('situacao', 'pedro')
            ->whereBetween('ultima_entrada_em', [now()->subDays(7), now()->subHours(SuporteConversa::JANELA_HORAS)])
            ->orderBy('id')->limit(200)->get();
        foreach ($conversas as $c) {
            if ($c->tarefa_id && ! in_array(Tarefa::whereKey($c->tarefa_id)->value('status'), Tarefa::STATUS_TERMINAIS, true)) {
                continue;
            }
            // Já registrado desde a última mensagem do cliente (proposto, confirmado ou descartado).
            if (SuporteNota::where('conversa_id', $c->id)->where('created_at', '>=', $c->ultima_entrada_em)->exists()) {
                continue;
            }
            $ultima = $c->mensagens()->reorder()->orderByDesc('id')->first();
            $pedido = SuportePedido::where('conversa_id', $c->id)->where('situacao', 'respondido')->orderByDesc('id')->first();
            if (! $ultima || $ultima->direcao !== 'pedro' || ! $pedido || $pedido->acao !== 'responder' || blank($pedido->resumo)) {
                continue;
            }
            if (app(AcessoInterno::class)->deQuem((string) $c->telefone) || app(DiretorioGestor::class)->daEquipe($c->telefone)) {
                continue;
            }
            $relato = $c->mensagens()->reorder()->where('direcao', 'entrada')->orderByDesc('id')->limit(3)->get()
                ->map(fn (SuporteMensagem $m) => $m->transcricao ?: $m->texto)->reverse()->implode("\n");
            $this->proporRegistro($c, ['titulo' => $pedido->resumo, 'relato' => $relato, 'situacao' => 'encerrado_sem_confirmacao']);
            $n++;
        }

        return $n;
    }

    /** Validam procedimento, conhecimento e empresa: os editores do Pedro e os administradores (decisão do dono, 21/10/2026). */
    public function podeValidar(User $u): bool
    {
        return in_array(mb_strtolower($u->email), array_map('mb_strtolower', (array) config('services.suporte_pedro.editores')), true) || $u->ehAdmin();
    }

    /**
     * Quem confirma o registro é quem atendeu a conversa (decisão do dono): o
     * último que respondeu por aqui, ou quem a assumiu. O Pedro resolveu sozinho?
     * Qualquer um do suporte. Quem valida a base também confirma, para o registro
     * não ficar preso quando quem atendeu se ausenta.
     */
    public function podeConfirmar(User $u, SuporteNota $n): bool
    {
        if ($this->podeValidar($u)) {
            return true;
        }
        $quem = $n->conversa ? $this->quemAtendeu($n->conversa) : null;

        return ! $quem || $quem->id === $u->id;
    }

    public function quemAtendeu(SuporteConversa $c): ?User
    {
        $id = SuporteMensagem::where('conversa_id', $c->id)->where('direcao', 'humano_matriz')->whereNotNull('autor_id')->orderByDesc('id')->value('autor_id')
            ?? $c->humano_id;

        return $id ? User::find($id) : null;
    }

    /**
     * Grava o que veio da tela. Procedimento validado que outra pessoa muda volta
     * a rascunho: o Pedro só segue como instrução o que quem valida leu.
     *
     * @param  array<string, mixed>  $dados
     */
    public function salvar(SuporteNota $n, array $dados, User $u): SuporteNota
    {
        $n->fill($dados);
        if (! $n->exists) {
            $n->autor_id = $u->id;
            $n->estado ??= 'ativa';
        }
        if ($n->exists && $n->validacao === 'validado' && $n->isDirty(['titulo', 'quando_usar', 'corpo'])) {
            $n->fill($this->podeValidar($u) ? ['validado_por' => $u->id, 'validado_em' => now()] : ['validacao' => 'rascunho', 'validado_por' => null, 'validado_em' => null]);
        }
        $n->atualizado_por = $u->id;
        $n->save();

        return $n;
    }

    public function validar(SuporteNota $n, User $u): void
    {
        $n->forceFill(['validacao' => 'validado', 'validado_por' => $u->id, 'validado_em' => now()])->save();
    }

    /** O registro confirmado entra na base e passa a valer na próxima conversa. @param array<string, mixed> $dados */
    public function confirmar(SuporteNota $n, array $dados, User $u): void
    {
        $this->salvar($n, $dados + ['estado' => 'ativa', 'confirmado_por' => $u->id, 'confirmado_em' => now()], $u);
    }

    /**
     * O que se aprendeu num atendimento vira conhecimento para os outros clientes,
     * como RASCUNHO: quem cria tira o que é só daquele cliente, e quem valida decide.
     */
    public function virarConhecimento(SuporteNota $n, User $u): SuporteNota
    {
        return $this->salvar(new SuporteNota(['tipo' => 'conhecimento', 'origem_id' => $n->id, 'titulo' => $n->titulo,
            'corpo' => "O que se aprendeu:\n".trim((string) $n->solucao)."\n\nEm que situação vale (o problema):\n".trim((string) $n->relato)
                .(filled($n->verificado) ? "\n\nO que foi verificado:\n".trim((string) $n->verificado) : '')]), [], $u);
    }

    /** @return list<string> as palavras que importam para a busca */
    public static function termos(string $texto): array
    {
        $genericas = ['para', 'como', 'qual', 'quais', 'quero', 'gostaria', 'saber', 'mais', 'sobre', 'voces', 'tenho', 'preciso', 'esta', 'esse', 'essa',
            'isso', 'aqui', 'onde', 'quando', 'tambem', 'ainda', 'muito', 'pode', 'podem', 'fazer', 'favor', 'obrigado', 'obrigada', 'tudo', 'nao', 'cliente',
            'dia', 'bom', 'boa', 'tarde', 'noite', 'ola', 'oi', 'com', 'que', 'uma', 'meu', 'minha', 'esta', 'estou', 'nada', 'dar', 'deu'];

        return array_values(array_unique(array_filter(preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($texto))),
            fn ($p) => strlen($p) >= 4 && ! in_array($p, $genericas, true))));
    }

    /** A consulta das notas do cliente confirmado na conversa, ou null sem cliente confirmado. */
    private function doCliente(SuporteConversa $c): ?Builder
    {
        if (! $c->gestor_cliente_id && ! $c->cliente_id) {
            return null;
        }

        return SuporteNota::ativas()->where(fn ($q) => $this->filtroDoCliente($q, $c))->orderBy('id');
    }

    private function filtroDoCliente(Builder $q, SuporteConversa $c): void
    {
        $q->where(function ($q) use ($c) {
            if ($c->gestor_cliente_id) {
                $q->orWhere('gestor_cliente_id', $c->gestor_cliente_id);
            }
            if ($c->cliente_id) {
                $q->orWhere('cliente_id', $c->cliente_id);
            }
        });
    }

    /**
     * Até 3 procedimentos ou conhecimentos que combinam com o que o cliente
     * escreveu. O banco filtra por palavra inteira (a base cresce sem limite); a
     * nota é pontuada aqui: o título pesa mais que o "quando usar", que pesa mais
     * que o texto.
     */
    private function queCombinam(string $recentes): Collection
    {
        $palavras = array_slice(self::termos($recentes), 0, 20);
        if (! $palavras) {
            return collect();
        }
        $candidatas = SuporteNota::ativas()->whereIn('tipo', ['procedimento', 'conhecimento'])
            ->where(fn ($q) => collect($palavras)->each(fn ($p) => $q->orWhere('termos', 'like', '% '.$p.' %')))
            ->with('validadoPor:id,name')->orderByDesc('id')->limit(200)->get();

        return $candidatas->map(function (SuporteNota $n) use ($palavras) {
            $titulo = count(array_intersect($palavras, self::termos($n->titulo)));
            $quando = count(array_intersect($palavras, self::termos((string) $n->quando_usar)));
            $corpo = count(array_intersect($palavras, self::termos((string) $n->corpo)));

            // Validada passa na frente da rascunho com a mesma pontuação.
            return ['nota' => $n, 'pontos' => 6 * $titulo + 2 * $quando + min($corpo, 4), 'validada' => $n->validada() ? 1 : 0];
        })->filter(fn ($x) => $x['pontos'] >= 4)->sortBy([['pontos', 'desc'], ['validada', 'desc']])->take(self::GERAIS)->pluck('nota');
    }

    /** O trecho de uma nota no prompt: número, tipo, validação e o texto, cortado no teto. */
    private function bloco(SuporteNota $n, int $teto, bool $soASolucao = false): string
    {
        $etiqueta = SuporteNota::TIPOS[$n->tipo] ?? $n->tipo;
        if ($n->geral()) {
            $etiqueta .= $n->validada()
                ? ' · VALIDADO por '.($n->validadoPor?->name ?? 'quem valida')
                : ' · RASCUNHO (não validado: cite só como possibilidade a confirmar, nunca como instrução)';
        }
        if ($n->tipo === 'atendimento') {
            $etiqueta .= ' · '.(SuporteNota::SITUACOES[$n->situacao] ?? 'Em curso').' · '.$n->created_at->setTimezone(config('app.timezone'))->format('d/m/Y');
            $texto = $soASolucao
                ? 'Solução e resultado: '.(filled($n->solucao) ? trim($n->solucao) : '(não registrada)')
                : collect(['Relato do cliente (não é fato)' => $n->relato, 'Verificado' => $n->verificado, 'Solução e resultado' => $n->solucao])
                    ->filter(fn ($v) => filled($v))->map(fn ($v, $k) => $k.":\n".trim($v))->implode("\n");
        } else {
            $texto = (filled($n->quando_usar) ? 'Quando usar: '.trim($n->quando_usar)."\n" : '').trim((string) $n->corpo);
        }

        return "### [nota {$n->id}] ".($n->codigo ? $n->codigo.' — ' : '').$n->titulo." ({$etiqueta})\n"
            .Str::limit($texto, $teto, ' …(nota cortada)');
    }

    /** AT-AAAAMMDD-<nº da conversa>, com -2, -3… se a conversa já teve um no mesmo dia (decisão do dono, 21/10/2026). */
    private function codigo(SuporteConversa $c): string
    {
        $base = 'AT-'.now()->setTimezone(config('app.timezone'))->format('Ymd').'-'.$c->id;
        $codigo = $base;
        for ($i = 2; SuporteNota::where('codigo', $codigo)->exists(); $i++) {
            $codigo = $base.'-'.$i;
        }

        return $codigo;
    }
}
