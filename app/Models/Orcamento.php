<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Um orçamento VixCard (docs/specs/orcamentos-vixcard). Cinco situações, e só
 * as da VENDA: produção e entrega ficam fora daqui. Enviado não se edita —
 * revisa-se, e a revisão nova substitui a anterior com o mesmo número.
 */
class Orcamento extends Model
{
    public const SITUACOES = [
        // "Em negociação" (20/10/2026): o cliente respondeu e se discute valor ou escopo; ainda está em aberto.
        'rascunho' => 'Rascunho', 'enviado' => 'Enviado', 'negociacao' => 'Em negociação', 'aprovado' => 'Aprovado', 'recusado' => 'Recusado', 'substituido' => 'Substituído',
    ];

    public const VALIDADES = [7, 15, 30];

    public const PARCELAS = [1, 2, 3, 4, 5, 6, 10, 12];

    protected $table = 'orcamentos';

    protected $fillable = [
        'ano', 'numero', 'revisao', 'revisao_de_id', 'substituido_por_id', 'situacao', 'produto', 'cliente', 'contato', 'cidade',
        'titulo', 'destaque', 'lead', 'validade_dias', 'entrada', 'parcelas', 'desconto_avista', 'inclui', 'nao_inclui', 'passos',
        'aceite', 'observacoes', 'total_unico', 'total_material', 'total_implantacao', 'total_mensal', 'user_id', 'pdf_arquivo', 'pdf_gerado_em', 'enviado_em', 'respondido_em',
        // Etapa 2 (docs/specs/orcamentos-vixcard/padrao-e-historico.md).
        'lead_id', 'tipo', 'rotulo_3', 'valor_3', 'natureza', 'dados_cliente', 'objeto', 'secoes', 'planos', 'pagamento_texto', 'notas_internas',
        'origem_arquivo', 'pdf_original', 'importado_em', 'imagens',
    ];

    public const TIPOS = ['Proposta comercial', 'Locação', 'Projeto piloto', 'Apresentação'];

    public const NATUREZAS = ['venda' => 'Venda', 'locacao' => 'Locação', 'comodato' => 'Comodato', 'assinatura' => 'Assinatura', 'apresentacao' => 'Apresentação'];

    /** Os campos do bloco "Cliente e objeto", na ordem da tabela da proposta. */
    public const DADOS_CLIENTE = ['razao_social' => 'Razão social', 'cnpj' => 'CNPJ', 'ie' => 'Inscrição estadual', 'endereco' => 'Endereço',
        'cargo' => 'Cargo do contato', 'telefone' => 'Telefone', 'email' => 'E-mail', 'atividade' => 'Atividade'];

    /**
     * As seções opcionais, na ordem em que saem no PDF, com os campos de cada uma.
     * Listas são uma linha por item; pares são "a | b" na mesma linha.
     */
    public const SECOES = [
        'desafio' => ['nome' => 'O desafio', 'campos' => ['titulo', 'texto', 'citacao', 'numeros', 'itens']],
        'solucao' => ['nome' => 'A solução', 'campos' => ['titulo', 'texto', 'passos']],
        'especificacao' => ['nome' => 'Especificação', 'campos' => ['titulo', 'linhas']],
        'decisoes' => ['nome' => 'Decisões técnicas', 'campos' => ['titulo', 'linhas']],
        'extras' => ['nome' => 'Seções livres', 'campos' => ['texto']],
        'perguntas' => ['nome' => 'Perguntas e respostas', 'campos' => ['linhas']],
        'execucao' => ['nome' => 'Execução e prazo', 'campos' => ['titulo', 'etapas', 'prazo']],
        'contracapa' => ['nome' => 'Contracapa', 'campos' => ['frase', 'mensagem']],
    ];

    protected $attributes = ['situacao' => 'rascunho', 'revisao' => 1, 'validade_dias' => 15, 'parcelas' => 1, 'aceite' => true,
        'entrada' => 0, 'desconto_avista' => 0, 'total_unico' => 0, 'total_mensal' => 0];

