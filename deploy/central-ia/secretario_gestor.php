<?php
/**
 * Retrato das pendências do Gestor para o Secretário do AlfaMatriz
 * (docs/specs/secretario-emails-gestor, research D4).
 *
 * Roda DENTRO do Gestor de produção pela ponte da máquina do dono
 * (C:\tmp\run_prod.py). A ponte NÃO desfaz nada sozinha — ela só passa
 * APPLY=1 com --apply (conferido no run_prod.py em 17/10/2026). Por isso a
 * garantia de leitura mora AQUI, em três camadas:
 *   1. com APPLY=1, recusa rodar;
 *   2. a sessão do MySQL é READ ONLY — qualquer escrita falha no banco;
 *   3. tudo dentro de uma transação desfeita no fim.
 * Aqui só há SELECT.
 *
 * As consultas espelham o resumo da Central do Gestor
 * (app/Http/Controllers/CentralController.php, linhas 155-183) — mesmos
 * status e filtros —, mais os leads parados no funil (PreCliente::ESTAGIOS).
 *
 * Saída: UMA linha "SECRETARIO_GESTOR:{json}" (o runner ignora o resto).
 */
require '/var/www/vendor/autoload.php';
$app = require_once '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (getenv('APPLY') === '1') {
    fwrite(STDERR, "secretario_gestor.php é só de leitura: rode sem --apply.\n");
    exit(1);
}
Illuminate\Support\Facades\DB::statement('SET SESSION TRANSACTION READ ONLY');
Illuminate\Support\Facades\DB::beginTransaction();

use App\Models\Cobranca;
use App\Models\ContaPagar;
use App\Models\Orcamento;
use App\Models\PreCliente;
use App\Models\ReuniaoCliente;

$nome = function ($m) {
    // Só o que foi carregado: em Cobranca, `preCliente()` devolve null e acessá-lo quebra.
    $c = $m->relationLoaded('cliente') ? $m->cliente : null;
    $p = $m->relationLoaded('preCliente') ? $m->preCliente : null;

    return trim((string) ($c->nome_fantasia ?? $c->nome ?? $c->razao_social ?? $p->nome_fantasia ?? $p->razao_social ?? '')) ?: 'sem cliente';
};
$curto = fn ($t, $n = 190) => mb_strlen((string) $t) > $n ? mb_substr((string) $t, 0, $n - 1).'…' : (string) $t;
$data = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('Y-m-d') : null;

$orcamentos = fn (string $status) => Orcamento::whereNull('deleted_at')->where('status', $status);
$blocos = [];

foreach (['orcamentos_aprovacao' => 'aguardando_aprovacao', 'orcamentos_elaboracao' => 'em_elaboracao'] as $chave => $status) {
    $blocos[$chave] = [
        'quantidade' => $orcamentos($status)->count(),
        'valor' => (float) $orcamentos($status)->sum('valor_total'),
        'itens' => $orcamentos($status)->with(['cliente', 'preCliente'])->orderBy('updated_at')->limit(10)->get()
            ->map(fn ($o) => ['texto' => $curto('Orç. '.$o->numero_orcamento.($o->revisao ? ' rev '.$o->revisao : '').' — '.$nome($o)),
                'data' => $data($o->data_envio ?? $o->updated_at), 'valor' => (float) $o->valor_total])->values()->all(),
    ];
}

// Cobranca::preCliente() devolve null no Gestor: só `cliente` é carregado.
// Cancelada não é dívida (22/10/2026: apareciam como vencidas).
$aReceber = fn () => Cobranca::whereNotIn('status', ['pago', 'cancelado'])->whereNull('prejuizo_em');
$blocos['cobrancas_vencidas'] = [
    'quantidade' => $aReceber()->whereDate('data_vencimento', '<', today())->count(),
    'valor' => (float) $aReceber()->whereDate('data_vencimento', '<', today())->sum('valor'),
    'itens' => $aReceber()->whereDate('data_vencimento', '<', today())->with('cliente')->orderBy('data_vencimento')->limit(10)->get()
        ->map(fn ($c) => ['texto' => $curto($nome($c).($c->descricao ? ' — '.$c->descricao : '')), 'data' => $data($c->data_vencimento), 'valor' => (float) $c->valor])->values()->all(),
];
$blocos['cobrancas_hoje'] = [
    'quantidade' => $aReceber()->whereDate('data_vencimento', today())->count(),
    'itens' => $aReceber()->whereDate('data_vencimento', today())->with('cliente')->limit(10)->get()
        ->map(fn ($c) => ['texto' => $curto($nome($c).($c->descricao ? ' — '.$c->descricao : '')), 'data' => $data($c->data_vencimento), 'valor' => (float) $c->valor])->values()->all(),
];
$blocos['pagar_vencendo'] = [
    'quantidade' => ContaPagar::where('status', 'em_aberto')->whereDate('data_vencimento', '<=', today())->count(),
    'itens' => [],
];
$blocos['reunioes_proximo_passo'] = [
    'quantidade' => ReuniaoCliente::comPendencia()->count(),
    'itens' => ReuniaoCliente::comPendencia()->with(['cliente', 'preCliente'])->orderByRaw('proximo_passo_em is null')->orderBy('proximo_passo_em')->limit(10)->get()
        ->map(fn ($r) => ['texto' => $curto($nome($r).': '.$r->proximo_passo), 'data' => $data($r->proximo_passo_em)])->values()->all(),
];
$parados = fn () => PreCliente::whereNotIn('estagio_funil', ['fechado', 'perdido'])->where('estagio_funil_em', '<', now()->subDays(7));
$blocos['funil_parado'] = [
    'quantidade' => $parados()->count(),
    'itens' => $parados()->orderBy('estagio_funil_em')->limit(10)->get()
        ->map(fn ($p) => ['texto' => $curto((trim((string) ($p->nome_fantasia ?: $p->razao_social)) ?: 'lead').' — '.(PreCliente::ESTAGIOS[$p->estagio_funil] ?? $p->estagio_funil)),
            'data' => $data($p->estagio_funil_em)])->values()->all(),
];

Illuminate\Support\Facades\DB::rollBack();

echo 'SECRETARIO_GESTOR:'.json_encode($blocos, JSON_UNESCAPED_UNICODE)."\n";
