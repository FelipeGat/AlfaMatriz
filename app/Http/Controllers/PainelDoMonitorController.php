<?php

namespace App\Http\Controllers;

use App\Models\MonitorAlerta;
use App\Models\MonitorEvento;
use App\Models\Sistema;
use App\Services\Monitor\ApiDoMonitor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * As telas do AlfaMonitor no Matriz (#394, decisão do dono em 17/10/2026).
 *
 * Sete telas, todas SÓ DE LEITURA: Geral, Acesso, Servidor, Clientes, a
 * página do cliente, Incidentes e Relatórios. O que age — reiniciar, parar e
 * ligar VPS, o sino, os cadastros — fica no monitor; e a rede, as sondas e as
 * câmeras ficam de fora de tudo, por decisão do dono.
 *
 * O dado vem da API de leitura do monitor (`ApiDoMonitor`), uma rota por
 * tela, com cache e o último dado bom para quando ele não responder. A
 * exceção é a aba "Alertas recebidos" dos Incidentes: ela é o que o webhook
 * guardou AQUI (`monitor_alertas`), porque servidor, fonte e Hostinger só
 * chegam por ele — a tabela de incidentes do monitor só tem disponibilidade e
 * aparelhos.
 *
 * A Hostinger tem porta própria (`monitor_hostinger`): "uma pessoa pode ter
 * acesso à parte de monitoramento mas não à de assinaturas" (o dono). Quem
 * não a tem nem dispara a consulta da rota `/hostinger`, e não vê os alertas
 * de vencimento — o dado não chega ao HTML dele.
 */
class PainelDoMonitorController extends Controller
{
    public const FONTES = ['AlfaControl', 'AlfaGym'];

    public const SITUACOES_DE_APARELHO = ['todos', 'online', 'offline'];

    public const JANELAS = ['24h' => '24 horas', '7d' => '7 dias', '30d' => '30 dias'];

    public const DIAS_DE_HISTORICO = [1, 7, 30];

    /** Os recortes da aba de alertas recebidos, em dias (o histórico guarda 90). */
    public const PERIODOS_DOS_ALERTAS = [7, 30, 90];

    public const EVENTOS_POR_PAGINA = 30;

    public function __construct(private readonly ApiDoMonitor $api) {}

    public function geral(Request $request): View
    {
        $this->bloquearVisaoDaMatriz();

        return $this->tela('geral', [
            'resposta' => $this->api->buscar('visao-geral'),
            'hostinger' => $this->veHostinger($request) ? $this->api->buscar('hostinger') : null,
        ]);
    }

    public function acesso(Request $request): View
    {
        $this->bloquearVisaoDaMatriz();

        $filtros = [
            'fonte' => in_array($request->query('fonte'), self::FONTES, true) ? $request->query('fonte') : self::FONTES[0],
            'situacao' => in_array($request->query('situacao'), self::SITUACOES_DE_APARELHO, true) ? $request->query('situacao') : 'todos',
            'busca' => trim((string) $request->query('busca', '')),
        ];

        $resposta = $this->api->buscar('acesso', ['fonte' => $filtros['fonte']]);
        $fonte = collect($resposta->get('fontes', []))->firstWhere('fonte', $filtros['fonte']);

        // Busca e filtro aqui, sobre a resposta inteira: são 42 aparelhos
        // hoje (o monitor conferiu), e a API não pagina por isso mesmo. Cada
        // cliente fica com os aparelhos que passam; cliente sem nenhum sai.
        $clientes = collect($fonte['clientes'] ?? [])
            ->map(function (array $cliente) use ($filtros) {
                $nomeCasa = $filtros['busca'] === '' || $this->contem($cliente['nome'] ?? '', $filtros['busca']);

                $cliente['aparelhos'] = collect($cliente['aparelhos'] ?? [])
                    ->filter(fn (array $a) => $filtros['situacao'] === 'todos' || ($a['estado'] ?? null) === $filtros['situacao'])
                    ->filter(fn (array $a) => $nomeCasa
                        || $this->contem(($a['nome'] ?? '').' '.($a['modelo'] ?? '').' '.($a['ip'] ?? ''), $filtros['busca']))
                    ->values()
                    ->all();

                return $cliente;
            })
            ->filter(fn (array $cliente) => $cliente['aparelhos'] !== [])
            ->values();

        return $this->tela('acesso', [
            'resposta' => $resposta,
            'filtros' => $filtros,
            'fonte' => $fonte,
            'clientes' => $clientes,
        ]);
    }

    public function servidor(string $slug): View
    {
        $this->bloquearVisaoDaMatriz();

        $resposta = $this->api->buscar('servidores/'.$slug);

        abort_if($resposta->naoEncontrado, 404, 'O AlfaMonitor não conhece este servidor.');

        return $this->tela('servidor', ['resposta' => $resposta]);
    }

    public function clientes(Request $request): View
    {
        $this->bloquearVisaoDaMatriz();

        $busca = trim((string) $request->query('busca', ''));
        $resposta = $this->api->buscar('clientes');

        $ordem = ['crit' => 0, 'warn' => 1, 'ok' => 2];

        // Quem tem problema primeiro, como no "Atenção" da Geral; dentro da
        // gravidade, por nome.
        $clientes = collect($resposta->get('clientes', []))
            ->filter(fn (array $c) => $busca === '' || $this->contem($c['nome'] ?? '', $busca))
            ->sortBy([
                fn ($a, $b) => ($ordem[$a['sev'] ?? ''] ?? 9) <=> ($ordem[$b['sev'] ?? ''] ?? 9),
                fn ($a, $b) => strcmp((string) ($a['nome'] ?? ''), (string) ($b['nome'] ?? '')),
            ])
            ->values();

        return $this->tela('clientes', [
            'resposta' => $resposta,
            'clientes' => $clientes,
            'busca' => $busca,
        ]);
    }

    public function cliente(string $id): View
    {
        $this->bloquearVisaoDaMatriz();

        $resposta = $this->api->buscar('clientes/'.$id);

        abort_if($resposta->naoEncontrado, 404, 'O AlfaMonitor não conhece este cliente.');

        return $this->tela('cliente', ['resposta' => $resposta]);
    }

    public function incidentes(Request $request): View
    {
        $this->bloquearVisaoDaMatriz();

        if ($request->query('origem') === 'alertas') {
            return $this->tela('incidentes', ['origem' => 'alertas'] + $this->alertasRecebidos($request));
        }

        $filtros = [
            'estado' => $request->query('estado') === 'historico' ? 'historico' : 'aberto',
            'dias' => in_array((int) $request->query('dias'), self::DIAS_DE_HISTORICO, true) ? (int) $request->query('dias') : 7,
            'sev' => in_array($request->query('sev'), ['crit', 'warn'], true) ? $request->query('sev') : '',
            'visao' => $request->query('visao') === 'cliente' ? 'cliente' : 'lista',
        ];

        $resposta = $this->api->buscar('incidentes', [
            'estado' => $filtros['estado'],
            'dias' => $filtros['estado'] === 'historico' ? $filtros['dias'] : null,
            'sev' => $filtros['sev'] ?: null,
        ]);

        $incidentes = collect($resposta->get('incidentes', []))
            ->sortByDesc(fn (array $i) => $i['inicio'] ?? '')
            ->values();

        return $this->tela('incidentes', [
            'origem' => 'monitor',
            'resposta' => $resposta,
            'filtros' => $filtros,
            'incidentes' => $incidentes,
            'porCliente' => $incidentes
                ->groupBy(fn (array $i) => $i['cliente']['nome'] ?? 'Sem cliente (disponibilidade)')
                ->sortKeys(),
        ]);
    }

    public function relatorio(Request $request): View
    {
        $this->bloquearVisaoDaMatriz();

        $janela = array_key_exists($request->query('janela'), self::JANELAS) ? $request->query('janela') : '24h';

        $resposta = $this->api->buscar(
            'relatorio',
            ['janela' => $janela],
            // 24h fica sempre pronta lá; 7d e 30d custam ~8 s quando vencem.
            $janela === '24h' ? ApiDoMonitor::CACHE_VIVO_S : ApiDoMonitor::CACHE_RELATORIO_S,
            ApiDoMonitor::TIMEOUT_RELATORIO_S,
        );

        return $this->tela('relatorio', ['resposta' => $resposta, 'janela' => $janela]);
    }

    public function exportar(Request $request): Response
    {
        $this->bloquearVisaoDaMatriz();

        $janela = array_key_exists($request->query('janela'), self::JANELAS) ? $request->query('janela') : '24h';
        $csv = $this->api->exportarRelatorio($janela);

        abort_if($csv === null, 503, 'O AlfaMonitor não respondeu; tente exportar de novo em instantes.');

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="alfamonitor-relatorio-'.$janela.'-'.now()->format('Y-m-d').'.csv"',
        ]);
    }

    /**
     * A aba "Alertas recebidos": o que o webhook do monitor empurrou e o
     * Matriz guardou. Nasceu como a aba Monitor da Manutenção (fase 1) e
     * mudou para cá quando o Monitor ganhou item próprio no menu.
     *
     * @return array<string, mixed>
     */
    private function alertasRecebidos(Request $request): array
    {
        $veHostinger = $this->veHostinger($request);

        $filtros = [
            'kind' => array_key_exists($request->query('kind'), MonitorAlerta::KINDS) ? $request->query('kind') : '',
            'periodo' => in_array((int) $request->query('periodo'), self::PERIODOS_DOS_ALERTAS, true) ? (int) $request->query('periodo') : 7,
            'lembretes' => $request->boolean('lembretes'),
        ];

        $rank = array_flip(array_keys(MonitorAlerta::SEVERIDADES));

        $alertas = MonitorAlerta::query()
            ->when(! $veHostinger, fn ($q) => $q->semAssinaturasDaHostinger());

        $agora = (clone $alertas)
            ->with('clienteDoMatriz')
            ->where('aberto', true)
            ->when($filtros['kind'], fn ($q, $kind) => $q->where('kind', $kind))
            ->get()
            // Crítico primeiro e, dentro da gravidade, quem caiu antes: o que
            // está fora há mais tempo é o que mais pede olho.
            ->sortBy([
                fn ($a, $b) => ($rank[$a->severity] ?? 9) <=> ($rank[$b->severity] ?? 9),
                fn ($a, $b) => $a->caiu_em <=> $b->caiu_em,
            ])
            ->values();

        $historico = MonitorEvento::query()
            ->with('alerta')
            ->whereIn('monitor_alerta_id', (clone $alertas)->select('id'))
            ->when($filtros['kind'], fn ($q, $kind) => $q->whereHas('alerta', fn ($a) => $a->where('kind', $kind)))
            ->when(! $filtros['lembretes'], fn ($q) => $q->where('derived', false))
            ->where('ocorreu_em', '>=', now()->subDays($filtros['periodo'])->format('Y-m-d H:i:s'))
            ->orderByDesc('ocorreu_em')
            ->orderByDesc('event_id')
            ->paginate(self::EVENTOS_POR_PAGINA)
            ->withQueryString();

        // Quanto tempo ficou fora: a volta aponta a queda pelo incident_id.
        $quedas = MonitorEvento::query()
            ->whereIn('event_id', $historico->getCollection()->where('resolved', true)->pluck('incident_id')->filter())
            ->pluck('ocorreu_em', 'event_id');

        $ultimo = MonitorEvento::query()->latest('id')->first();
        $abertos = (clone $alertas)->where('aberto', true);
        $eventos = MonitorEvento::query()->where('derived', false)->whereIn('monitor_alerta_id', (clone $alertas)->select('id'));

        return [
            'filtros' => $filtros,
            'agora' => $agora,
            'historico' => $historico,
            'quedas' => $quedas,
            // Se o monitor está ligado (tem token) e quando falou por último:
            // tela vazia com o monitor mudo há dias não é boa notícia.
            'ligado' => Sistema::whereNotNull('monitor_token_hash')->exists(),
            'ultimoRecebido' => $ultimo?->created_at,
            'kpis' => [
                'fora' => (clone $abertos)->count(),
                'criticos' => (clone $abertos)->where('severity', 'critical')->count(),
                'quedas7d' => (clone $eventos)->where('resolved', false)
                    ->where('ocorreu_em', '>=', now()->subDays(7)->format('Y-m-d H:i:s'))->count(),
                'voltas24h' => (clone $eventos)->where('resolved', true)
                    ->where('ocorreu_em', '>=', now()->subDay()->format('Y-m-d H:i:s'))->count(),
            ],
        ];
    }

    private function veHostinger(Request $request): bool
    {
        return (bool) $request->user()?->canPermissao('monitor_hostinger', 'ler');
    }

    /** Sem acento nem caixa: "colegio" acha "COLÉGIO". */
    private function contem(string $texto, string $busca): bool
    {
        $normalizar = fn (string $s) => mb_strtolower(Str::ascii($s));

        return str_contains($normalizar($texto), $normalizar($busca));
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function tela(string $tela, array $dados): View
    {
        return view('monitor.'.$tela, ['tela' => $tela] + $dados);
    }
}
