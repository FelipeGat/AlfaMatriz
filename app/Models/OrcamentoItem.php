<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma linha do investimento: valor único ou mensalidade; quantidade decimal (m, CNPJ, un). */
class OrcamentoItem extends Model
{
    /**
     * "Opção" fica fora do total (20/10/2026): faixa de quantidade, item opcional
     * ou "sob consulta" — o cliente escolhe, e somar tudo dava um total que ninguém pediu.
     */
    public const COBRANCAS = ['unico' => 'Valor único', 'mensal' => 'Mensal', 'opcao' => 'Opção (fora do total)'];

    protected $table = 'orcamento_itens';

    /**
     * O que o item É (Felipe, 20/10/2026: "dividir o que é material, do que é
     * implantação e do que é recorrente — é um calcanhar de Aquiles que precisa
     * começar certo"). Independe de estar no total: opcional é a `cobranca` 'opcao'.
     */
    public const CATEGORIAS = ['material' => 'Material', 'implantacao' => 'Implantação', 'recorrente' => 'Recorrente'];

    protected $fillable = ['orcamento_id', 'ordem', 'descricao', 'quantidade', 'unidade', 'valor_unitario', 'cobranca', 'categoria'];

    /**
     * A categoria provável pela descrição — para o histórico importado, os modelos
     * e os itens antigos. Na tela, quem decide é a pessoa.
     */
    public static function categoriaProvavel(string $descricao, ?string $cobranca = null): string
    {
        $d = mb_strtolower(\Illuminate\Support\Str::ascii($descricao));
        if ($cobranca === 'mensal' || preg_match('/mensal|mensalidade|licen[cs]a|assinatura|\/mes\b|por mes/', $d)) {
            return 'recorrente';
        }
        if (preg_match('/implanta|instala|treinament|configura|mao de obra|servi[cç]o|landing|desenvolviment|visita|calibra|montagem|valor global|estrutura e disponibilidade|diaria|projeto|setup|cadastro/', $d)) {
            return 'implantacao';
        }

        return 'material';
    }

    /**
     * Categoria e cobrança coerentes a partir do que veio do formulário (ou de um
     * formulário antigo, que só mandava a cobrança). Recorrente é mensal; opcional
     * fica fora do total em qualquer categoria.
     *
     * @return array{categoria: string, cobranca: string}
     */
    public static function classificar(array $i): array
    {
        $cobranca = (string) ($i['cobranca'] ?? '');
        $categoria = array_key_exists($i['categoria'] ?? '', self::CATEGORIAS) ? $i['categoria'] : self::categoriaProvavel((string) ($i['descricao'] ?? ''), $cobranca);
        $opcional = ! empty($i['opcional']) || $cobranca === 'opcao';

        return ['categoria' => $categoria, 'cobranca' => $opcional ? 'opcao' : ($categoria === 'recorrente' ? 'mensal' : 'unico')];
    }

    protected function casts(): array
    {
        return ['quantidade' => 'float', 'valor_unitario' => 'float', 'ordem' => 'integer'];
    }

    public function orcamento(): BelongsTo
    {
        return $this->belongsTo(Orcamento::class);
    }

    public function categoriaEfetiva(): string
    {
        return $this->categoria ?: self::categoriaProvavel((string) $this->descricao, $this->cobranca);
    }

    public function subtotal(): float
    {
        return round($this->quantidade * $this->valor_unitario, 2);
    }

    /** 300 → "300"; 12,5 → "12,5". */
    public function quantidadeLegivel(): string
    {
        return rtrim(rtrim(number_format($this->quantidade, 3, ',', '.'), '0'), ',');
    }
}
