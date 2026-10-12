<?php

namespace App\Services\CentralIa;

use App\Models\CentralIaEntrega;
use App\Models\CentralIaExecucao;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaTarefa;
use App\Models\CentralIaTelegramVinculo;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * O Telegram como segundo canal da conversa: parear, receber e entregar.
 *
 * Quem fala pelo Telegram passa pelas MESMAS portas da tela, relidas a cada
 * mensagem: conta ativa e permissão `central_ia`. Perdeu o perfil, o chat
 * pareado para de falar com a Central na hora — o vínculo não é permissão.
 */
class TelegramCanal
{
    public function __construct(
        private readonly TelegramApi $api,
        private readonly ConversaService $conversas,
        private readonly AgentesService $agentes,
        private readonly CockpitService $cockpit,
    ) {}

    public function vinculoDe(User $user): ?CentralIaTelegramVinculo
    {
        return CentralIaTelegramVinculo::where('user_id', $user->id)->first();
    }

    /**
     * Um código novo invalida o anterior. Só o hash vai para o banco: o
     * código aparece uma vez, na tela de quem pediu.
     */
    public function gerarCodigo(User $user): string
    {
        $codigo = bin2hex(random_bytes(16));

        DB::table('central_ia_telegram_codigos')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'codigo_hash' => hash('sha256', $codigo),
                'expira_em' => now()->addMinutes(config('central_ia.telegram.codigo_minutos')),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        return $codigo;
    }

