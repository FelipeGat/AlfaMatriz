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
 * Técnico e agendamento (Felipe, 21/10/2026: "nem todo atendimento já fica agendado"):
 * com "agendar", a visita entra pelo próprio AgendaTecnicaService do Gestor (conflito de
 * agenda, Google Agenda, cache do atendimento), no início do período e pela duração dele.
 * Agenda recusada (técnico ocupado) não desfaz o atendimento: volta "agendamento_erro".
 * Sem agendar, o técnico, se houver, fica só como responsável do atendimento novo.
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
use App\Services\AgendaTecnicaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
    Auth::onceUsingId($autor);
    $tecnico = ! empty($d['funcionario_id'])
        ? DB::table('funcionarios')->where('id', (int) $d['funcionario_id'])->where('ativo', 1)->whereNull('deleted_at')->value('id') : null;
    if (! empty($d['funcionario_id']) && ! $tecnico) {
        throw new RuntimeException('técnico inexistente ou inativo no Gestor');
    }
    $periodo = (string) ($d['periodo'] ?? '');
    $agendar = ! empty($d['agendar_data']) && $tecnico && isset(AgendaTecnicaService::PERIODOS[$periodo]) && $periodo !== 'noturno';
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
            // Sem agendar, o técnico escolhido fica como responsável; agendando, quem põe é a agenda.
            'funcionario_id' => $agendar ? null : $tecnico,
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
    if ($agendar) {
        $faixa = AgendaTecnicaService::PERIODOS[$periodo];
        $horas = max(1, (int) floor((strtotime('2000-01-01 '.$faixa['fim']) - strtotime('2000-01-01 '.$faixa['inicio'])) / 3600));
        try {
            app(AgendaTecnicaService::class)->adicionarVisitas($at, [[
                'funcionario_id' => (int) $tecnico, 'data' => (string) $d['agendar_data'], 'periodo' => $periodo,
                'hora_inicio' => $faixa['inicio'], 'duracao_horas' => $horas, 'tecnicos_adicionais' => [],
            ]]);
            $r['agendado'] = true;
        } catch (ValidationException $e) {
            $r['agendamento_erro'] = mb_substr((string) collect($e->errors())->flatten()->first(), 0, 200);
        }
    }
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
