<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ComentarTarefa;
use App\Mcp\Tools\ConversarNaTarefa;
use App\Mcp\Tools\CriarTarefa;
use App\Mcp\Tools\ListarTarefas;
use App\Mcp\Tools\MarcarCompromisso;
use App\Mcp\Tools\MoverTarefa;
use App\Mcp\Tools\Referencias;
use App\Mcp\Tools\VerAgenda;
use App\Mcp\Tools\VerTarefa;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

/**
 * O AlfaMatriz como um conjunto de ferramentas para um agente — o quadro de
 * tarefas e a agenda, pela porta do Model Context Protocol.
 *
 * É o que deixa alguém dizer "abre uma tarefa para X" ou "marca reunião com Y
 * quinta às 10" ao Claude e a tarefa nascer AQUI, com a regra daqui. Cada
 * ferramenta espelha a rota da tela equivalente — validação, permissão e
 * serviço são os mesmos —, e por isso nenhuma delas tem regra própria: o que
 * a tela recusa, a ferramenta recusa com a mesma frase.
 *
 * Roda por stdio (`php artisan mcp:start alfamatriz`), sem rota HTTP: quem
 * chega até o processo já passou pela máquina. A identidade vem do processo
 * (ver `boot`), e não de um login — o agente age EM NOME de uma pessoa,
 * decisão do dono do produto em 28/09/2026, e é o nome dela que fica no
 * histórico.
 */
class AlfaMatrizServer extends Server
{
    protected string $name = 'AlfaMatriz';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Quadro de tarefas e agenda do time da AlfaTecnologia. Você age EM NOME do
        usuário identificado neste processo: tudo o que criar, mover ou responder
        sai com o nome dele.

        - Chame `referencias` quando precisar de nomes de pessoas, sistemas,
          etapas, prioridades ou categorias — as outras ferramentas aceitam nomes,
          não ids.
        - Antes de mover, leia a tarefa com `ver_tarefa` e passe em `de` a etapa
          que viu. Se alguém já moveu, a ferramenta recusa em vez de sobrescrever.
        - Tarefas são chamadas pelo código "#N". Datas em AAAA-MM-DD, horas em HH:MM.
        - Quem não faz triagem não escolhe prioridade nem responsável: a tarefa
          nasce na fila, e a ferramenta avisa quando deixou algo para a triagem.
    MARKDOWN;

    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        Referencias::class,
        ListarTarefas::class,
        VerTarefa::class,
        CriarTarefa::class,
        MoverTarefa::class,
        ConversarNaTarefa::class,
        ComentarTarefa::class,
        VerAgenda::class,
        MarcarCompromisso::class,
    ];

    /**
     * Quem o agente representa.
     *
     * Quem já chegou identificado — os testes, via `actingAs` — passa pelas
     * mesmas portas da tela (`conferir`) e segue. Pelo stdio ninguém chega
     * identificado, porque um processo de console não tem sessão, e o nome
     * vem do ambiente (`MCP_USUARIO`).
     *
     * `env()` e não `config()`, de propósito: a identidade é DO PROCESSO, não
     * do aplicativo. Cada agente sobe com o seu `MCP_USUARIO`, e uma config
     * cacheada em produção congelaria o primeiro nome que passasse por ali.
     * Variável de ambiente real continua visível a `env()` mesmo com o cache
     * de config ligado — só o `.env` deixa de ser lido.
     */
    protected function boot(): void
    {
        $usuario = Auth::user();

        if ($usuario instanceof User) {
            // Pela porta HTTP há a quem responder, e a resposta certa para uma
            // conta que a tela também recusaria é a mesma da tela: 403. Pelo
            // stdio não há resposta a dar, e a exceção derruba o processo.
            try {
                static::conferir($usuario);
            } catch (RuntimeException $e) {
                abort(403, $e->getMessage());
            }

            return;
        }

        Auth::setUser(static::usuarioDoProcesso(env('MCP_USUARIO')));
    }

    /** A pessoa por trás de um e-mail, já conferida. */
    public static function usuarioDoProcesso(?string $email): User
    {
        if (blank($email)) {
            throw new RuntimeException('Defina MCP_USUARIO com o e-mail de quem o agente representa.');
        }

        $usuario = User::query()->where('email', $email)->first();

        if (! $usuario) {
            throw new RuntimeException('Nenhum usuário com o e-mail '.$email.'.');
        }

        return static::conferir($usuario);
    }

    /**
     * As mesmas portas que a tela fecha: conta desativada (`ContaAtiva`) e
     * escopo de revenda (`bloquearVisaoDaMatriz`). Aqui elas recusam o
     * processo inteiro, porque não há tela para redirecionar — e um agente
     * agindo por uma conta desativada é exatamente o que a desativação existe
     * para impedir.
     */
    public static function conferir(User $usuario): User
    {
        if (! $usuario->ativo) {
            throw new RuntimeException('A conta '.$usuario->email.' está desativada.');
        }

        if ($usuario->temEscopoDeRevenda()) {
            throw new RuntimeException('A conta '.$usuario->email.' é de revenda; o quadro e a agenda são exclusivos da matriz.');
        }

        return $usuario;
    }
}
