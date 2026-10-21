<?php

namespace App\Services\Orcamentos;

use App\Models\Lead;
use App\Models\Orcamento;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * As propostas que já foram enviadas antes do Matriz, remontadas no padrão novo
 * (docs/specs/orcamentos-vixcard/padrao-e-historico.md, FR-H6). O levantamento de
 * cada PDF vem num JSON (um objeto por PDF); o conteúdo entra como foi enviado
 * (decisão do Felipe, 20/10/2026) e o PDF original fica guardado ao lado.
 *
 * Cada "grupo" do levantamento é uma proposta com suas versões: um número só,
 * e as versões viram revisões encadeadas (a anterior substituída pela seguinte).
 * Rodar de novo não duplica: a chave é o arquivo de origem.
 */
class Historico
{
    /** O produto do orçamento a partir do projeto do levantamento. */
    private const PRODUTO_DO_SISTEMA = ['vix-id' => 'vix-id', 'vixlink' => 'vixlink', 'gestor-vixcard' => 'gestor', 'alfacontrol' => 'alfacontrol',
        'vix-beer' => 'nexbeer', 'vixtrace' => 'outro'];

    /**
     * @param  list<array<string, mixed>>  $propostas  o levantamento (propostas-import.json)
     * @param  string  $pasta  onde estão os PDFs originais, pelos caminhos relativos de "arquivo"
     * @return list<string> uma linha por proposta: o que foi feito
     */
    public function importar(array $propostas, string $pasta, User $quem): array
    {
        $log = [];
        $grupos = collect($propostas)->groupBy('grupo')
            ->sortBy(fn ($g) => collect($g)->min('data'));
        foreach ($grupos as $grupo => $versoes) {
            $versoes = collect($versoes)->sortBy('ordem_no_grupo')->values();
            DB::transaction(function () use ($versoes, $pasta, $quem, $grupo, &$log) {
                $anterior = null;
                $numero = null;
                foreach ($versoes as $n => $p) {
                    $existente = Orcamento::where('origem_arquivo', $p['arquivo'])->first();
                    if ($existente) {
                        $log[] = "já importado: {$p['arquivo']} ({$existente->codigo()})";
                        [$anterior, $numero] = [$existente, $existente->numero];

                        continue;
                    }
                    $data = Carbon::parse($p['data'] ?? now());
                    $numero ??= (int) Orcamento::where('ano', $data->year)->max('numero') + 1;
                    $o = $this->criar($p, $data, $numero, $n + 1, $anterior, $pasta, $quem);
                    if ($anterior && $anterior->situacao !== 'substituido') {
                        $anterior->update(['situacao' => 'substituido', 'substituido_por_id' => $o->id]);
                    } elseif ($anterior) {
                        $anterior->update(['substituido_por_id' => $o->id]);
                    }
                    $log[] = "{$o->codigo()} [{$o->situacao}] {$grupo}: {$p['arquivo']}";
                    $anterior = $o;
                }
            });
        }

        return $log;
    }

