<?php

namespace App\Services\Executivo;

use App\Models\MonitorAlerta;
use App\Models\Notificacao;
use App\Models\SuporteAvaliacao;
use App\Models\SuporteConversa;
use App\Models\User;
use App\Models\VigiaErro;
use App\Services\OQueEsperaVoce;
use App\Services\Secretario\AgendaConsolidada;
use App\Services\Secretario\CentralDeGestao;
use App\Services\Secretario\ConsultaDeEmails;
use App\Services\Suporte\IndicadoresDoDia;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Os blocos de leitura da API Executiva (spec `api-executiva-leitura`).
 *
 * Cada bloco é uma camada fina sobre o serviço que a tela já usa — a regra
 * nunca é reescrita aqui — e devolve o `Envelope`: de onde veio, de que período,
 * quando foi consultado e em que situação está a fonte. O que sai é lista
 * branca: título truncado, nome de remetente sem endereço, contagem sem nome de
 * cliente. Nada aqui grava, marca como lido ou chama o Google.
 *
 * Tudo escopado em `$user`, que é sempre o dono do token (`ExecutivoDaConta`).
 */
class Blocos
{
    public const NOMES = ['agenda', 'emails', 'pendencias', 'alertas', 'pedro', 'fontes'];

    /** Título de compromisso e de aviso: o que vem de fora (terceiros) não sai inteiro. */
    private const TITULO = 80;
    private const ASSUNTO = 90;
    private const MAX_DIAS_AGENDA = 14;
    private const MAX_ITENS = 20;

    public function __construct(
        private AgendaConsolidada $agenda,
        private ConsultaDeEmails $emails,
        private OQueEsperaVoce $espera,
        private CentralDeGestao $gestao,
        private IndicadoresDoDia $pedro,
    ) {}

    /** @return array<string, mixed> */
    public function montar(string $nome, User $user, array $parametros = []): array
    {
        return match ($nome) {
            'agenda' => $this->agenda($user, $parametros['de'] ?? null, $parametros['ate'] ?? null),
            'emails' => $this->emails($user),
            'pendencias' => $this->pendencias($user),
            'alertas' => $this->alertas($user),
            'pedro' => $this->pedro($user),
            'fontes' => $this->fontes($user),
        };
    }

    // ───────────────────────── agenda

    /** @return array<string, mixed> */
    public function agenda(User $user, ?string $de = null, ?string $ate = null): array
    {
        $inicio = $de ? CarbonImmutable::parse($de)->startOfDay() : CarbonImmutable::now()->startOfDay();
        $fim = $ate ? CarbonImmutable::parse($ate)->endOfDay() : $inicio->addDay()->endOfDay();
        $periodo = $inicio->format('Y-m-d').' a '.$fim->format('Y-m-d');

        return Envelope::proteger('Agenda consolidada (Matriz, Secretário e Google)', $periodo, function () use ($user, $inicio, $fim, $periodo) {
            $google = $this->agenda->situacaoGoogle($user);
            [$situacao, $motivo] = match ($google['estado']) {
                'ok' => ['ok', null],
                'desatualizado' => ['desatualizado', 'O Google não é lido há mais de '.AgendaConsolidada::DESATUALIZADO_MIN.' min: pode faltar evento.'],
                'reconectar' => ['falha', 'O Google Agenda precisa ser reconectado: os eventos dele não estão aqui.'],
                'desconectado' => ['falha', 'O Google Agenda não está conectado: os eventos dele não estão aqui.'],
                default => ['nao_configurado', 'O Google Agenda não está configurado: só compromissos do Matriz e do Secretário.'],
            };

            $itens = $this->agenda->periodo($user, $inicio, $fim)->take(200)->map(fn ($i) => [
                'origem' => $i['origem'],
                'titulo' => $this->curto((string) $i['titulo'], self::TITULO),
                'inicio' => $i['inicio']->toIso8601String(),
                'fim' => $i['fim']?->toIso8601String(),
                'dia_inteiro' => (bool) $i['dia_inteiro'],
                'conflito' => (bool) $i['conflito'],
            ])->values()->all();

            return Envelope::montar(
                'Agenda consolidada (Matriz, Secretário e Google)', $periodo, $google['sincronizado_em'],
                $situacao, $motivo, ['total' => count($itens), 'itens' => $itens, 'google' => $google['estado']],
            );
        });
    }

