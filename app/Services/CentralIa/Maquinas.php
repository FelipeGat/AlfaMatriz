<?php

namespace App\Services\CentralIa;

use App\Models\CentralIaRunner;
use App\Models\CentralIaTelegramBot;
use App\Models\CentralIaTelegramVinculo;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * "Conectar minha máquina" (T-374): a pessoa liga a própria máquina à Central
 * pela tela, sem ninguém rodar `artisan` no servidor.
 *
 * Dono do produto, 14/10/2026: "nada pode estar preso à máquina do Felipe; a
 * Central de IA é para um time". Até aqui o token saía de
 * `alfa:central-ia-token` no servidor e a instalação era à mão, só em Linux.
 *
 * O token NÃO vai no comando que a pessoa cola: vai um código de instalação
 * de uso único, que vale 15 minutos e que o instalador troca pelo token. O
 * comando fica no histórico do terminal; o código, depois de usado, não abre
 * nada. O token continua o mesmo de sempre — pessoal, capacidade
 * `central-ia-runner` e só ela, o banco guarda só o hash.
 */
class Maquinas
{
    public const HABILIDADE = 'central-ia-runner';

    public const VALIDADE_MINUTOS = 15;

    /** Sem batida há mais que isto, a tela pede para conferir a tailnet. */
    public const SEM_NOTICIA_MINUTOS = 10;

    /**
     * Os arquivos do runner que a instalação baixa — e só eles. A porta
     * pública dos arquivos (`arquivo`) recusa qualquer outro nome.
     */
    public const ARQUIVOS = [
        'runner.mjs', 'politica.mjs', 'confirmacao.mjs', 'auditoria.mjs', 'uso-local.mjs',
        'extrato.mjs', 'anexos.mjs', 'progresso.mjs', 'limpeza.mjs', 'atualizar-quando-ocioso.sh',
        // Secretário, Etapa 4: transcrição de reuniões na máquina.
        'transcricao.mjs', 'transcrever_reuniao.py',
        // Etapa 5: o retrato do Gestor pela ponte (o .php vai para a pasta da ponte, na máquina da pessoa).
        'gestor.mjs', 'secretario_gestor.php',
        // Agenda do Google pela máquina: o .py vai para a pasta da ponte pessoal do Google.
        'agenda-google.mjs', 'secretario_agenda.py',
        // Pastas de documentos espelhadas do OneDrive (documentos-espelhados).
        'documentos.mjs',
    ];

    public function podeConectar(User $user): bool
    {
        return $user->ativo && ! $user->temEscopoDeRevenda() && $user->canPermissao('central_ia_agentes', 'incluir');
    }

    /**
     * As máquinas da pessoa: um token de runner por máquina, com o que a
     * máquina contou na última batida.
     */
    public function lista(User $user): array
    {
        $runners = CentralIaRunner::where('user_id', $user->id)->whereNotNull('token_id')->get()->keyBy('token_id');

        return $this->tokens($user)->sortByDesc('id')->map(function (PersonalAccessToken $t) use ($runners) {
            $r = $runners->get($t->id);
            $visto = $r?->visto_em;

            return [
                'id' => $t->id,
                'nome' => $t->name,
                'criada_em' => $t->created_at?->toIso8601String(),
                'maquina' => $r?->maquina,
                'sistema' => $r?->politica['sistema'] ?? null,
                'agentes' => $r?->agentes ?? [],
                'projetos' => $r ? count($r->projetos ?? []) : 0,
                'telegram' => $r?->politica['telegram'] ?? null,
                'visto_em' => $visto?->toIso8601String(),
                'online' => (bool) $r?->online(),
                // A máquina sumiu: quase sempre é a tailnet desligada nela, ou o
                // serviço parado. Sem batida nenhuma: o comando não rodou (ou
                // rodou numa máquina que não alcança o AlfaMatriz).
                'situacao' => ! $visto ? 'nunca' : ($visto->greaterThan(now()->subMinutes(self::SEM_NOTICIA_MINUTOS)) ? 'ok' : 'sumiu'),
            ];
        })->values()->all();
    }

    /** Gera o código de instalação; o token só nasce quando a máquina o troca. */
    public function iniciar(User $user): string
    {
        abort_unless($this->podeConectar($user), 403);
        $codigo = Str::random(40);
        Cache::put($this->chave($codigo), ['user_id' => $user->id], now()->addMinutes(self::VALIDADE_MINUTOS));
        Eventos::registrar($user, 'maquina', 'Código de instalação de máquina gerado.', ['origem' => 'web']);

        return $codigo;
    }