    private function criar(array $p, Carbon $data, int $numero, int $revisao, ?Orcamento $anterior, string $pasta, User $quem): Orcamento
    {
        $capa = (array) ($p['capa'] ?? []);
        $cli = (array) ($p['cliente'] ?? []);
        $pag = (array) ($p['pagamento'] ?? []);
        $corta = fn ($v, int $max) => filled($v) ? mb_substr(trim((string) $v), 0, $max) : null;
        $linhas = fn ($lista) => collect((array) $lista)->map(fn ($l) => trim(str_replace(["\r", "\n"], ' ', (string) $l)))->filter()->implode("\n") ?: null;
        $situacao = in_array($p['situacao'] ?? '', ['enviado', 'rascunho', 'substituido'], true) ? $p['situacao'] : 'enviado';

        $o = Orcamento::create([
            'ano' => $data->year, 'numero' => $numero, 'revisao' => $revisao, 'revisao_de_id' => $anterior?->id, 'situacao' => $situacao,
            'produto' => self::PRODUTO_DO_SISTEMA[$p['sistema'] ?? ''] ?? 'outro',
            'lead_id' => Lead::where('nome', $p['lead'] ?? '')->value('id'),
            'tipo' => $corta($capa['tipo'] ?? null, 40),
            'cliente' => $corta($cli['nome'] ?? null, 160) ?? (string) ($p['lead'] ?? 'Cliente'),
            'contato' => $corta($cli['contato'] ?? null, 120),
            'cidade' => $corta($cli['cidade_uf'] ?? null, 120),
            'rotulo_3' => $corta($capa['rotulo_3'] ?? null, 30),
            'valor_3' => $corta($capa['valor_3'] ?? null, 160),
            'natureza' => in_array($p['natureza'] ?? null, array_keys(Orcamento::NATUREZAS), true) ? $p['natureza'] : null,
            'dados_cliente' => collect($cli)->only(array_keys(Orcamento::DADOS_CLIENTE))->map(fn ($v) => $corta($v, 255))->filter()->all() ?: null,
            'objeto' => $linhas($p['objeto'] ?? []),
            'titulo' => $corta(self::semDestaque((string) ($capa['titulo'] ?? ''), (string) ($capa['destaque'] ?? '')), 120) ?? 'Proposta',
            'destaque' => $corta($capa['destaque'] ?? null, 80),
            'lead' => $corta($capa['subtitulo'] ?? null, 600),
            'validade_dias' => in_array((int) ($p['validade_dias'] ?? 0), Orcamento::VALIDADES, true) ? (int) $p['validade_dias'] : null,
            'entrada' => (float) ($pag['entrada'] ?? 0), 'parcelas' => max(1, (int) ($pag['parcelas'] ?? 1)), 'desconto_avista' => (float) ($pag['desconto_avista'] ?? 0),
            'pagamento_texto' => $corta($pag['texto'] ?? null, 3000),
            'inclui' => $linhas($p['inclui'] ?? []), 'nao_inclui' => $linhas($p['nao_inclui'] ?? []),
            'secoes' => $this->secoes((array) ($p['secoes'] ?? [])),
            'planos' => collect((array) ($p['planos'] ?? []))->map(fn ($pl) => implode(' | ', array_map(fn ($v) => str_replace('|', '/', trim((string) $v)),
                [$pl['nome'] ?? '', $pl['valor'] ?? '', $pl['periodo'] ?? '', $pl['observacao'] ?? ''])))->implode("\n") ?: null,
            'aceite' => (bool) ($p['aceite'] ?? true),
            'notas_internas' => $corta($p['observacoes'] ?? null, 6000),
            'user_id' => $quem->id,
            'enviado_em' => $situacao === 'rascunho' ? null : $data,
            'origem_arquivo' => $p['arquivo'], 'importado_em' => now(),
        ]);
        foreach (array_values((array) ($p['itens'] ?? [])) as $n => $i) {
            if (trim((string) ($i['descricao'] ?? '')) === '') {
                continue;
            }
            $o->itens()->create(['ordem' => $n, 'descricao' => mb_substr(trim($i['descricao']), 0, 255), 'quantidade' => (float) ($i['quantidade'] ?? 1),
                'unidade' => mb_substr(trim((string) ($i['unidade'] ?? 'un')) ?: 'un', 0, 20), 'valor_unitario' => (float) ($i['valor_unitario'] ?? 0)]
                + \App\Models\OrcamentoItem::classificar($i));
        }
        $t = Orcamentos::totais($o->itens()->get());
        $o->update($t + ['entrada' => min((float) $o->entrada, $t['total_unico']), 'pdf_original' => $this->guardarOriginal($pasta, (string) $p['arquivo'], $o)]);

        return $o;
    }

    /**
     * O título sem o trecho em destaque no fim: o levantamento traz a capa como ela
     * se lê ("Controle … portal de saída RFID"), e o PDF já põe o destaque embaixo.
     */
    public static function semDestaque(string $titulo, string $destaque): string
    {
        $t = trim($titulo);
        $d = trim($destaque);
        if ($d !== '' && mb_strlen($t) > mb_strlen($d) && mb_strtolower(mb_substr($t, -mb_strlen($d))) === mb_strtolower($d)) {
            return rtrim(mb_substr($t, 0, -mb_strlen($d)), " \t—-:,");
        }

        return $t;
    }

