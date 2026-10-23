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
    // Cliente, pré-cliente do funil ou pré-cliente NOVO (nome e cidade), criado aqui mesmo (22/10/2026).
    $novoPre = is_array($d['novo_pre'] ?? null) && trim((string) ($d['novo_pre']['nome'] ?? '')) !== '' ? $d['novo_pre'] : null;
    if (! is_array($d) || (empty($d['cliente_id']) && empty($d['pre_cliente_id']) && ! $novoPre) || trim((string) ($d['descricao'] ?? '')) === '') {
        throw new RuntimeException('pedido incompleto');
    }
    $cliente = ! empty($d['cliente_id']) ? DB::table('clientes')->where('id', (int) $d['cliente_id'])->where('ativo', 1)->first() : null;
    $pre = ! $cliente && ! empty($d['pre_cliente_id']) ? DB::table('pre_clientes')->where('id', (int) $d['pre_cliente_id'])->first() : null;
    if (! $cliente && ! $pre && ! $novoPre) {
        throw new RuntimeException('cliente ou pré-cliente inexistente ou inativo no Gestor');
    }
    // Para buscar o atendimento existente e gravar o novo: pela coluna de cliente OU de pré-cliente.
    $dono = fn () => $cliente ? ['cliente_id' => (int) $cliente->id] : ['pre_cliente_id' => (int) $pre->id];
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
    // Horário de início e fim (22/10/2026: "toda agenda precisa de técnico e período"); o período antigo continua aceito.
    $inicio = preg_match('/^\d{2}:\d{2}$/', (string) ($d['hora_inicio'] ?? '')) ? $d['hora_inicio'] : null;
    $fim = preg_match('/^\d{2}:\d{2}$/', (string) ($d['hora_fim'] ?? '')) ? $d['hora_fim'] : null;
    $periodo = $inicio && $fim && $fim > $inicio ? 'personalizado' : (string) ($d['periodo'] ?? '');
    $agendar = ! empty($d['agendar_data']) && $tecnico && isset(AgendaTecnicaService::PERIODOS[$periodo]) && $periodo !== 'noturno';
    $solicitante = mb_substr((string) ($d['solicitante'] ?? 'Contato pelo WhatsApp'), 0, 255);

    DB::beginTransaction();
    if ($novoPre) {
        $preId = DB::table('pre_clientes')->insertGetId([
            'tipo_pessoa' => 'PJ', 'razao_social' => mb_substr(trim((string) $novoPre['nome']), 0, 255), 'nome_fantasia' => mb_substr(trim((string) $novoPre['nome']), 0, 255),
            'cidade' => mb_substr(trim((string) ($novoPre['cidade'] ?? '')), 0, 255) ?: null, 'origem' => 'manual', 'origem_lead' => 'Whatsapp',
            'telefone' => null, 'created_by' => $autor, 'empresa_id' => ! empty($d['empresa_id']) ? (int) $d['empresa_id'] : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $pre = DB::table('pre_clientes')->where('id', $preId)->first();
        $modo = 'novo';
    }
    $existente = null;
    if ($modo !== 'novo') {
        $existente = Atendimento::where('numero_atendimento', (string) ($d['atendimento'] ?? ''))->where($dono())->lockForUpdate()->first();
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
            'cliente_id' => $cliente ? (int) $cliente->id : null,
            'pre_cliente_id' => $cliente ? null : (int) $pre->id,
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

    $r = ['ok' => true, 'modo' => $modo, 'numero' => (string) $at->numero_atendimento, 'id' => (int) $at->id] + ($novoPre ? ['pre_cliente_criado' => (int) $pre->id] : []);
    if ($agendar) {
        $faixa = $periodo === 'personalizado' ? ['inicio' => $inicio, 'fim' => $fim] : AgendaTecnicaService::PERIODOS[$periodo];
        // A agenda do Gestor conta em horas inteiras: 08:00–10:30 vira 3 h (até 11:00).
        $horas = max(1, (int) ceil((strtotime('2000-01-01 '.$faixa['fim']) - strtotime('2000-01-01 '.$faixa['inicio'])) / 3600));
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
