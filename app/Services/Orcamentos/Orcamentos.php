<?php

namespace App\Services\Orcamentos;

use App\Models\DocumentoPasta;
use App\Models\Orcamento;
use App\Models\OrcamentoItem;
use App\Models\User;
use App\Services\Documentos\Alteracoes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * As regras dos orçamentos (docs/specs/orcamentos-vixcard): número por ano sem
 * repetir, revisão que substitui a anterior com o mesmo número, e o PDF do
 * enviado guardado também na pasta do cliente em Documentos (e dali no OneDrive).
 */
class Orcamentos
{
    /** A pasta de Documentos onde o PDF enviado é guardado, e o caminho dos clientes dentro dela. */
    public const PASTA_DOCUMENTOS = 'VixCard';

    public const CAMINHO_CLIENTES = 'Comercial/Orçamentos/Clientes';

    public function __construct(private Pdf $pdf, private Alteracoes $alteracoes) {}

    public function criar(User $quem, array $dados): Orcamento
    {
        // O número sai do maior do ano + 1; a chave única segura a corrida de dois ao mesmo tempo.
        for ($tentativa = 1; ; $tentativa++) {
            try {
                return DB::transaction(function () use ($quem, $dados) {
                    $ano = (int) now()->year;
                    $numero = (int) Orcamento::where('ano', $ano)->lockForUpdate()->max('numero') + 1;
                    $o = Orcamento::create(['ano' => $ano, 'numero' => $numero, 'revisao' => 1, 'user_id' => $quem->id, 'situacao' => 'rascunho']
                        + $this->campos($dados));
                    $this->gravarItens($o, $dados['itens'] ?? []);

                    return $o;
                });
            } catch (QueryException $e) {
                if ($tentativa >= 3) {
                    throw $e;
                }
            }
        }
    }

    public function atualizar(Orcamento $o, array $dados): Orcamento
    {
        $this->exigirRascunho($o);

        return DB::transaction(function () use ($o, $dados) {
            $o->update($this->campos($dados));
            $this->gravarItens($o, $dados['itens'] ?? []);

            return $o->fresh('itens');
        });
    }

    /** Nova revisão (mesmo número, Rev. + 1) em rascunho; a anterior fica Substituída. */
    public function revisar(User $quem, Orcamento $o): Orcamento
    {
        if (! in_array($o->situacao, ['enviado', 'recusado', 'aprovado'], true)) {
            throw ValidationException::withMessages(['situacao' => 'Só se revisa orçamento enviado, aprovado ou recusado; o rascunho se edita direto.']);
        }

        return DB::transaction(function () use ($quem, $o) {
            $nova = $this->copiar($o, ['ano' => $o->ano, 'numero' => $o->numero, 'revisao' => (int) Orcamento::where('ano', $o->ano)->where('numero', $o->numero)->max('revisao') + 1,
                'revisao_de_id' => $o->id, 'user_id' => $quem->id]);
            $o->update(['situacao' => 'substituido', 'substituido_por_id' => $nova->id]);

            return $nova;
        });
    }

    /** Outro orçamento, número novo, a partir deste — o atalho para cliente parecido. */
    public function duplicar(User $quem, Orcamento $o): Orcamento
    {
        $dados = $o->only(['produto', 'cliente', 'contato', 'cidade', 'titulo', 'destaque', 'lead', 'validade_dias', 'entrada', 'parcelas',
            'desconto_avista', 'inclui', 'nao_inclui', 'passos', 'aceite', 'observacoes',
            'lead_id', 'tipo', 'rotulo_3', 'valor_3', 'natureza', 'dados_cliente', 'objeto', 'secoes', 'planos', 'pagamento_texto', 'notas_internas']);
        $dados['cliente'] = $o->cliente.' (cópia)';
        $dados['itens'] = $o->itens->map(fn ($i) => $i->only(['descricao', 'quantidade', 'unidade', 'valor_unitario', 'cobranca']))->all();

        return $this->criar($quem, $dados);
    }

    /**
     * Marca como enviado: congela o PDF desta revisão e o guarda na pasta do
     * cliente em Documentos, de onde a máquina do dono leva ao OneDrive.
     */
    public function enviado(User $quem, Orcamento $o): ?string
    {
        $this->exigirRascunho($o);
        if (! $o->itens()->exists() || $o->total_unico + $o->total_mensal <= 0) {
            throw ValidationException::withMessages(['itens' => 'Coloque ao menos um item com valor antes de enviar.']);
        }
        $o->update(['situacao' => 'enviado', 'enviado_em' => now()]);
        $arquivo = $this->pdf->gerar($o->fresh('itens'));

        return $this->guardarEmDocumentos($quem, $o->fresh(), $arquivo);
    }