    /** No máximo `$max` caracteres contando as reticências — "80 caracteres" é o teto, não 80 mais três. */
    private function curto(string $texto, int $max): string
    {
        return Str::limit($texto, $max - 3);
    }

    // ───────────────────────── e-mails

    /** @return array<string, mixed> */
    public function emails(User $user): array
    {
        $fonte = 'Caixas de e-mail do Secretário (só metadados)';
        $periodo = 'hoje e aguardando resposta dos últimos 7 dias';

        return Envelope::proteger($fonte, $periodo, function () use ($user, $fonte, $periodo) {
            $s = $this->emails->situacaoEmail($user);
            [$situacao, $motivo] = match ($s['estado']) {
                'ok' => ['ok', null],
                'desatualizado' => ['desatualizado', 'Há caixa sem leitura há mais de '.config('services.executivo.email_desatualizado_min', 60).' min: pode faltar mensagem.'],
                'falha' => ['falha', 'Há caixa com erro na última leitura: pode faltar mensagem.'],
                'desconectado' => ['falha', 'Há caixa cadastrada que nunca foi lida.'],
                default => ['nao_configurado', 'Nenhuma caixa de e-mail cadastrada.'],
            };

            if ($s['estado'] === 'nao_configurado') {
                return Envelope::montar($fonte, $periodo, null, $situacao, $motivo, null);
            }

            $dia = $this->emails->importantesDoDia($user, CarbonImmutable::now());

            return Envelope::montar($fonte, $periodo, $s['lida_em'], $situacao, $motivo, [
                'recebidos_hoje' => $dia['todos']->count(),
                'importantes_hoje' => $dia['importantes']->take(self::MAX_ITENS)->map(fn ($e) => $this->email($e))->values()->all(),
                'aguardando_resposta' => $this->emails->aguardandoLista($user)->take(self::MAX_ITENS)->map(fn ($e) => $this->email($e))->values()->all(),
                'caixas' => array_map(fn ($c) => [
                    'rotulo' => $c['rotulo'], 'situacao' => $c['estado'], 'lida_em' => $c['lida_em']?->toIso8601String(),
                ], $s['caixas']),
            ]);
        });
    }

    /** Lista branca: nome do remetente (nunca o endereço), assunto curto, marcas. Sem corpo, anexo ou id de mensagem. */
    private function email($e): array
    {
        return [
            'recebido_em' => $e->recebido_em->toIso8601String(),
            'remetente' => $e->de_nome ?: null,
            'assunto' => Str::limit((string) $e->assunto, self::ASSUNTO),
            'tipo' => $e->tipo,
            'urgente' => $e->urgencia === 'alta',
            'aguardando_resposta' => (bool) ($e->pede_retorno && ! $e->respondido),
            'caixa' => $e->conta?->rotulo,
        ];
    }

    // ───────────────────────── pendências