    public function desvincular(User $user): void
    {
        DB::transaction(function () use ($user) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (app(CanalAtivo::class)->pronto()) app(CanalAtivo::class)->transferir($user, 'web');
            CentralIaTelegramVinculo::where('user_id', $user->id)->delete();
            // O que ainda ia para aquele chat não vai mais.
            CentralIaEntrega::where('user_id', $user->id)->where('status', CentralIaEntrega::PENDENTE)
                ->update(['status' => CentralIaEntrega::FALHOU, 'erro' => 'desvinculado']);
        });
    }

    /**
     * Um update do getUpdates. Nada do payload bruto é guardado.
     */
    public function receber(array $update): void
    {
        $updateId = $update['update_id'] ?? null;
        $m = $update['message'] ?? null;

        // Só conversa privada, de gente, em texto. Em grupo o `from` não é o
        // dono do chat, e um vínculo por chat deixaria qualquer membro do
        // grupo escrever na conversa de quem pareou.
        if (! is_int($updateId) || ! is_array($m) || ($m['chat']['type'] ?? null) !== 'private'
            || ($m['from']['is_bot'] ?? true) || ! isset($m['from']['id'], $m['chat']['id'])
            || $m['from']['id'] !== $m['chat']['id']) {
            return;
        }

        $chatId = (int) $m['chat']['id'];
        $midia = $m['document'] ?? $m['video'] ?? (isset($m['photo']) ? last($m['photo']) : null);
        $texto = $m['text'] ?? $m['caption'] ?? ($midia ? 'Analise os arquivos anexados.' : '');
        if (! is_string($texto)) {
            return;
        }

        if (RateLimiter::tooManyAttempts('central-ia:telegram:'.$chatId, 30)) {
            return;
        }
        RateLimiter::hit('central-ia:telegram:'.$chatId, 60);

        if (str_starts_with($texto, '/start')) {
            // O código nunca vira texto da conversa.
            $this->parear($chatId, trim(substr($texto, 6)), $m['from']['username'] ?? null);

            return;
        }

        $vinculo = CentralIaTelegramVinculo::where('chat_id', $chatId)->first();

        if (! $vinculo) {
            $this->responder($chatId, 'Este chat não está ligado a nenhuma conta. Gere o código na Central de IA do AlfaMatriz e toque no link.');

            return;
        }

        $user = User::find($vinculo->user_id);

        if (! $this->podeEscrever($user)) {
            $this->responder($chatId, 'Sua conta não tem acesso à Central de IA agora.');

            return;
        }

        if (CentralIaMensagem::where('telegram_update_id', $updateId)->exists()) return;

        // Mensagem encaminhada é texto de OUTRA pessoa: não vira pedido a um
        // agente com acesso total na máquina do dono.
        if (isset($m['forward_origin']) || isset($m['forward_from']) || isset($m['forward_from_chat']) || isset($m['forward_date'])) {
            $this->responder($chatId, 'Mensagem encaminhada não vira pedido. Escreva o pedido com suas palavras (pode colar o texto).');

            return;
        }

        // O código que a SUA máquina mandou: vale com o canal na tela ou aqui.
        if ($this->codigoDeConfirmacao($user, $chatId, trim($texto))) {
            return;
        }

        $controle = app(CanalAtivo::class);
        if ($controle->pronto()) {
            if (trim($texto) === '/matriz') {
                if ($controle->ativo($user) === 'telegram') {
                    DB::transaction(function () use ($user, $texto, $updateId, $controle) {
                        User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                        if ($controle->ativo($user) !== 'telegram' || CentralIaMensagem::where('telegram_update_id', $updateId)->exists()) return;
                        $this->conversas->registrarDoUsuario($user, $texto, 'telegram', telegramUpdateId: $updateId,
                            projeto: $controle->estado($user)?->projeto_canal);
                        $controle->transferir($user, 'web');
                    });
                }
                $this->responder($chatId, 'Conversa retomada no AlfaMatriz. Continue pelo sistema.');
                return;
            }
            if ($controle->ativo($user) !== 'telegram') {
                $this->responder($chatId, 'A conversa está no AlfaMatriz. Toque em Continuar pelo Telegram no sistema para ativar este canal.');
                return;
            }
        }

        if (trim($texto) === '' || mb_strlen($texto) > config('central_ia.max_texto')) {
            $this->responder($chatId, 'Mensagem vazia ou longa demais (até '.config('central_ia.max_texto').' caracteres).');

            return;
        }

        try {
            if (CentralIaMensagem::where('telegram_update_id', $updateId)->exists()) {
                return;
            }
            DB::transaction(function () use ($user, $vinculo, $chatId, $texto, $updateId, $midia) {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                app(CanalAtivo::class)->exigir($user, 'telegram');
                if ($midia) {
                    $this->receberMidia($user, $vinculo, $chatId, trim($texto), $updateId, $midia);
                } else {
                    $this->tratar($user, $vinculo, $chatId, trim($texto), $updateId);
                }
            });
        } catch (ValidationException $e) {
            // Pedido recusado (projeto que a máquina não tem, limite do dia):
            // a resposta vai só para o chat, e o pedido não entra na conversa.
            $motivo = collect($e->errors())->flatten()->first();
            Eventos::registrar($user, 'recusado', 'Pelo Telegram: '.$motivo.' — mensagem: '.\Illuminate\Support\Str::limit($texto, 120), ['origem' => 'telegram'], 'aviso');
            $this->responder($chatId, $motivo);
        } catch (UniqueConstraintViolationException) {
            // Reentrega de um update que já entrou: o cursor se perdeu, a
            // mensagem não.
        }
    }

    /**
     * `/confirmar 123456`, `/confirmar <pedido> 123456` ou só os 6 dígitos
     * quando há um pedido esperando. O código não entra na conversa: ele é
     * de uso único e quem o confere é a máquina.
     */
    private function codigoDeConfirmacao(User $user, int $chatId, string $texto): bool
    {
        $comando = (bool) preg_match('/^\/confirmar(?:@\w+)?\b/i', $texto);
        if (! preg_match('/^(?:\/confirmar(?:@\w+)?\s+)?(?:#?(\d{1,9})\s+)?(\d{6})$/i', $texto, $c)) {
            if ($comando) {
                $this->responder($chatId, 'Use /confirmar seguido do código de 6 dígitos que a sua máquina mandou.');
            }

            return $comando;
        }
        $esperando = $this->agentes->aguardandoConfirmacao($user);
        if (! $comando && $esperando->isEmpty()) {
            return false;
        }
        $alvo = filled($c[1]) ? $esperando->firstWhere('id', (int) $c[1]) : ($esperando->count() === 1 ? $esperando->first() : null);
        if (! $alvo) {
            $this->responder($chatId, $esperando->isEmpty()
                ? 'Nenhum pedido está esperando confirmação agora.'
                : 'Há '.$esperando->count().' pedidos esperando: '.$esperando->map(fn ($e) => '#'.$e->id)->implode(', ').'. Use /confirmar <pedido> <código>.');

            return true;
        }
        try {
            $this->agentes->confirmar($user, $alvo->id, $c[2], 'telegram');
            $this->responder($chatId, "Código do pedido #{$alvo->id} enviado à sua máquina; ela confere e começa.");
        } catch (ValidationException $e) {
            $this->responder($chatId, collect($e->errors())->flatten()->first());
        }

        return true;
    }

    private function receberMidia(User $user, CentralIaTelegramVinculo $vinculo, int $chatId, string $texto, int $updateId, array $midia): void
    {
        if (($midia['file_size'] ?? 0) > AnexosService::MAX_BYTES || ! is_string($midia['file_id'] ?? null)) {
            throw ValidationException::withMessages(['arquivo' => 'O arquivo deve ter até 20 MB.']);
        }
        $this->responder($chatId, 'Arquivo recebido. Estou baixando para analisar…');
        $tmp = tempnam(sys_get_temp_dir(), 'central-ia-');
        try {
            if (! $this->api->baixar($midia['file_id'], $tmp)) {
                throw ValidationException::withMessages(['arquivo' => 'Não consegui baixar o arquivo. Reenvie um arquivo de até 20 MB.']);
            }
            $this->api->verificarConsumidor();
            $anexo = app(AnexosService::class)->guardar($user,
                new \Illuminate\Http\UploadedFile($tmp, $midia['file_name'] ?? 'foto.jpg', null, null, true), (string) \Illuminate\Support\Str::uuid());
            try {
                DB::transaction(function () use ($user, $vinculo, $chatId, $texto, $updateId, $anexo) {
                    // Só fica visível ao runner depois de o arquivo estar ligado.
                    $pedido = $this->agentes->interpretar($texto);
                    if (! $pedido && str_starts_with($texto, '/')) {
                        throw ValidationException::withMessages(['arquivo' => 'Envie uma legenda descrevendo o pedido, sem comandos de navegação.']);
                    }
                    $pedido ??= ['projeto' => $vinculo->projeto_atual ?? '', 'agente' => $vinculo->agente_atual ?? 'auto',
                        'modo' => 'analisar', 'instrucao' => $texto,
                        'tarefa_id' => filled($vinculo->projeto_atual) ? CentralIaTarefa::de($user)->where('projeto', $vinculo->projeto_atual)
                            ->emCurso()->orderByDesc('ultima_atividade_em')->value('id') : null];
                    $pedido['anexos'] = [$anexo->id];
                    $this->confirmar($chatId, $this->agentes->pedir($user, $texto, $pedido,
                        CentralIaMensagem::ORIGEM_TELEGRAM, telegramUpdateId: $updateId));
                });
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($anexo->caminho);
                $anexo->delete();
                throw $e;
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    public function sinalizarAtividade(): void
    {
        $users = CentralIaExecucao::whereIn('status', [CentralIaExecucao::PENDENTE, CentralIaExecucao::EXECUTANDO])
            ->where('created_at', '>', now()->subMinutes((int) config('central_ia.agentes.timeout_implementar_minutos') + 5))
            ->distinct()->pluck('user_id');
        foreach (CentralIaTelegramVinculo::whereIn('user_id', $users)->get() as $vinculo) {
            if (($user = User::find($vinculo->user_id)) && $this->podeEscrever($user) && (! app(CanalAtivo::class)->pronto() || app(CanalAtivo::class)->ativo($user) === 'telegram') && $this->agentes->disponiveis($user)['online']) {
                $this->api->chamar('sendChatAction', ['chat_id' => $vinculo->chat_id, 'action' => 'typing'], 3);
            }
        }
    }

    /**
     * Comandos do chat e mensagens comuns.
     *
     * Com `/usar <projeto>`, a mensagem comum vira pedido no projeto — na
     * tarefa mais recente em curso, com o agente escolhido (`/auto` por
     * padrão). Sem projeto em uso, ela é só anotação na conversa Geral: nada
     * dispara agente sem a pessoa ter escolhido um projeto.
     */
    private function tratar(User $user, CentralIaTelegramVinculo $vinculo, int $chatId, string $texto, int $updateId): void
    {
        if (app(ProgressoService::class)->consulta($texto)) {
            DB::transaction(function () use ($user, $vinculo, $texto, $updateId) {
                User::whereKey($user->id)->lockForUpdate()->first();
                if (CentralIaMensagem::where('telegram_update_id', $updateId)->exists()) return;
                $projeto = $texto === '/status' ? null : $vinculo->projeto_atual;
                // A resposta fica no assunto ATIVO do canal (projeto e
                // tarefa): gravada fora dele, a regra de entrega do canal
                // ativo a segurava e o /status ficava sem resposta no celular.
                $canal = app(CanalAtivo::class)->pronto() ? app(CanalAtivo::class)->estado($user) : null;
                $onde = $canal && $canal->canal_ativo === 'telegram' ? [$canal->projeto_canal, $canal->tarefa_canal] : [$projeto, null];
                $this->conversas->registrarDoUsuario($user, $texto, CentralIaMensagem::ORIGEM_TELEGRAM,
                    telegramUpdateId: $updateId, projeto: $onde[0], tarefaId: $onde[1]);
                $this->conversas->registrarDoAgente($user, 'Central de IA', app(ProgressoService::class)->resumo($user, $projeto),
                    CentralIaMensagem::AUTOR_SISTEMA, $onde[0], $onde[1]);
            });
            $this->entregarPendentes();
            return;
        }

        // O atalho completo (`/claude projeto pedido`) vale sempre.
        if ($pedido = $this->agentes->interpretar($texto)) {
            $this->confirmar($chatId, $this->agentes->pedir($user, $texto, $pedido, CentralIaMensagem::ORIGEM_TELEGRAM, telegramUpdateId: $updateId));

            return;
        }

        // Sem barra e sem projeto em uso, a frase pode ser a intenção dita
        // com palavras: "continuar gestor de onde paramos", "usar o alfahome".
        // Foi assim que o dono escreveu na primeira tentativa (10/10/2026), e
        // a mensagem virou anotação calada.
        if (! str_starts_with($texto, '/') && blank($vinculo->projeto_atual) && ($intencao = $this->intencao($user, $texto))) {
            [$verbo, $projeto] = $intencao;
            $verbo === 'continuar'
                ? $this->continuar($user, $vinculo, $chatId, $projeto, pedidoInicial: $texto, updateId: $updateId)
                : $this->usar($user, $vinculo, $chatId, $projeto);

            return;
        }

        [$comando, $resto] = str_starts_with($texto, '/')
            ? array_pad(preg_split('/\s+/', preg_replace('/^(\/\w+)@\w+/', '$1', $texto), 2), 2, '')
            : [null, $texto];
        $agentes = ['/auto' => null, '/claude' => 'claude', '/gpt' => 'codex', '/codex' => 'codex', '/copilot' => 'copilot'];

        match (true) {
            $comando === '/projetos' => $this->responder($chatId, $this->textoProjetos($user, $vinculo)),
            $comando === '/status' => $this->responder($chatId, $this->textoStatus($user)),
            $comando === '/usar' => $this->usar($user, $vinculo, $chatId, trim($resto)),
            $comando === '/geral' => $this->definir($vinculo, $chatId, ['projeto_atual' => null], 'Mensagens voltam para a conversa Geral, sem acionar agente.'),
            $comando !== null && array_key_exists($comando, $agentes) && trim($resto) === '' => $this->definir(
                $vinculo, $chatId, ['agente_atual' => $agentes[$comando]],
                'Próximos pedidos: '.($agentes[$comando] ? CentralIaExecucao::AGENTES[$agentes[$comando]] : 'IA automática').'.'
            ),
            $comando === '/nova' => $this->pedirNoProjeto($user, $vinculo, $chatId, trim($resto), $texto, $updateId, novaTarefa: true),
            $comando === '/trocar' => $this->trocar($user, $vinculo, $chatId, trim($resto), $texto, $updateId),
            $comando === '/continuar' => $this->continuar($user, $vinculo, $chatId, trim($resto)),
            $comando === '/salvar' => $this->salvar($user, $vinculo, $chatId),
            $comando !== null => $this->responder($chatId, $this->ajuda()),
            filled($vinculo->projeto_atual) => $this->pedirNoProjeto($user, $vinculo, $chatId, $texto, $texto, $updateId),
            // Sem projeto, mas é um pedido de resposta ("resumo da minha
            // agenda de hoje"): vai a um agente na conversa Geral.
            app(Roteador::class)->pedeResposta($texto) => $this->confirmar($chatId, $this->agentes->pedir($user, $texto, [
                'projeto' => '', 'agente' => $vinculo->agente_atual ?? 'auto', 'modo' => 'analisar', 'instrucao' => $texto,
            ], CentralIaMensagem::ORIGEM_TELEGRAM, telegramUpdateId: $updateId)),
            default => $this->anotar($user, $chatId, $texto, $updateId),
        };
    }

    /**
     * Mensagem sem projeto em uso: fica anotada na conversa Geral — e o bot
     * DIZ isso. Calado, parecia que a mensagem tinha se perdido.
     */
    private function anotar(User $user, int $chatId, string $texto, int $updateId): void
    {
        $this->conversas->registrarDoUsuario($user, $texto, CentralIaMensagem::ORIGEM_TELEGRAM, telegramUpdateId: $updateId);
        $this->responder($chatId, 'Anotado na conversa Geral (nenhum agente acionado). Para trabalhar num projeto: /usar gestor, '
            .'ou /continuar gestor para seguir a conversa do VS Code. /projetos lista os do cockpit.');
    }

    /**
     * "continuar|retomar|seguir … <projeto>" ou "usar|trabalhar|abrir … <projeto>",
     * com o nome de um projeto que a máquina informou.
     *
     * @return array{0: string, 1: string}|null
     */
    private function intencao(User $user, string $texto): ?array
    {
        $minusculo = mb_strtolower($texto);
        $verbo = match (true) {
            (bool) preg_match('/^\s*(continu|retom|segui|sig[ao])/u', $minusculo) => 'continuar',
            (bool) preg_match('/^\s*(us[ae]|usar|trabalh|abr[ae]|mud[ae] para)/u', $minusculo) => 'usar',
            default => null,
        };
        if (! $verbo) {
            return null;
        }

        // O nome mais longo que aparece inteiro vence ("gestor_alfa_mobile" antes de "gestor").
        $projetos = collect($this->agentes->disponiveis($user)['projetos'])->sortByDesc(fn ($p) => mb_strlen($p));
        $projeto = $projetos->first(fn ($p) => preg_match('/(^|[^a-z0-9_.-])'.preg_quote(mb_strtolower($p), '/').'($|[^a-z0-9_.-])/u', $minusculo));

        return $projeto ? [$verbo, $projeto] : null;
    }

    /** Recibo do pedido, na hora: quem vai fazer, por quê e se já está rodando. */
    private function confirmar(int $chatId, CentralIaExecucao $e): void
    {
        $modo = $e->modo === 'implementar' ? 'implementar' : 'analisar';
        $handoff = isset($e->roteamento['handoff_de']) ? ' Assume a tarefa no lugar de '.(CentralIaExecucao::AGENTES[$e->roteamento['handoff_de']] ?? '?').', com o contexto dela.' : '';
        $onde = $e->projeto !== '' ? $e->projeto : 'Geral';
        $fontes = collect($e->roteamento['fontes'] ?? [])->map(fn ($f) => $f['tipo'] === 'agenda' ? "Google Agenda ({$f['rotulo']})" : $f['tipo'])->implode(', ');
        $this->responder($chatId, "Recebido em {$onde}: {$e->nomeDoAgente()} vai {$modo} (tarefa #{$e->tarefa_id}). "
            .($fontes ? "Dados oficiais: {$fontes}. " : '')
            .($e->roteamento['motivo'] ?? '').".{$handoff} A resposta chega aqui quando terminar.");
    }

    private function definir(CentralIaTelegramVinculo $vinculo, int $chatId, array $dados, string $resposta): void
    {
        if (array_key_exists('projeto_atual', $dados) && app(CanalAtivo::class)->pronto()) {
            app(CanalAtivo::class)->transferir($vinculo->user, 'telegram', $dados['projeto_atual']);
        }
        $vinculo->update($dados);
        $this->responder($chatId, $resposta);
    }

    private function usar(User $user, CentralIaTelegramVinculo $vinculo, int $chatId, string $projeto): void
    {
        if (! in_array($projeto, $this->agentes->disponiveis($user)['projetos'], true)) {
            $this->responder($chatId, $projeto === '' ? 'Diga o projeto: /usar alfahome' : "Não achei {$projeto} entre os projetos da sua máquina. /projetos mostra os do cockpit.");

            return;
        }

        $this->definir($vinculo, $chatId, ['projeto_atual' => $projeto],
            "Usando {$projeto}. Suas mensagens agora são pedidos nele (continuam a tarefa mais recente; /nova abre outra). /geral sai.");
    }

    private function pedirNoProjeto(User $user, CentralIaTelegramVinculo $vinculo, int $chatId, string $instrucao, string $texto, int $updateId, bool $novaTarefa = false, ?string $agente = null): void
    {
        if (blank($vinculo->projeto_atual)) {
            $this->responder($chatId, 'Escolha o projeto antes: /usar alfahome');

            return;
        }
        if ($instrucao === '') {
            $this->responder($chatId, 'Diga o que fazer depois do comando.');

            return;
        }

        if ($novaTarefa && app(CanalAtivo::class)->pronto()) app(CanalAtivo::class)->transferir($user, 'telegram', $vinculo->projeto_atual);
        $contexto = app(CanalAtivo::class)->estado($user);
        $tarefa = ! $novaTarefa && $contexto?->canal_ativo === 'telegram' && $contexto->projeto_canal === $vinculo->projeto_atual && $contexto->tarefa_canal
            ? $contexto->tarefa_canal : ($novaTarefa ? null : CentralIaTarefa::de($user)->where('projeto', $vinculo->projeto_atual)->emCurso()
            ->orderByDesc('ultima_atividade_em')->value('id'));

        $this->confirmar($chatId, $this->agentes->pedir($user, $texto, [
            'projeto' => $vinculo->projeto_atual,
            'agente' => $agente ?? $vinculo->agente_atual ?? 'auto',
            'modo' => 'auto',
            'instrucao' => $instrucao,
            'tarefa_id' => $tarefa,
        ], CentralIaMensagem::ORIGEM_TELEGRAM, telegramUpdateId: $updateId));
    }

    /**
     * `/trocar codex [pedido]`: a tarefa atual passa para outro agente. O
     * handoff acontece no próximo pedido — o novo agente recebe o dossiê e
     * confere o estado antes de alterar.
     */
    private function trocar(User $user, CentralIaTelegramVinculo $vinculo, int $chatId, string $resto, string $texto, int $updateId): void
    {
        [$nome, $pedido] = array_pad(preg_split('/\s+/', $resto, 2), 2, '');
        $agente = ['claude' => 'claude', 'gpt' => 'codex', 'codex' => 'codex', 'copilot' => 'copilot'][strtolower($nome)] ?? null;

        if (! $agente) {
            $this->responder($chatId, 'Para qual agente? /trocar claude, /trocar gpt ou /trocar copilot.');

            return;
        }

        $vinculo->update(['agente_atual' => $agente]);

        if (trim($pedido) !== '') {
            $this->pedirNoProjeto($user, $vinculo, $chatId, trim($pedido), $texto, $updateId, agente: $agente);

            return;
        }

        $this->responder($chatId, CentralIaExecucao::AGENTES[$agente].' assume a tarefa no próximo pedido, com o contexto dela.');
    }

    /**
     * `/continuar gestor [claude|gpt|copilot]`: pega a sessão do VS Code mais
     * recente do projeto e passa a usar o projeto aqui, na tarefa que continua
     * aquela conversa.
     */
    private function continuar(User $user, CentralIaTelegramVinculo $vinculo, int $chatId, string $resto, ?string $pedidoInicial = null, ?int $updateId = null): void
    {
        [$projeto, $nome] = array_pad(preg_split('/\s+/', $resto, 2), 2, '');
        $agente = ['claude' => 'claude', 'gpt' => 'codex', 'codex' => 'codex', 'copilot' => 'copilot'][strtolower($nome)] ?? null;

        $sessao = $this->agentes->sessoes($user)
            ->first(fn ($s) => $s['projeto'] === $projeto && (! $agente || $s['agente'] === $agente));

        if (! $sessao) {
            $this->responder($chatId, $projeto === '' ? 'Diga o projeto: /continuar gestor' : "Não achei sessão recente do VS Code em {$projeto}.");

            return;
        }

        $tarefa = $this->agentes->continuarSessao($user, $sessao['agente'], $sessao['id']);
        if (app(CanalAtivo::class)->pronto()) app(CanalAtivo::class)->transferir($user, 'telegram', $projeto, $tarefa->id);
        $vinculo->update(['projeto_atual' => $projeto, 'agente_atual' => null]);

        $quando = \Illuminate\Support\Carbon::parse($sessao['ultima_atividade'])->timezone(config('app.timezone'))->format('d/m H:i');
        $this->responder($chatId, 'Continuando a sessão de '.CentralIaExecucao::AGENTES[$sessao['agente']]." em {$projeto} (última atividade {$quando}, {$sessao['mensagens']} mensagens suas), na tarefa #{$tarefa->id}. "
            .($pedidoInicial ? 'Sua mensagem já foi como primeiro pedido. ' : 'Mande o próximo pedido. ')
            .'A sessão do VS Code fica como está; daqui em diante as duas seguem separadas.');

        // Dito com palavras ("continuar gestor de onde paramos"), a frase já é
        // o pedido: segue na hora, sem pedir outra mensagem.
        if ($pedidoInicial && $updateId) {
            $this->pedirNoProjeto($user, $vinculo->refresh(), $chatId, $pedidoInicial, $pedidoInicial, $updateId);
        }
    }

    /** `/salvar`: ponto na tarefa mais recente do projeto em uso. */
    private function salvar(User $user, CentralIaTelegramVinculo $vinculo, int $chatId): void
    {
        // A tarefa do canal ativo vence a "mais recente do projeto": o pedido
        // do ponto vai para ela (AgentesService::pedir), e o ponto ia para
        // uma, com o #id e o agente de outra.
        $canal = app(CanalAtivo::class)->pronto() ? app(CanalAtivo::class)->estado($user) : null;
        $tarefa = $canal?->tarefa_canal ? CentralIaTarefa::de($user)->find($canal->tarefa_canal) : null;
        $tarefa ??= blank($vinculo->projeto_atual) ? null : CentralIaTarefa::de($user)->where('projeto', $vinculo->projeto_atual)
            ->emCurso()->orderByDesc('ultima_atividade_em')->first();
        if (! $tarefa) {
            $this->responder($chatId, 'Sem tarefa em curso para salvar. Use /usar <projeto> antes.');

            return;
        }
        $this->agentes->salvarPonto($user, $tarefa, CentralIaMensagem::ORIGEM_TELEGRAM);
        $this->responder($chatId, "Salvando ponto da tarefa #{$tarefa->id} em {$tarefa->projeto}: o agente vai resumir o estado, e os próximos pedidos começam enxutos a partir do resumo.");
    }

    private function textoProjetos(User $user, CentralIaTelegramVinculo $vinculo): string
    {
        $projetos = $this->cockpit->montar($user)['projetos'];
        if (! $projetos) {
            return 'Nenhum projeto no cockpit ainda. Fixe projetos na Central de IA ou use /usar <projeto>.';
        }
        $rotulos = ['executando' => 'executando', 'aguardando_voce' => '⚠ aguardando você', 'falhou' => '⚠ falhou', 'aberta' => 'aberta',
            'pausada' => 'pausado', 'ocioso' => 'sem tarefa'];
        $linhas = array_map(fn ($p) => ($p['nome'] === $vinculo->projeto_atual ? '▶ ' : '• ').$p['nome'].' — '.($rotulos[$p['estado']] ?? $p['estado'])
            .($p['agente'] ? " ({$p['agente']})" : '').($p['tarefas_em_curso'] ? " · {$p['tarefas_em_curso']} tarefa(s)" : ''), $projetos);

        return "Projetos:\n".implode("\n", $linhas)."\n\nAgente: ".($vinculo->agente_atual ? CentralIaExecucao::AGENTES[$vinculo->agente_atual] : 'automático');
    }

    private function textoStatus(User $user): string
    {
        return app(ProgressoService::class)->resumo($user);
    }

    private function ajuda(): string
    {
        return "Comandos:\n/projetos — projetos do cockpit\n/status — o que roda e o que espera você\n/usar <projeto> — mensagens viram pedidos nele\n"
            ."/nova <pedido> — tarefa nova no projeto em uso\n/geral — sai do projeto\n/auto, /claude, /gpt, /copilot — agente dos próximos pedidos\n"
            ."/trocar <agente> [pedido] — passa a tarefa atual para outro agente\n/continuar <projeto> [agente] — segue a conversa do VS Code daquele projeto\n/salvar — salva um ponto na tarefa atual (a conversa recomeça enxuta)\n/claude <projeto> <pedido> — pedido direto (com ! para implementar)";
    }

    private function parear(int $chatId, string $codigo, ?string $usuarioTelegram): void
    {
        $resposta = DB::transaction(function () use ($chatId, $codigo, $usuarioTelegram) {
            $registro = preg_match('/^[a-f0-9]{32}$/D', $codigo)
                ? DB::table('central_ia_telegram_codigos')->where('codigo_hash', hash('sha256', $codigo))->lockForUpdate()->first()
                : null;

            if (! $registro || now()->greaterThan($registro->expira_em)) {
                return 'Código inválido ou vencido. Gere outro na Central de IA do AlfaMatriz.';
            }

            DB::table('central_ia_telegram_codigos')->where('id', $registro->id)->delete();
            $user = User::find($registro->user_id);

            if (! $this->podeEscrever($user)) {
                return 'Sua conta não tem acesso à Central de IA agora.';
            }

            $outro = CentralIaTelegramVinculo::where('chat_id', $chatId)->where('user_id', '!=', $user->id)->exists();

            if ($outro) {
                return 'Este Telegram já está ligado a outra conta do AlfaMatriz. Desvincule por lá antes.';
            }

            CentralIaTelegramVinculo::updateOrCreate(
                ['user_id' => $user->id],
                ['chat_id' => $chatId, 'telegram_usuario' => $usuarioTelegram],
            );

            return "Pronto, {$user->name}: este chat agora é a sua conversa da Central de IA.";
        });

        $this->responder($chatId, $resposta);
    }

    /**
     * Leva ao Telegram as entregas pendentes, mais antigas primeiro.
     */
    public function entregarPendentes(int $limite = 20): void
    {
        // Presa em `enviando` é processo que morreu no meio da chamada: pode
        // ter chegado. Vira incerta, nunca pendente de novo.
        CentralIaEntrega::where('status', CentralIaEntrega::ENVIANDO)->where('updated_at', '<', now()->subMinutes(2))
            ->update(['status' => CentralIaEntrega::INCERTA, 'erro' => 'interrompida']);

        $ids = CentralIaEntrega::where('status', CentralIaEntrega::PENDENTE)->orderBy('id')->limit($limite)->pluck('id');
        // Prazo por rodada: o ciclo do bot precisa caber na trava dele. O que
        // sobrar sai na próxima volta, segundos depois.
        $ate = microtime(true) + 20;

        foreach ($ids as $id) {
            if (! $this->entregar($id) || microtime(true) > $ate) {
                return;
            }
        }
    }

    /**
     * @return bool falso quando o Telegram pediu para esperar — a fila para.
     */
    private function entregar(int $id): bool
    {
        // A chamada ao Telegram fica FORA de qualquer transação: dentro dela,
        // o `enviando` era só um savepoint — se o processo morresse no meio do
        // envio, o rollback devolvia a entrega a `pendente` e a mensagem saía
        // de novo. Agora `enviando` é gravado antes, e quem cai no meio vira
        // `incerta` (entregarPendentes), sem reenvio. A troca de canal ainda
        // é conferida na reserva, imediatamente antes do envio.
        return $this->entregarInterno($id);
    }

    private function entregarInterno(int $id): bool
    {
        $entrega = DB::transaction(function () use ($id) {
            $entrega = CentralIaEntrega::whereKey($id)->lockForUpdate()->first();

            if (! $entrega || $entrega->status !== CentralIaEntrega::PENDENTE) {
                return null;
            }

            $vinculo = CentralIaTelegramVinculo::where('user_id', $entrega->user_id)->first();

            if (! $vinculo || $vinculo->chat_id !== $entrega->chat_id || ! $this->podeLer(User::find($entrega->user_id))) {
                $entrega->update(['status' => CentralIaEntrega::FALHOU, 'erro' => 'sem_acesso']);

                return null;
            }

            if (! app(CanalAtivo::class)->entregaPermitida(User::findOrFail($entrega->user_id), $entrega->mensagem)) {
                $entrega->update(['status' => CentralIaEntrega::FALHOU, 'erro' => 'canal_inativo']);
                return null;
            }

            $entrega->update(['status' => CentralIaEntrega::ENVIANDO, 'tentativas' => $entrega->tentativas + 1]);

            return $entrega;
        });

        if (! $entrega) {
            return true;
        }

        $mensagem = $entrega->mensagem;
        $texto = RespostaVisual::telegram($mensagem->conteudo);

        // O Telegram recusa acima de 4096. Resposta de agente passa disso
        // fácil; no celular vai o começo, e a íntegra fica na tela.
        // O limite do Telegram conta em UTF-16: emoji vale 2.
        if (self::utf16($texto) > 3800) {
            while (self::utf16($texto) > 3800) {
                $texto = mb_substr($texto, 0, (int) (mb_strlen($texto) * 0.95));
            }
            $texto .= "\n\n… (continua na Central de IA do AlfaMatriz)";
        }

        $resultado = $this->api->chamar('sendMessage', [
            'chat_id' => $entrega->chat_id,
            'text' => $this->cabecalho($mensagem)."\n".$texto,
            // Sem prévia: um link na resposta não é buscado pelos servidores
            // do Telegram (canal de vazamento por URL montada pelo agente).
            'link_preview_options' => ['is_disabled' => true],
        ]);

        if ($resultado['ok']) {
            $entrega->update(['status' => CentralIaEntrega::ENTREGUE, 'telegram_message_id' => $resultado['resultado']['message_id'] ?? null, 'erro' => null]);

            return true;
        }

        if ($resultado['erro'] === 'limite') {
            // 429 é "não entreguei": volta para a fila sem contar como incerta.
            $entrega->update(['status' => CentralIaEntrega::PENDENTE, 'erro' => 'limite']);

            return false;
        }

        $entrega->update([
            'status' => $resultado['erro'] === 'recusada' ? CentralIaEntrega::FALHOU : CentralIaEntrega::INCERTA,
            'erro' => $resultado['erro'],
        ]);

        return true;
    }

    private function cabecalho(CentralIaMensagem $m): string
    {
        // Vários projetos no mesmo chat: cada mensagem diz de qual é.
        $projeto = $m->projeto ? "[{$m->projeto}] " : '';

        return $projeto.match ($m->autor_tipo) {
            CentralIaMensagem::AUTOR_USUARIO => '🖥 Você, pelo AlfaMatriz:',
            CentralIaMensagem::AUTOR_AGENTE => '🤖 '.($m->autor_nome ?: 'Agente').':',
            default => 'ℹ️ Central:',
        };
    }

    private static function utf16(string $texto): int
    {
        return intdiv(strlen(mb_convert_encoding($texto, 'UTF-16LE', 'UTF-8')), 2);
    }

    private function responder(int $chatId, string $texto): void
    {
        // Aviso do próprio bot, fora da conversa. Perdido, a pessoa tenta de
        // novo; por isso não vira entrega rastreada.
        $this->api->chamar('sendMessage', ['chat_id' => $chatId, 'text' => $texto, 'link_preview_options' => ['is_disabled' => true]]);
    }

    private function podeEscrever(?User $user): bool
    {
        return $this->podeLer($user) && $user->canPermissao('central_ia', 'incluir');
    }

    private function podeLer(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $user->esquecerPermissoes();

        return $user->ativo && ! $user->primeiro_acesso && $user->revenda_id === null
            && $user->canPermissao('central_ia', 'ler');
    }
}