    /** As seções do levantamento no formato do editor (texto: listas por linha, pares "a | b"). */
    private function secoes(array $s): array
    {
        $txt = fn ($v) => trim((string) ($v ?? ''));
        $lista = fn ($l) => collect((array) $l)->map(fn ($x) => trim(str_replace(["\r", "\n"], ' ', (string) $x)))->filter()->implode("\n");
        $pares = fn ($l, string $a, string $b) => collect((array) $l)->map(fn ($x) => str_replace('|', '/', trim(str_replace(["\r", "\n"], ' ', (string) ($x[$a] ?? ''))))
            .' | '.trim(str_replace(["\r", "\n"], ' ', (string) ($x[$b] ?? ''))))->implode("\n");
        $r = [];
        if ($d = $s['desafio'] ?? null) {
            $r['desafio'] = ['ativo' => '1', 'titulo' => $txt($d['titulo'] ?? null), 'texto' => $txt($d['texto'] ?? null), 'citacao' => $txt($d['citacao'] ?? null),
                'numeros' => $pares($d['numeros'] ?? [], 'valor', 'rotulo'), 'itens' => $lista($d['itens'] ?? [])];
        }
        if ($d = $s['solucao'] ?? null) {
            $r['solucao'] = ['ativo' => '1', 'titulo' => $txt($d['titulo'] ?? null), 'texto' => $txt($d['texto'] ?? null), 'passos' => $lista($d['passos'] ?? [])];
        }
        if ($d = $s['especificacao'] ?? null) {
            $r['especificacao'] = ['ativo' => '1', 'titulo' => $txt($d['titulo'] ?? null), 'linhas' => $pares($d['linhas'] ?? [], 'item', 'detalhe')];
        }
        if ($d = $s['decisoes'] ?? null) {
            $r['decisoes'] = ['ativo' => '1', 'titulo' => $txt($d['titulo'] ?? null), 'linhas' => $pares($d['linhas'] ?? [], 'ponto', 'situacao')];
        }
        if ($d = $s['perguntas'] ?? null) {
            $r['perguntas'] = ['ativo' => '1', 'linhas' => $pares($d, 'pergunta', 'resposta')];
        }
        if ($d = $s['execucao'] ?? null) {
            $r['execucao'] = ['ativo' => '1', 'titulo' => $txt($d['titulo'] ?? null), 'etapas' => $lista($d['etapas'] ?? []), 'prazo' => $txt($d['prazo'] ?? null)];
        }
        if ($d = $s['contracapa'] ?? null) {
            $r['contracapa'] = ['ativo' => '1', 'frase' => $txt($d['frase'] ?? null), 'mensagem' => $txt($d['mensagem'] ?? null)];
        }
        if ($extras = collect((array) ($s['extras'] ?? []))->filter(fn ($e) => filled($e['texto'] ?? null) || filled($e['titulo'] ?? null))) {
            if ($extras->isNotEmpty()) {
                $r['extras'] = ['ativo' => '1', 'texto' => $extras->map(fn ($e) => '## '.$txt($e['titulo'] ?? '')."\n".$txt($e['texto'] ?? ''))->implode("\n\n")];
            }
        }

        return $r;
    }

    private function guardarOriginal(string $pasta, string $arquivo, Orcamento $o): ?string
    {
        $origem = rtrim($pasta, '/').'/'.$arquivo;
        if (! is_file($origem)) {
            return null;
        }
        $destino = "orcamentos/originais/{$o->ano}-".str_pad((string) $o->numero, 3, '0', STR_PAD_LEFT).'-rev'.$o->revisao.'-'.Str::slug(pathinfo($arquivo, PATHINFO_FILENAME)).'.pdf';
        Storage::disk(Pdf::DISCO)->put($destino, (string) file_get_contents($origem));

        return $destino;
    }
}