    /** @return array<string, mixed> */
    public function pendencias(User $user): array
    {
        $fonte = 'Quadro de tarefas e Central de Gestão do Secretário';

        return Envelope::proteger($fonte, 'agora', function () use ($user, $fonte) {
            $agora = CarbonImmutable::now();
            $numeros = $this->gestao->numeros($user, $agora);

            $esperam = $this->espera->podeVer($user)
                ? $this->espera->pendencias($user)->take(self::MAX_ITENS)->map(fn ($p) => [
                    'tipo' => $p['tipo'],
                    'motivo' => Str::limit((string) $p['motivo'], self::TITULO),
                    'nivel' => $p['nivel'] ?? null,
                    'desde' => $p['desde'] ? CarbonImmutable::parse($p['desde'])->toIso8601String() : null,
                    'tarefa' => ['codigo' => '#'.$p['tarefa']->id, 'titulo' => Str::limit((string) $p['tarefa']->titulo, self::TITULO)],
                ])->values()->all()
                : null;

            return Envelope::montar($fonte, 'agora', null, 'ok', $esperam === null ? 'Sem acesso de leitura ao quadro: só os números da Central de Gestão.' : null, [
                'esperam_voce' => $esperam,
                'numeros' => [
                    'compromissos_hoje' => $numeros['compromissos'],
                    'emails_nao_lidos' => $numeros['nao_lidos'],
                    'emails_prioritarios' => $numeros['prioritarios'],
                    'vencidas' => $numeros['vencidas'],
                    'alertas_em_acompanhamento' => $numeros['alertas'],
                ],
            ]);
        });
    }

    // ───────────────────────── alertas

    /** @return array<string, mixed> */
    public function alertas(User $user): array
    {
        $fonte = 'Notificações do sino, vigia de logs e AlfaMonitor';
        $periodo = 'não lidas; erros e alertas das últimas 24 h';

        return Envelope::proteger($fonte, $periodo, function () use ($user, $fonte, $periodo) {
            // Só lê: marcar como lida é decisão da pessoa, em Acompanhamentos.
            $notificacoes = Notificacao::naoLidasDe($user->id)->limit(self::MAX_ITENS)->get()->map(fn ($n) => [
                'tipo' => $n->tipo,
                'nivel' => $n->nivel,
                'titulo' => Str::limit((string) $n->titulo, self::TITULO * 2),
                'criada_em' => $n->created_at?->toIso8601String(),
            ])->all();

            $erros = null;
            $monitor = null;
            $atualizado = null;

            if ($user->canPermissao('manutencao', 'ler')) {
                $desde = now()->subDay();
                $erros = VigiaErro::with('sistema:id,nome')->where('ignorado', false)->where('ultima_vez', '>=', $desde)
                    ->orderByDesc('total')->limit(10)->get()->map(fn ($e) => [
                        'sistema' => $e->sistema?->nome,
                        'ambiente' => $e->ambiente,
                        'nivel' => $e->nivel,
                        'padrao' => Str::limit((string) $e->padrao, 160),
                        'total' => $e->total,
                        'ultima_vez' => $e->ultima_vez->toIso8601String(),
                        'tem_tarefa' => $e->tarefa_id !== null,
                    ])->all();
                $atualizado = VigiaErro::max('ultima_vez');
            }

            if ($user->canPermissao('monitor', 'ler')) {
                $veHostinger = (bool) $user->canPermissao('monitor_hostinger', 'ler');
                $monitor = MonitorAlerta::query()->when(! $veHostinger, fn ($q) => $q->semAssinaturasDaHostinger())
                    ->where('aberto', true)->orderByDesc('caiu_em')->limit(self::MAX_ITENS)->get()->map(fn ($a) => [
                        'nome' => Str::limit($a->nome(), self::TITULO),
                        'tipo' => $a->kind,
                        'severidade' => $a->severity,
                        'caiu_em' => $a->caiu_em?->toIso8601String(),
                    ])->all();
                $ultimo = MonitorAlerta::max('ultimo_evento_em');
                $atualizado = $atualizado && $ultimo ? min($atualizado, $ultimo) : ($atualizado ?: $ultimo);
            }

            return Envelope::montar($fonte, $periodo, $atualizado ? CarbonImmutable::parse($atualizado) : null, 'ok', null, [
                'notificacoes_nao_lidas' => $notificacoes,
                // null = a conta não tem acesso àquela fonte (diferente de "nenhum erro", que é lista vazia).
                'erros_dos_sistemas' => $erros,
                'monitor_abertos' => $monitor,
            ]);
        });
    }