    public function responder(Orcamento $o, string $resposta): void
    {
        if ($o->situacao !== 'enviado' || ! in_array($resposta, ['aprovado', 'recusado'], true)) {
            throw ValidationException::withMessages(['situacao' => 'Só o orçamento enviado recebe aprovação ou recusa.']);
        }
        $o->update(['situacao' => $resposta, 'respondido_em' => now()]);
    }

    /** O caminho em Documentos onde o PDF foi anotado, ou null se a pasta VixCard não existe para quem enviou. */
    private function guardarEmDocumentos(User $quem, Orcamento $o, string $arquivo): ?string
    {
        $pasta = DocumentoPasta::visiveisPara($quem)->where('nome', self::PASTA_DOCUMENTOS)->first();
        if (! $pasta) {
            return null;
        }
        $cliente = trim(preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]+/u', ' ', $o->cliente)) ?: 'Cliente';
        $caminho = self::CAMINHO_CLIENTES.'/'.mb_substr($cliente, 0, 100).'/'.$o->nomeDoPdf();
        $this->alteracoes->enviarArquivo($pasta, $quem, $caminho, Storage::disk(Pdf::DISCO)->path($arquivo));

        return $caminho;
    }

    private function exigirRascunho(Orcamento $o): void
    {
        if (! $o->editavel()) {
            throw ValidationException::withMessages(['situacao' => 'Orçamento '.Orcamento::SITUACOES[$o->situacao].' não se altera: use Revisar.']);
        }
    }

    private function copiar(Orcamento $o, array $extra): Orcamento
    {
        // O original e a origem são do documento que foi enviado: a revisão nova não os herda.
        $nova = Orcamento::create(collect($o->getAttributes())->except(['id', 'situacao', 'substituido_por_id', 'pdf_arquivo', 'pdf_gerado_em',
            'enviado_em', 'respondido_em', 'created_at', 'updated_at', 'origem_arquivo', 'pdf_original', 'importado_em'])
            ->merge(['situacao' => 'rascunho', 'dados_cliente' => $o->dados_cliente, 'secoes' => $o->secoes])->merge($extra)->all());
        foreach ($o->itens as $i) {
            $nova->itens()->create($i->only(['ordem', 'descricao', 'quantidade', 'unidade', 'valor_unitario', 'cobranca']));
        }

        return $nova->fresh('itens');
    }

    private function campos(array $d): array
    {
        $c = collect($d)->only(['produto', 'cliente', 'contato', 'cidade', 'titulo', 'destaque', 'lead', 'validade_dias', 'entrada', 'parcelas',
            'desconto_avista', 'inclui', 'nao_inclui', 'passos', 'aceite', 'observacoes',
            'lead_id', 'tipo', 'rotulo_3', 'valor_3', 'natureza', 'dados_cliente', 'objeto', 'secoes', 'planos', 'pagamento_texto', 'notas_internas'])->all();
        $c['aceite'] = (bool) ($d['aceite'] ?? false);
        foreach (['entrada', 'desconto_avista'] as $k) {
            $c[$k] = (float) ($c[$k] ?? 0);
        }

        return $c;
    }

    private function gravarItens(Orcamento $o, array $itens): void
    {
        $o->itens()->delete();
        $unico = $mensal = 0.0;
        foreach (array_values($itens) as $n => $i) {
            if (trim((string) ($i['descricao'] ?? '')) === '') {
                continue;
            }
            $item = $o->itens()->create(['ordem' => $n, 'descricao' => trim($i['descricao']), 'quantidade' => (float) ($i['quantidade'] ?? 1),
                'unidade' => trim((string) ($i['unidade'] ?? 'un')) ?: 'un', 'valor_unitario' => (float) ($i['valor_unitario'] ?? 0),
                'cobranca' => array_key_exists($i['cobranca'] ?? '', OrcamentoItem::COBRANCAS) ? $i['cobranca'] : 'unico']);
            match ($item->cobranca) { 'mensal' => $mensal += $item->subtotal(), 'unico' => $unico += $item->subtotal(), default => null };
        }
        // A entrada nunca passa do total (senão a parcela fica negativa).
        $o->update(['total_unico' => round($unico, 2), 'total_mensal' => round($mensal, 2), 'entrada' => min((float) $o->entrada, round($unico, 2))]);
    }
}