    protected function casts(): array
    {
        return [
            'entrada' => 'float', 'desconto_avista' => 'float', 'total_unico' => 'float', 'total_material' => 'float', 'total_implantacao' => 'float', 'total_mensal' => 'float', 'aceite' => 'boolean',
            'parcelas' => 'integer', 'validade_dias' => 'integer', 'revisao' => 'integer', 'numero' => 'integer', 'ano' => 'integer',
            'pdf_gerado_em' => 'datetime', 'enviado_em' => 'datetime', 'respondido_em' => 'datetime',
            'dados_cliente' => 'array', 'secoes' => 'array', 'importado_em' => 'datetime', 'imagens' => 'array',
        ];
    }

    public function itens(): HasMany
    {
        return $this->hasMany(OrcamentoItem::class)->orderBy('ordem')->orderBy('id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** O pré-cliente do Funil de Vendas (o cliente de quem recebe proposta). */
    public function preCliente(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /** Um campo de uma seção opcional, ou null. */
    public function secao(string $secao, string $campo): ?string
    {
        $v = trim((string) data_get($this->secoes, "{$secao}.{$campo}"));

        return $v === '' ? null : $v;
    }

    /** A seção está ligada e tem algum conteúdo? */
    public function temSecao(string $secao): bool
    {
        return (bool) data_get($this->secoes, "{$secao}.ativo") && collect(self::SECOES[$secao]['campos'] ?? [])->contains(fn ($c) => $this->secao($secao, $c) !== null);
    }

    /** Linhas de um campo de seção (lista). @return list<string> */
    public function linhasDaSecao(string $secao, string $campo): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->secao($secao, $campo)) ?: [])));
    }

    /** Pares "a | b" de um campo (tabelas de duas colunas). @return list<array{0: string, 1: string}> */
    public function paresDaSecao(string $secao, string $campo): array
    {
        return array_map(fn ($l) => array_pad(array_map('trim', explode('|', $l, 2)), 2, ''), $this->linhasDaSecao($secao, $campo));
    }

    /** As seções livres: cada uma começa por uma linha "## Título". @return list<array{titulo: string, texto: string}> */
    public function secoesLivres(): array
    {
        $blocos = [];
        foreach (preg_split('/\R/', (string) $this->secao('extras', 'texto')) ?: [] as $linha) {
            if (preg_match('/^##\s*(.+)$/', $linha, $m)) {
                $blocos[] = ['titulo' => trim($m[1]), 'texto' => ''];
            } elseif ($blocos) {
                $blocos[count($blocos) - 1]['texto'] .= $linha."\n";
            } elseif (trim($linha) !== '') {
                $blocos[] = ['titulo' => '', 'texto' => $linha."\n"];
            }
        }

        return array_map(fn ($b) => ['titulo' => $b['titulo'], 'texto' => trim($b['texto'])], $blocos);
    }

    /** Onde uma imagem pode entrar no PDF, além das seções livres (que entram pela chave "livre:<slug do título>"). */
    public const LUGARES_DE_IMAGEM = ['galeria' => 'Página de imagens (na prática)', 'desafio' => 'Junto do desafio', 'solucao' => 'Junto da solução',
        'especificacao' => 'Escopo do fornecimento'];

    /**
     * Os lugares de imagem deste orçamento: os fixos e uma entrada por seção livre.
     *
     * @return array<string, string>
     */
    public function lugaresDeImagem(): array
    {
        $livres = collect($this->secoesLivres())->filter(fn ($b) => $b['titulo'] !== '')
            ->mapWithKeys(fn ($b) => ['livre:'.\Illuminate\Support\Str::slug($b['titulo']) => 'Seção: '.$b['titulo']])->all();

        return self::LUGARES_DE_IMAGEM + $livres;
    }

    /** @return list<array{arquivo: string, legenda: string, onde: string}> */
    public function imagensDoCorpo(): array
    {
        return array_values(array_filter(array_map(fn ($i) => ['arquivo' => (string) ($i['arquivo'] ?? ''), 'legenda' => trim((string) ($i['legenda'] ?? '')),
            'onde' => (string) ($i['onde'] ?? 'galeria')], (array) $this->imagens), fn ($i) => $i['arquivo'] !== ''));
    }

    /** Planos alternativos: "Nome | valor | período | observação". @return list<array{nome: string, valor: string, periodo: string, observacao: string}> */
    public function planosLista(): array
    {
        return array_values(array_filter(array_map(function ($l) {
            [$nome, $valor, $periodo, $obs] = array_pad(array_map('trim', explode('|', $l, 4)), 4, '');

            return $nome === '' ? null : ['nome' => $nome, 'valor' => $valor, 'periodo' => $periodo, 'observacao' => $obs];
        }, $this->linhas('planos'))));
    }

    /** Algum dado cadastral do cliente preenchido (o bloco "Cliente e objeto" só sai com ele). */
    public function temDadosDoCliente(): bool
    {
        return collect((array) $this->dados_cliente)->filter(fn ($v) => trim((string) $v) !== '')->isNotEmpty() || $this->linhas('objeto');
    }

    /**
     * "001", e "001.Rev2" a partir da segunda revisão (Felipe, 20/10/2026: "as
     * propostas sigam um padrão de orçamentos 001…002 […] Orçamento 001.Rev2").
     * O ano continua no banco (a numeração recomeça a cada ano), mas não no código.
     */
    public function codigo(bool $comRevisao = true): string
    {
        $c = str_pad((string) $this->numero, 3, '0', STR_PAD_LEFT);

        return $comRevisao && $this->revisao > 1 ? $c.'.Rev'.$this->revisao : $c;
    }

    public function editavel(): bool
    {
        return $this->situacao === 'rascunho';
    }

    /** A data de referência da proposta: o envio, ou hoje enquanto é rascunho. */
    public function data(): Carbon
    {
        return Carbon::parse($this->enviado_em ?? now());
    }

    /** Sem validade (histórico importado que não tinha), null. */
    public function validoAte(): ?Carbon
    {
        return $this->validade_dias ? $this->data()->copy()->addDays($this->validade_dias) : null;
    }

    public function valorAVista(): float
    {
        return round($this->total_unico * (1 - $this->desconto_avista / 100), 2);
    }

    public function valorParcela(): float
    {
        return $this->parcelas > 0 ? round(max(0, $this->total_unico - $this->entrada) / $this->parcelas, 2) : 0;
    }

    /**
     * A frase de pagamento que vai no PDF, pronta — a ideia boa do Gestor
     * (`condicoes_pagamento_texto`), sem os sete botões de rádio.
     */
    public function condicoes(): string
    {
        $brl = fn (float $v) => 'R$ '.number_format($v, 2, ',', '.');
        if ($this->total_unico <= 0) {
            $frase = '';
        } elseif ($this->parcelas <= 1) {
            $frase = 'À vista: '.$brl($this->valorAVista()).($this->desconto_avista > 0 ? ' ('.self::pct($this->desconto_avista).' de desconto).' : '.');
        } else {
            $frase = ($this->entrada > 0 ? 'Entrada de '.$brl($this->entrada).' e saldo' : 'Total').' em '.$this->parcelas.'x de '.$brl($this->valorParcela()).' sem juros'
                .($this->desconto_avista > 0 ? ', ou à vista com '.self::pct($this->desconto_avista).' de desconto: '.$brl($this->valorAVista()) : '').'.';
        }
        if ($this->total_mensal > 0) {
            $frase = trim($frase.' Mensalidade de '.$brl($this->total_mensal).' a partir da instalação.');
        }

        return $frase;
    }

    /** Uma linha por item de texto (inclui, não inclui, passos). @return list<string> */
    public function linhas(string $campo): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->{$campo}) ?: [])));
    }

    /** Nome do arquivo do PDF: PROPOSTA_001.Rev2_MARMORARIA_LORENZO.pdf */
    public function nomeDoPdf(): string
    {
        $cliente = strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '_', (string) \Illuminate\Support\Str::ascii($this->cliente)), '_'));

        return sprintf('PROPOSTA_%s_%s.pdf', $this->codigo(), substr($cliente, 0, 60) ?: 'CLIENTE');
    }

    private static function pct(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',').'%';
    }
}