    // ───────────────────────── Pedro

    /** @return array<string, mixed> */
    public function pedro(User $user): array
    {
        $fonte = 'Suporte WhatsApp do Pedro (Matriz)';
        $periodo = 'hoje; conversas das últimas '.SuporteConversa::JANELA_HORAS.' h; avaliações dos últimos 30 dias';

        return Envelope::proteger($fonte, $periodo, function () use ($user, $fonte, $periodo) {
            if (! $user->canPermissao('suporte', 'ler')) {
                return Envelope::montar($fonte, $periodo, null, 'nao_configurado', 'A conta não tem acesso de leitura ao suporte.', null);
            }

            $n = $this->pedro->calcular();
            $maisAntiga = SuporteConversa::where('situacao', 'humano')->whereNotNull('fila_desde')
                ->where('ultima_entrada_em', '>', now()->subHours(SuporteConversa::JANELA_HORAS))->min('fila_desde');
            $recentes = SuporteAvaliacao::whereNotNull('nota')->where('respondida_em', '>=', now()->subDays(30));
            // Só contagens e idade: nem nome de cliente, nem telefone, nem texto de conversa.
            $dados = [
                'na_fila' => $n['na_fila'],
                'fila_mais_antiga_min' => $maisAntiga ? (int) CarbonImmutable::parse($maisAntiga)->diffInMinutes(now(), true) : null,
                'esperando_resposta' => $n['esperando'],
                'com_pedro' => $n['com_pedro'],
                'com_humano' => $n['com_humano'],
                'recebidas_hoje' => $n['recebidas_hoje'],
                'atendimentos_abertos_hoje' => $n['atendimentos_hoje'],
                'primeira_resposta_seg' => $n['primeira_resposta'],
                'avaliacoes_30d' => [
                    'respondidas' => (int) ($n['avaliacao']->n ?? 0),
                    'nota_media' => $n['avaliacao'] && $n['avaliacao']->media !== null ? round((float) $n['avaliacao']->media, 2) : null,
                    'ruins' => (clone $recentes)->where('nota', '<=', 2)->count(),
                ],
            ];

            return Envelope::montar($fonte, $periodo, CarbonImmutable::parse(SuporteConversa::max('updated_at') ?? now()), 'ok', null, $dados);
        });
    }

    // ───────────────────────── fontes

    /** O painel de saúde: cada fonte, sua situação e o motivo — sem ler o conteúdo delas. @return array<string, mixed> */
    public function fontes(User $user): array
    {
        return Envelope::proteger('Saúde das fontes da API Executiva', null, function () use ($user) {
            $google = $this->agenda->situacaoGoogle($user);
            $email = $this->emails->situacaoEmail($user);

            $linha = fn (string $fonte, string $situacao, ?CarbonInterface $em, ?string $motivo = null) => [
                'fonte' => $fonte, 'situacao' => $situacao, 'atualizado_em' => $em?->toIso8601String(), 'motivo' => $motivo,
            ];

            $fontes = [
                $linha('google_agenda', match ($google['estado']) {
                    'ok' => 'ok', 'desatualizado' => 'desatualizado', 'nao_configurado' => 'nao_configurado', default => 'falha',
                }, $google['sincronizado_em'], $google['estado'] === 'ok' ? null : $google['estado']),
                $linha('emails', match ($email['estado']) {
                    'ok' => 'ok', 'desatualizado' => 'desatualizado', 'nao_configurado' => 'nao_configurado', default => 'falha',
                }, $email['lida_em'], $email['estado'] === 'ok' ? null : $email['estado']),
                $linha('quadro', 'ok', null),
                $linha('pedro', $user->canPermissao('suporte', 'ler') ? 'ok' : 'nao_configurado', null),
            ];

            return Envelope::montar('Saúde das fontes da API Executiva', null, null, 'ok', null, ['fontes' => $fontes]);
        });
    }
}
