<?php

namespace App\Services\Vigia;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\TarefaEvento;
use App\Models\User;
use App\Models\VigiaErro;
use App\Models\VigiaErroHora;
use App\Models\VigiaIgnorado;
use App\Services\AvisoNoTelegram;
use App\Services\TarefaService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * O que o AlfaMatriz faz com os erros que o servidor de um sistema manda (#219).
 *
 * Nasceu do erro do Wellhub (#191): 36 de 36 check-ins falhando, um WARN por
 * falha, e semanas até alguém perceber. O script `deploy/vigia-logs/` manda
 * de hora em hora o que apareceu no log; aqui o lote vira:
 *
 * - ERRO NOVO (assinatura nunca vista) → tarefa de Bug no quadro, na fila
 *   Aberta, e aviso no Telegram. A tarefa passa pelo `TarefaService::criar`,
 *   como a da tela e a do MCP: o Bug exige o relato de quem e quando, e o
 *   vigia o cumpre ("Vigia de logs (automático)", a primeira ocorrência). Sem
 *   triagem, ela nasce sem dono e "A definir" — a régua é a de quem não triaga.
 * - ERRO REPETIDO com tarefa em curso → só conta. Um comentário por dia, no
 *   máximo, com a contagem: o lote chega de hora em hora, e um comentário por
 *   lote enterraria a conversa da tarefa em 24 linhas iguais por dia.
 * - ERRO QUE VOLTOU depois de a tarefa ser encerrada → tarefa NOVA e aviso.
 *   Reabrir a antiga reescreveria o histórico de quem a deu por resolvida; a
 *   nova cita a anterior.
 * - PICO: na hora, 10× a média das últimas 24h (e pelo menos 20) → aviso, no
 *   máximo um a cada 6h por erro.
 * - IGNORADO (`vigia_ignorados`) → só conta.
 *
 * A regra mora num serviço, e não no controller, pela mesma razão do resto do
 * quadro: se um dia o vigia ganhar outra porta (uma ferramenta MCP, uma fila),
 * as duas abrem a mesma tarefa.
 */
class VigiaDeLogs
{
    public const AMBIENTES = ['producao' => 'produção', 'staging' => 'staging'];

    /** Quantos erros cabem num lote. Acima disso a rota responde 413 e o script divide. */
    public const MAX_ERROS_POR_LOTE = 500;

    /**
     * O tamanho de cada campo de um erro. O excedente é CORTADO, e não
     * recusado: um stack trace de 300 linhas não pode fazer o erro sumir —
     * as primeiras linhas são as que dizem onde foi.
     */
    public const LIMITES = [
        'mensagem' => 2000,
        'excecao' => 255,
        'trecho' => 4000,
        'nivel' => 20,
    ];

    public const QUEM = 'Vigia de logs (automático)';

    public const PICO_FATOR = 10;

    public const PICO_MINIMO = 20;

    public const PICO_INTERVALO_HORAS = 6;

    /**
     * Quantos avisos um lote manda ao Telegram antes de resumir o resto numa
     * mensagem só. Uma publicação quebrada faz dez erros novos de uma vez, e
     * dez mensagens seguidas no grupo viram ruído que ninguém lê — além de
     * esbarrar no limite do Telegram por grupo.
     */
    public const MAX_AVISOS_POR_LOTE = 5;

    /** Horas de contagem guardadas: 24h para a média do pico, e folga para investigar. */
    public const DIAS_DE_HORAS_GUARDADAS = 8;

    /** @var list<string> */
    private array $avisos = [];

    public function __construct(
        private readonly TarefaService $tarefas,
        private readonly AvisoNoTelegram $telegram,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $erros  já validados pela rota
     * @return array{recebidos: int, assinaturas: int, tarefas_abertas: list<string>, comentadas: list<string>, picos: int, ignorados: int}
     */
    public function receber(Sistema $sistema, string $ambiente, ?string $origem, array $erros): array
    {
        $this->avisos = [];

        $resumo = [
            'recebidos' => count($erros),
            'assinaturas' => 0,
            'tarefas_abertas' => [],
            'comentadas' => [],
            'picos' => 0,
            'ignorados' => 0,
        ];

        $ignorados = VigiaIgnorado::query()
            ->where(fn ($q) => $q->whereNull('sistema_id')->orWhere('sistema_id', $sistema->id))
            ->get();

        foreach ($this->agrupar($erros) as $grupo) {
            $resumo['assinaturas']++;

            $resultado = $this->comRetentativa(fn () => DB::transaction(
                fn () => $this->tratarGrupo($sistema, $ambiente, $origem, $grupo, $ignorados)
            ));

            if ($resultado['ignorado']) {
                $resumo['ignorados']++;
            }

            if ($resultado['tarefa_aberta']) {
                $resumo['tarefas_abertas'][] = $resultado['tarefa_aberta'];
            }

            if ($resultado['comentada']) {
                $resumo['comentadas'][] = $resultado['comentada'];
            }

            $resumo['picos'] += $resultado['pico'] ? 1 : 0;
        }

        VigiaErroHora::where('hora', '<', now()->subDays(self::DIAS_DE_HORAS_GUARDADAS)->format('Y-m-d H:i:s'))->delete();

        // Depois das transações: o aviso fala de uma tarefa que precisa
        // existir quando alguém clicar no link.
        $this->despacharAvisos($sistema, $ambiente);

        return $resumo;
    }

    /**
     * Corta cada campo no limite. Fica aqui, e não na rota, para quem um dia
     * chamar o serviço por outra porta cortar igual.
     *
     * @param  array<string, mixed>  $erro
     * @return array<string, mixed>
     */
    public static function cortar(array $erro): array
    {
        foreach (self::LIMITES as $campo => $limite) {
            if (isset($erro[$campo]) && is_string($erro[$campo]) && mb_strlen($erro[$campo]) > $limite) {
                $erro[$campo] = mb_substr($erro[$campo], 0, $limite - 1).'…';
            }
        }

        return $erro;
    }

    /**
     * Junta o lote por assinatura ANTES de tocar no banco: 36 ocorrências
     * iguais num lote são uma linha atualizada uma vez, não 36.
     *
     * @param  list<array<string, mixed>>  $erros
     * @return Collection<string, array<string, mixed>>
     */
    private function agrupar(array $erros): Collection
    {
        $grupos = [];

        foreach ($erros as $erro) {
            $erro = self::cortar($erro);
            $assinatura = new AssinaturaDeErro(
                (string) $erro['mensagem'],
                $erro['excecao'] ?? null,
                $erro['trecho'] ?? null,
            );
            $quando = $this->quando($erro['quando'] ?? null);
            $hora = $quando->copy()->startOfHour()->format('Y-m-d H:i:s');

            $grupo = $grupos[$assinatura->hash] ??= [
                'assinatura' => $assinatura,
                'exemplo' => $erro,
                'quantos' => 0,
                'primeira' => $quando,
                'ultima' => $quando,
                'horas' => [],
            ];

            $grupo['quantos']++;
            $grupo['primeira'] = $quando->lt($grupo['primeira']) ? $quando : $grupo['primeira'];
            $grupo['ultima'] = $quando->gt($grupo['ultima']) ? $quando : $grupo['ultima'];
            $grupo['horas'][$hora] = ($grupo['horas'][$hora] ?? 0) + 1;

            $grupos[$assinatura->hash] = $grupo;
        }

        return collect($grupos);
    }

    /**
     * O momento da ocorrência, no fuso daqui. Sem data legível, ou no futuro
     * (relógio do servidor adiantado), vale agora: o erro aconteceu, e
     * descartá-lo por causa do relógio seria perder justamente o aviso.
     */
    private function quando(mixed $valor): Carbon
    {
        try {
            $quando = filled($valor) ? Carbon::parse((string) $valor)->setTimezone(config('app.timezone')) : now();
        } catch (\Throwable) {
            return now();
        }

        return $quando->gt(now()) ? now() : $quando;
    }

    /**
     * @param  array<string, mixed>  $grupo
     * @param  Collection<int, VigiaIgnorado>  $ignorados
     * @return array{ignorado: bool, tarefa_aberta: ?string, comentada: ?string, pico: bool}
     */
    private function tratarGrupo(Sistema $sistema, string $ambiente, ?string $origem, array $grupo, Collection $ignorados): array
    {
        /** @var AssinaturaDeErro $assinatura */
        $assinatura = $grupo['assinatura'];
        $exemplo = $grupo['exemplo'];

        $erro = VigiaErro::query()
            ->where('sistema_id', $sistema->id)
            ->where('ambiente', $ambiente)
            ->where('assinatura', $assinatura->hash)
            ->lockForUpdate()
            ->first();

        if (! $erro) {
            $erro = new VigiaErro([
                'sistema_id' => $sistema->id,
                'ambiente' => $ambiente,
                'assinatura' => $assinatura->hash,
                'padrao' => $assinatura->padrao,
                'nivel' => strtoupper((string) ($exemplo['nivel'] ?? 'ERROR')),
                'excecao' => $assinatura->excecao,
                'mensagem' => (string) $exemplo['mensagem'],
                'trecho' => $exemplo['trecho'] ?? null,
                'origem' => $origem,
                'primeira_vez' => $grupo['primeira'],
                'ultima_vez' => $grupo['ultima'],
                'total' => 0,
            ]);
        } else {
            $erro->primeira_vez = $grupo['primeira']->lt($erro->primeira_vez) ? $grupo['primeira'] : $erro->primeira_vez;
            $erro->ultima_vez = $grupo['ultima']->gt($erro->ultima_vez) ? $grupo['ultima'] : $erro->ultima_vez;
        }

        $erro->total += $grupo['quantos'];
        // Recalculado a cada lote: tirar um padrão da lista faz o erro voltar
        // a ser vigiado no lote seguinte, sem ninguém mexer na linha dele.
        $erro->ignorado = $this->casaComIgnorado($erro, $ignorados);
        $erro->save();

        foreach ($grupo['horas'] as $hora => $quantos) {
            $linha = VigiaErroHora::firstOrNew(['vigia_erro_id' => $erro->id, 'hora' => $hora]);
            $linha->total = ($linha->total ?? 0) + $quantos;
            $linha->save();
        }

        $resultado = ['ignorado' => $erro->ignorado, 'tarefa_aberta' => null, 'comentada' => null, 'pico' => false];

        if ($erro->ignorado) {
            return $resultado;
        }

        $tarefa = $erro->tarefa;

        // Sem tarefa — ou com a tarefa excluída do quadro: é erro novo para o
        // quadro, ainda que o vigia já o conhecesse (estava ignorado e saiu da
        // lista, por exemplo).
        if (! $tarefa || $tarefa->trashed()) {
            $nova = $this->abrirTarefa($erro, $grupo, $sistema);
            $resultado['tarefa_aberta'] = $nova?->codigo();

            return $resultado;
        }

        if (in_array($tarefa->status, Tarefa::STATUS_TERMINAIS, true)) {
            // Só VOLTOU o que aconteceu depois do encerramento: um lote
            // atrasado trazendo ocorrências de antes não reabre nada.
            if ($grupo['ultima']->gt($this->encerradaEm($tarefa))) {
                $nova = $this->abrirTarefa($erro, $grupo, $sistema, anterior: $tarefa);
                $resultado['tarefa_aberta'] = $nova?->codigo();
            }

            return $resultado;
        }

        if ($this->deveComentar($erro, $tarefa)) {
            $this->comentarContagem($erro, $tarefa);
            $resultado['comentada'] = $tarefa->codigo();
        }

        $resultado['pico'] = $this->conferirPico($erro, $grupo, $sistema, $ambiente);

        return $resultado;
    }

    /**
     * Duas máquinas do mesmo sistema mandando o mesmo erro novo no mesmo
     * segundo: a segunda esbarra no índice único. Uma segunda tentativa já
     * encontra a linha da primeira e só soma.
     */
    private function comRetentativa(callable $passo): array
    {
        try {
            return $passo();
        } catch (UniqueConstraintViolationException) {
            return $passo();
        }
    }

    /** @param  Collection<int, VigiaIgnorado>  $ignorados */
    private function casaComIgnorado(VigiaErro $erro, Collection $ignorados): bool
    {
        $texto = trim(($erro->excecao ? $erro->excecao.': ' : '').$erro->mensagem);

        return $ignorados->contains(fn (VigiaIgnorado $padrao) => $padrao->casaCom($texto) || $padrao->casaCom($erro->padrao));
    }

    /**
     * O comentário diário só cai na tarefa EM CURSO e fora do arquivo.
     *
     * Arquivada fica de fora: o vigia é quem abriu a tarefa, e o comentário de
     * quem abriu desarquiva sozinho (`TarefaComentario::booted`, #208) — a
     * decisão da triagem de tirar o card do quadro seria desfeita todo dia.
     * Para calar um erro de vez, o caminho é a lista do que ignorar.
     */
    private function deveComentar(VigiaErro $erro, Tarefa $tarefa): bool
    {
        if ($tarefa->estaArquivada()) {
            return false;
        }

        return $erro->comentado_em === null || $erro->comentado_em->lte(now()->subDay());
    }

    private function comentarContagem(VigiaErro $erro, Tarefa $tarefa): void
    {
        $ultimas24h = (int) $erro->horas()
            ->where('hora', '>=', now()->subDay()->startOfHour()->format('Y-m-d H:i:s'))
            ->sum('total');

        $tarefa->comentarios()->create([
            'autor_id' => User::vigiaDeLogs()->id,
            'corpo' => sprintf(
                'O erro continua aparecendo no log (%s): %s nas últimas 24 horas, %s no total desde %s. Última vez: %s.',
                self::AMBIENTES[$erro->ambiente] ?? $erro->ambiente,
                self::vezes($ultimas24h),
                self::vezes($erro->total),
                $erro->primeira_vez->format('d/m/Y H:i'),
                $erro->ultima_vez->format('d/m/Y H:i'),
            ),
        ]);

        $erro->forceFill(['comentado_em' => now()])->save();
    }

    /**
     * @param  array<string, mixed>  $grupo
     */
    private function abrirTarefa(VigiaErro $erro, array $grupo, Sistema $sistema, ?Tarefa $anterior = null): ?Tarefa
    {
        $vigia = User::vigiaDeLogs();
        $ambiente = self::AMBIENTES[$erro->ambiente] ?? $erro->ambiente;
        $mensagem = self::primeiraLinha($erro->mensagem);
        $desde = $anterior ? $grupo['primeira'] : $erro->primeira_vez;
        $vezes = $anterior ? $grupo['quantos'] : $erro->total;

        $resumo = $anterior
            ? sprintf('Este erro voltou ao log do %s (%s) depois de a %s ser encerrada: %s desde %s. O que apareceu: «%s».',
                $sistema->nome, $ambiente, $anterior->codigo(), self::vezes($vezes), $desde->format('d/m/Y H:i'), $mensagem)
            : sprintf('O vigia de logs encontrou um erro novo no %s (%s): %s desde %s. O que apareceu: «%s».',
                $sistema->nome, $ambiente, self::vezes($vezes), $desde->format('d/m/Y H:i'), $mensagem);

        $tarefa = $this->tarefas->criar([
            'titulo' => Str::limit(($anterior ? 'Erro voltou no log: ' : 'Erro no log: ').$mensagem, 150),
            'resumo' => Str::limit($resumo.' O trecho do erro está no primeiro comentário.', 500),
            'tipo' => 'bug',
            'sistema_id' => $sistema->id,
            'defeito_quem' => self::QUEM,
            'defeito_quando' => $desde->format('Y-m-d H:i:s'),
        ], $vigia);

        if (! $tarefa) {
            return null;
        }

        // O detalhe vai num comentário, e não no resumo: o resumo tem 500
        // caracteres e é o que o card mostra; o trecho do stack é longo e é
        // para quem vai investigar.
        $tarefa->comentarios()->create([
            'autor_id' => $vigia->id,
            'corpo' => Str::limit(implode("\n", array_filter([
                'O que apareceu no log:',
                $erro->mensagem,
                '',
                $erro->excecao ? 'Exceção: '.$erro->excecao : null,
                'Nível: '.$erro->nivel.' · Ambiente: '.$ambiente.($erro->origem ? ' · Origem: '.$erro->origem : ''),
                sprintf('Vezes: %s (de %s a %s)', self::vezes($vezes), $desde->format('d/m/Y H:i'), $grupo['ultima']->format('d/m/Y H:i')),
                $anterior ? 'A tarefa anterior deste mesmo erro é a '.$anterior->codigo().'.' : null,
                $erro->trecho ? "\nTrecho:\n".$erro->trecho : null,
                '',
                'Se este erro não precisa de conserto, ele pode entrar na lista do que o vigia ignora: continua contado, mas não abre tarefa nem avisa.',
            ], fn ($linha) => $linha !== null)), 6000),
        ]);

        $erro->forceFill(['tarefa_id' => $tarefa->id, 'comentado_em' => now()])->save();

        $this->avisos[] = $this->mensagemDeTarefa($erro, $tarefa, $sistema, $vezes, $desde, $anterior);

        return $tarefa;
    }

    /** O instante em que a tarefa chegou à etapa terminal em que está. */
    private function encerradaEm(Tarefa $tarefa): Carbon
    {
        $entrada = TarefaEvento::where('tarefa_id', $tarefa->id)
            ->where('para_status', $tarefa->status)
            ->latest('entrou_em')
            ->value('entrou_em');

        return $entrada ? Carbon::parse($entrada) : $tarefa->updated_at;
    }

    /**
     * Pico na hora mais recente que o lote tocou: 10× a média das 24 horas
     * ANTERIORES a ela, com um piso de 20 — sem o piso, um erro que aparece
     * uma vez por dia "dispararia" na segunda ocorrência.
     *
     * @param  array<string, mixed>  $grupo
     */
    private function conferirPico(VigiaErro $erro, array $grupo, Sistema $sistema, string $ambiente): bool
    {
        if ($erro->pico_avisado_em && $erro->pico_avisado_em->gt(now()->subHours(self::PICO_INTERVALO_HORAS))) {
            return false;
        }

        $hora = Carbon::parse(collect(array_keys($grupo['horas']))->max());
        $formato = 'Y-m-d H:i:s';

        $naHora = (int) $erro->horas()->where('hora', $hora->format($formato))->value('total');

        $media = $erro->horas()
            ->where('hora', '>=', $hora->copy()->subDay()->format($formato))
            ->where('hora', '<', $hora->format($formato))
            ->sum('total') / 24;

        if ($naHora < self::PICO_MINIMO || $naHora < self::PICO_FATOR * $media) {
            return false;
        }

        $erro->forceFill(['pico_avisado_em' => now()])->save();

        $this->avisos[] = implode("\n", array_filter([
            '<b>🟠 Pico de erro no log</b> — '.e($sistema->nome).' · '.e(self::AMBIENTES[$ambiente] ?? $ambiente),
            '<code>'.e(Str::limit(self::primeiraLinha($erro->mensagem), 300)).'</code>',
            $erro->excecao ? '<i>'.e(class_basename(str_replace('.', '\\', $erro->excecao))).'</i>' : null,
            sprintf('%s às %s (a média era %s por hora)', self::vezes($naHora), $hora->format('H\h'), number_format($media, 1, ',', '.')),
            $erro->tarefa ? '<a href="'.e(self::linkDaTarefa($erro->tarefa)).'">'.$erro->tarefa->codigo().' no quadro</a>' : null,
        ]));

        return true;
    }

    private function mensagemDeTarefa(VigiaErro $erro, Tarefa $tarefa, Sistema $sistema, int $vezes, Carbon $desde, ?Tarefa $anterior): string
    {
        return implode("\n", array_filter([
            ($anterior ? '<b>🔴 Erro voltou no log</b>' : '<b>🔴 Erro novo no log</b>')
                .' — '.e($sistema->nome).' · '.e(self::AMBIENTES[$erro->ambiente] ?? $erro->ambiente),
            '<code>'.e(Str::limit(self::primeiraLinha($erro->mensagem), 300)).'</code>',
            $erro->excecao ? '<i>'.e(class_basename(str_replace('.', '\\', $erro->excecao))).'</i>' : null,
            self::vezes($vezes).' desde '.$desde->format('d/m H:i')
                .($anterior ? ' · a '.$anterior->codigo().' estava encerrada' : ''),
            '<a href="'.e(self::linkDaTarefa($tarefa)).'">'.$tarefa->codigo().' no quadro</a>',
        ]));
    }

    private function despacharAvisos(Sistema $sistema, string $ambiente): void
    {
        $avisos = array_values(array_filter($this->avisos));

        foreach (array_slice($avisos, 0, self::MAX_AVISOS_POR_LOTE) as $aviso) {
            $this->telegram->enviar($aviso);
        }

        $sobra = count($avisos) - self::MAX_AVISOS_POR_LOTE;

        if ($sobra > 0) {
            $this->telegram->enviar(sprintf(
                '<b>🔴 E mais %d aviso(s) do vigia</b> — %s · %s. Veja as tarefas abertas pelo Vigia de logs no quadro.',
                $sobra,
                e($sistema->nome),
                e(self::AMBIENTES[$ambiente] ?? $ambiente),
            ));
        }

        $this->avisos = [];
    }

    /**
     * O link pelo endereço do PAINEL (`APP_URL`), e não pelo da requisição: o
     * lote pode chegar por um endereço que só expõe a rota do vigia, e o link
     * da tarefa precisa abrir o quadro.
     */
    public static function linkDaTarefa(Tarefa $tarefa): string
    {
        return rtrim((string) config('app.url'), '/').route('tarefas.index', ['tarefa' => $tarefa->id], false);
    }

    private static function primeiraLinha(string $texto): string
    {
        return Str::limit(trim(strtok($texto, "\n") ?: $texto), 200);
    }

    private static function vezes(int $quantas): string
    {
        return $quantas === 1 ? '1 vez' : number_format($quantas, 0, ',', '.').' vezes';
    }
}