    /**
     * A máquina troca o código pelo token. Uso único: o `pull` tira o código
     * do cache antes de qualquer outra coisa, e o segundo uso não acha nada.
     * O `pull` sozinho lê e depois apaga (no cache `database`, duas consultas):
     * duas trocas no mesmo instante liam as duas e saíam com dois tokens
     * (revisão da T-374). A trava faz uma esperar a outra, e a segunda já não
     * acha o código.
     * As portas da tela valem de novo aqui — quem perdeu o perfil ou foi
     * desativado nos 15 minutos não leva o token.
     *
     * A mesma máquina conectada de novo (o comando rodado outra vez, com
     * código novo) troca o token antigo dela, em vez de deixar dois. Máquina
     * NOVA (`$nova`: sem configuração anterior) com o nome de outra da mesma
     * pessoa ganha um sufixo: dois Macs chamados "MacBook-Pro" não podem um
     * desconectar o outro em silêncio (revisão da T-374).
     */
    public function concluir(string $codigo, string $maquina, bool $nova = false): ?array
    {
        $chave = $this->chave($codigo);
        try {
            $dados = Cache::lock($chave.':trava', 10)->block(5, fn () => Cache::pull($chave));
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            $dados = null;
        }
        $user = $dados ? User::find($dados['user_id']) : null;
        if (! $user || ! $this->podeConectar($user)) {
            return null;
        }

        $token = DB::transaction(function () use ($user, &$maquina, $nova) {
            // Mesma ordem de travas do `desconectar`: conta, depois tokens.
            User::whereKey($user->id)->lockForUpdate()->first();
            if ($nova) {
                $usados = $this->tokens($user)->pluck('name')->all();
                for ($base = mb_substr($maquina, 0, 60), $n = 2; in_array($maquina, $usados, true); $n++) {
                    $maquina = "{$base}-{$n}";
                }
            }
            $this->tokens($user)->filter(fn ($t) => $t->name === $maquina)->each(function ($t) use ($user) {
                CentralIaRunner::where('user_id', $user->id)->where('token_id', $t->id)->update(['token_id' => null]);
                $t->delete();
            });

            return $user->createToken($maquina, [self::HABILIDADE]);
        });
        Eventos::registrar($user, 'maquina', "Máquina {$maquina} conectada à Central.", ['origem' => 'runner']);

        $chat = CentralIaTelegramVinculo::where('user_id', $user->id)->value('chat_id');
        $bot = CentralIaTelegramBot::desteServidor()->where('user_id', $user->id)->first();

        return [
            'token' => $token->plainTextToken,
            'maquina' => $maquina,
            'conta' => $user->name,
            // Só o chat, e UMA vez, na instalação: ele fica no arquivo da
            // máquina, e o servidor não muda depois para onde o código vai.
            // O código sai por um SEGUNDO bot da pessoa, cujo token é colado no
            // terminal e nunca passa pelo servidor (decisão da T-368, mantida
            // na T-374 com um par de bots por pessoa). Com o token do bot da
            // conversa aqui, quem controlasse o servidor encaminharia a
            // mensagem com o código e confirmaria o que quisesse (revisão da
            // T-374). O nome do bot da conversa vai só para o instalador
            // recusar o mesmo bot no lugar do de código.
            'telegram' => [
                'chat_id' => $bot && $chat ? (string) $chat : null,
                'bot' => $bot?->usuario,
            ],
        ];
    }

    /** Desconectar: o token deixa de abrir a porta e a máquina sai da lista. */
    public function desconectar(User $user, int $tokenId): void
    {
        $token = $this->tokens($user)->firstWhere('id', $tokenId);
        abort_unless($token, 404);
        // A trava na conta vem primeiro, como no `proxima` e no `concluir`:
        // sem ela, um pedido entregue à máquina no mesmo instante escapava da
        // interrupção (lido antes de virar "executando") e ficava preso a uma
        // máquina que já não existe; e as travas em ordens diferentes davam
        // deadlock com o `concluir` (revisão da T-374).
        DB::transaction(function () use ($user, $token) {
            User::whereKey($user->id)->lockForUpdate()->first();
            $runners = CentralIaRunner::where('user_id', $user->id)->where('token_id', $token->id)->get();
            foreach ($runners as $runner) app(AgentesService::class)->interromperDaMaquinaDesconectada($user, $runner);
            CentralIaRunner::whereKey($runners->modelKeys())->delete();
            $token->delete();
        });
        Eventos::registrar($user, 'maquina', "Máquina {$token->name} desconectada da Central.", ['origem' => 'web']);
    }

    /**
     * O comando que a pessoa cola no terminal. O código vai no AMBIENTE do
     * bash, e não como argumento: argumento aparece no `ps` de qualquer
     * usuário da máquina durante a instalação inteira; o ambiente de um
     * processo, só para o dono dele (revisão da T-374).
     */
    public function comando(?string $codigo = null): string
    {
        $url = rtrim((string) config('app.url'), '/');

        return "curl -fsSL {$url}/central-ia/instalar.sh | ".($codigo ? "CENTRAL_IA_CODIGO={$codigo} " : '').'bash';
    }

    /** O instalador, com o endereço deste AlfaMatriz já dentro. */
    public function instalador(): string
    {
        $script = file_get_contents(base_path('deploy/central-ia/instalar.sh'));

        return strtr($script, [
            '__ALFAMATRIZ_URL__' => rtrim((string) config('app.url'), '/'),
            '__ARQUIVOS__' => implode(' ', self::ARQUIVOS),
        ]);
    }

    /** @return Collection<int, PersonalAccessToken> */
    private function tokens(User $user)
    {
        return $user->tokens()->get()->filter(fn ($t) => $t->abilities === [self::HABILIDADE])->values();
    }

    private function chave(string $codigo): string
    {
        return 'central-ia:instalacao:'.hash('sha256', $codigo);
    }
}
