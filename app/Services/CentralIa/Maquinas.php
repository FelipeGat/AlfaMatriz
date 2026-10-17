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
        // Etapa 5: o retrato do Gestor pela ponte (o .php vai para o C:\tmp do dono).
        'gestor.mjs', 'secretario_gestor.php',
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
     * As portas da tela valem de novo aqui — quem perdeu o perfil ou foi
     * desativado nos 15 minutos não leva o token.
     *
     * A mesma máquina conectada de novo (o comando rodado outra vez, com
     * código novo) troca o token antigo dela, em vez de deixar dois.
     */
    public function concluir(string $codigo, string $maquina): ?array
    {
        $dados = Cache::pull($this->chave($codigo));
        $user = $dados ? User::find($dados['user_id']) : null;
        if (! $user || ! $this->podeConectar($user)) {
            return null;
        }

        $token = DB::transaction(function () use ($user, $maquina) {
            $this->tokens($user)->filter(fn ($t) => $t->name === $maquina)->each(function ($t) use ($user) {
                CentralIaRunner::where('user_id', $user->id)->where('token_id', $t->id)->update(['token_id' => null]);
                $t->delete();
            });

            return $user->createToken($maquina, [self::HABILIDADE]);
        });
        Eventos::registrar($user, 'maquina', "Máquina {$maquina} conectada à Central.", ['origem' => 'runner']);

        $chat = CentralIaTelegramVinculo::where('user_id', $user->id)->value('chat_id');
        // O bot DA PESSOA (T-374): ela conversa e recebe o código pelo mesmo
        // bot. O token ir à máquina dela não abre nada que ela já não tenha.
        $bot = CentralIaTelegramBot::desteServidor()->where('user_id', $user->id)->first();

        return [
            'token' => $token->plainTextToken,
            'conta' => $user->name,
            // O chat vai UMA vez, na instalação, e fica no arquivo da máquina.
            // Depois disso o servidor não muda para onde o código vai: se
            // mudasse, quem controlasse o servidor mandaria o código para o
            // próprio Telegram e confirmaria o que quisesse.
            'telegram' => [
                'token' => $bot && $chat ? $bot->token : null,
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
        DB::transaction(function () use ($user, $token) {
            CentralIaRunner::where('user_id', $user->id)->where('token_id', $token->id)->delete();
            $token->delete();
        });
        Eventos::registrar($user, 'maquina', "Máquina {$token->name} desconectada da Central.", ['origem' => 'web']);
    }

    /** O comando que a pessoa cola no terminal. */
    public function comando(?string $codigo = null): string
    {
        $url = rtrim((string) config('app.url'), '/');

        return "curl -fsSL {$url}/central-ia/instalar.sh | bash".($codigo ? " -s -- {$codigo}" : '');
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
