<?php
/**
 * Grava UM atendimento no Gestor de produção para o Pedro, do suporte no WhatsApp
 * (docs/specs/suporte-pedro-whatsapp/acesso-interno.md, FR-A8; Felipe, 21/10/2026:
 * "sempre ir para o Gestor"). O runner copia este modelo, troca __DADOS__ pelo pedido
 * (JSON em base64) e roda pela ponte. Sem APPLY=1 é ensaio: faz tudo e desfaz.
 *
 * Três modos, conferidos aqui de novo (o Matriz decide pelo retrato, que pode ter 10 min):
 *   - novo: imita o portal do cliente (PortalController::storeChamado). Nasce "aberto",
 *     sem técnico e sem agendamento, então não dispara push, Google Agenda nem mensagem.
 *     O número sai do MAX da tabela, travado na mesma transação da gravação. O assunto
 *     tem de existir e estar ativo; nunca é criado aqui.
 *   - vincular: o atendimento ABERTO do mesmo cliente ganha um andamento com o pedido.
 *   - reabrir: o atendimento CONCLUÍDO do mesmo cliente volta a "em_atendimento", com
 *     andamento e histórico, como o botão Reabrir do Gestor
 *     (AtendimentoStatusController::reabrir).
 * Se o atendimento a vincular já foi concluído, reabre; se o que seria reaberto voltou a
 * ficar aberto, vincula. Nunca apaga nada.
 *
 * O andamento exige autor em produção (user_id NOT NULL): é a conta do Gestor com o
 * e-mail da conta dona do Pedro (o Felipe); quem pediu de fato vai no texto.
 *
 * Saída: UMA linha "SUPORTE_GESTOR_GRAVADO:{json}".
 */
require '/var/www/vendor/autoload.php';
$app = require_once '/var/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Atendimento;
use App\Models\AtendimentoAndamento;
use App\Models\AtendimentoStatusHistorico;
use Illuminate\Support\Facades\DB;

$d = json_decode(base64_decode('__DADOS__'), true);
$aplicar = getenv('APPLY') === '1';
$saida = fn (array $r) => print('SUPORTE_GESTOR_GRAVADO:'.json_encode($r, JSON_UNESCAPED_UNICODE)."\n");
$origem = 'Pedro, suporte do AlfaMatriz pelo WhatsApp';

try {
    $modo = in_array($d['modo'] ?? 'novo', ['novo', 'vincular', 'reabrir'], true) ? ($d['modo'] ?? 'novo') : 'novo';
    if (! is_array($d) || empty($d['cliente_id']) || trim((string) ($d['descricao'] ?? '')) === '') {
        throw new RuntimeException('pedido incompleto');
    }
    $cliente = DB::table('clientes')->where('id', (int) $d['cliente_id'])->where('ativo', 1)->first();
    if (! $cliente) {
        throw new RuntimeException('cliente inexistente ou inativo no Gestor');
    }
    $descricao = trim((string) $d['descricao']);
    $autor = DB::table('users')->where('email', (string) ($d['autor_email'] ?? ''))->value('id');
    if (! $autor) {
        throw new RuntimeException('a conta do Pedro não tem usuário no Gestor');
    }
    $solicitante = mb_substr((string) ($d['solicitante'] ?? 'Contato pelo WhatsApp'), 0, 255);

    DB::beginTransaction();
    $existente = null;
    if ($modo !== 'novo') {
        $existente = Atendimento::where('numero_atendimento', (string) ($d['atendimento'] ?? ''))->where('cliente_id', (int) $cliente->id)->lockForUpdate()->first();
        if (! $existente) {
            throw new RuntimeException('atendimento nº '.($d['atendimento'] ?? '?').' não é deste cliente');
        }
        if ($existente->status_atual === 'cancelado') {
            throw new RuntimeException('atendimento nº '.$existente->numero_atendimento.' está cancelado');
        }
        $modo = $existente->status_atual === 'concluido' ? 'reabrir' : 'vincular';
    }

    if ($modo === 'novo') {
        $assunto = DB::table('assuntos')->where('id', (int) ($d['assunto_id'] ?? 0))->where('ativo', 1)->first();
        if (! $assunto) {
            throw new RuntimeException('assunto inexistente ou inativo no Gestor');
        }
        $prioridade = in_array($p = mb_strtolower(trim((string) ($d['prioridade'] ?? 'media'))), ['baixa', 'media', 'alta'], true) ? $p : 'media';
        $ultimo = (int) DB::table('atendimentos')->lockForUpdate()->max('numero_atendimento');
        $at = Atendimento::create([
            'numero_atendimento' => $ultimo + 1,
            'cliente_id' => (int) $cliente->id,
            'nome_solicitante' => $solicitante,
            'telefone_solicitante' => mb_substr((string) ($d['telefone'] ?? ''), 0, 30),
            'assunto_id' => (int) $assunto->id,
            'descricao' => "Assunto: {$assunto->nome}\n\n".$descricao,
            'prioridade' => $prioridade,
            'empresa_id' => ! empty($d['empresa_id']) ? (int) $d['empresa_id'] : ($assunto->empresa_id ?: null),
            'funcionario_id' => null,
            'status_atual' => 'aberto',
            'is_orcamento' => false,
            'data_atendimento' => now(),
        ]);
        AtendimentoStatusHistorico::create(['atendimento_id' => $at->id, 'status' => 'aberto', 'observacao' => "Abertura via WhatsApp ({$origem})", 'user_id' => $autor]);
    } else {
        $at = $existente;
        if ($modo === 'reabrir') {
            $at->update(['status_atual' => 'em_atendimento']);
            AtendimentoStatusHistorico::create(['atendimento_id' => $at->id, 'status' => 'em_atendimento',
                'observacao' => "Status alterado de \"concluido\" para \"em_atendimento\" (reabertura via WhatsApp, {$origem})", 'user_id' => $autor]);
        }
        AtendimentoAndamento::create(['atendimento_id' => $at->id, 'user_id' => $autor,
            'descricao' => ($modo === 'reabrir' ? 'Atendimento reaberto' : 'Novo pedido no mesmo atendimento')." pelo WhatsApp — {$solicitante}:\n".$descricao]);
    }

    $r = ['ok' => true, 'modo' => $modo, 'numero' => (string) $at->numero_atendimento, 'id' => (int) $at->id];
    if ($aplicar) {
        DB::commit();
    } else {
        DB::rollBack();
        $r['ensaio'] = true;
    }
    $saida($r);
} catch (Throwable $e) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $saida(['ok' => false, 'erro' => mb_substr($e->getMessage(), 0, 200)]);
}
