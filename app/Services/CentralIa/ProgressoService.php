<?php

namespace App\Services\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaRunner;
use App\Models\CentralIaTarefa;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProgressoService
{
    public const INTERVALO = 90;

    /** Na fila nada muda a cada 90 s: avisar de 10 em 10 min basta. */
    public const INTERVALO_FILA = 600;

    public const ETAPAS = [
        'preparando' => 'Preparando a execução',
        'confirmacao' => 'Esperando o seu código de confirmação',
        'anexos' => 'Preparando os anexos',
        'agente' => 'Aguardando resposta do agente',
        'comando' => 'Executando uma ferramenta ou comando',
        'resposta' => 'Recebendo saída do agente',
        'finalizando' => 'Registrando o resultado',
    ];

    public function consulta(string $texto): bool
    {
        $t = trim(Str::lower(Str::ascii(Str::squish($texto))), " ?!.…");
        return in_array($t, ['/status', 'status', 'como estamos', 'como esta', 'como esta o andamento',
            'qual o status', 'qual o andamento', 'travou', 'esta travado', 'ainda esta executando'], true);
    }

    public function registrar(User $user, string $maquina, int $id, array $dados): void
    {
        DB::transaction(function () use ($user, $maquina, $id, $dados) {
            $runner = CentralIaRunner::where('user_id', $user->id)->where('maquina', $maquina)->first();
            $e = CentralIaExecucao::where('user_id', $user->id)->whereKey($id)->lockForUpdate()->first();
            abort_unless($runner && $e && $e->runner_id === $runner->id, 404);
            if ($e->status !== CentralIaExecucao::EXECUTANDO || ($e->progresso['sequencia'] ?? 0) >= $dados['sequencia']) {
                return;
            }
            // Apenas metadados: nunca publicar comandos, prompts ou saída bruta.
            $e->update(['progresso' => array_intersect_key($dados, array_flip(['sequencia', 'etapa', 'atividade_em', 'processo_ativo'])),
                'progresso_em' => now()]);
        });
    }

    public function publicar(?User $user = null): void
    {
        $ids = CentralIaExecucao::whereIn('status', [CentralIaExecucao::PENDENTE, CentralIaExecucao::EXECUTANDO])
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->where('created_at', '<=', now()->subSeconds(self::INTERVALO))
            ->where(fn ($q) => $q->whereNull('status_avisado_em')->orWhere('status_avisado_em', '<=', now()->subSeconds(self::INTERVALO)))
            ->orderBy('id')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id) {
                $e = CentralIaExecucao::whereKey($id)->lockForUpdate()->first();
                // Na fila: o primeiro aviso aos 90 s, os seguintes de 10 em 10 min.
                // Tarefa arquivada suspende a fila (reversível): sem aviso.
                $intervalo = $e?->status === CentralIaExecucao::PENDENTE && $e->status_avisado_em ? self::INTERVALO_FILA : self::INTERVALO;
                if ($e?->status === CentralIaExecucao::PENDENTE && $e->tarefa?->status === CentralIaTarefa::ARQUIVADA) {
                    return;
                }
                if (! $e || ! in_array($e->status, [CentralIaExecucao::PENDENTE, CentralIaExecucao::EXECUTANDO], true)
                    || ($e->status_avisado_em ?? $e->created_at)->greaterThan(now()->subSeconds($intervalo))) {
                    return;
                }
                $dono = User::find($e->user_id);
                if (! $dono || ! $dono->ativo || $dono->temEscopoDeRevenda() || ! $dono->canPermissao('central_ia', 'ler')) {
                    return;
                }
                // Na tela o andamento já aparece ao vivo, embaixo da conversa:
                // gravar aviso a cada 90 s só enchia o histórico (dono,
                // 12/10/2026: "para uma pergunta simples, várias notificações").
                // Vira mensagem só com o atendimento no Telegram.
                $canal = app(CanalAtivo::class);
                if ($canal->pronto() && $canal->ativo($dono) !== 'telegram') {
                    $e->update(['status_avisado_em' => now()]);
                    return;
                }
                app(ConversaService::class)->registrarDoAgente($dono, 'Central de IA', $this->texto($e),
                    CentralIaMensagem::AUTOR_SISTEMA, $e->projeto ?: null, $e->tarefa_id);
                $e->update(['status_avisado_em' => now()]);
            });
        }
    }

    public function texto(CentralIaExecucao $e): string
    {
        $projeto = $e->tarefa?->ehAssunto() ? '“'.$e->tarefa->titulo.'”' : ($e->projeto ?: 'Geral');
        $cabecalho = "{$projeto} — pedido #{$e->id}".($e->tarefa_id ? " · tarefa #{$e->tarefa_id}" : '')." · {$e->nomeDoAgente()}";
        $tempo = $this->tempo($e->iniciada_em ?? $e->created_at);
        if ($e->status === CentralIaExecucao::PENDENTE) {
            $online = CentralIaRunner::where('user_id', $e->user_id)->get()->contains(fn ($r) => $r->online()
                && in_array($e->agente, $r->agentes, true) && ($e->projeto === '' || in_array($e->projeto, $r->projetos, true)));
            $ocupada = $e->tarefa_id && CentralIaExecucao::where('tarefa_id', $e->tarefa_id)->where('status', CentralIaExecucao::EXECUTANDO)->exists();
            return "$cabecalho\nNa fila há {$tempo}. ".(! $online ? 'Máquina sem conexão recente; aguardando reconexão.'
                : ($ocupada ? 'Aguardando a execução anterior da mesma tarefa terminar.' : 'Aguardando uma vaga no runner.'));
        }
        if ($e->aguardandoConfirmacao()) {
            return "$cabecalho\nEsperando o seu código há {$tempo}. A sua máquina mandou o pedido e o código ao seu Telegram; digite-o na Central ou responda lá com /confirmar <código>.";
        }
        $p = $e->progresso ?? [];
        $etapa = self::ETAPAS[$p['etapa'] ?? ''] ?? 'Sem etapa informada pelo runner';
        if (! $e->progresso_em || $e->progresso_em->lessThanOrEqualTo(now()->subSeconds(self::INTERVALO))) {
            return "$cabecalho\nEm execução há {$tempo}. Sem confirmação recente desta execução; não é possível confirmar atividade agora. Última etapa: {$etapa}.";
        }
        $atividade = ! empty($p['atividade_em']) ? 'Última saída do processo há '.$this->tempo(Carbon::parse($p['atividade_em'])).'.' : 'O processo ainda não informou saída.';
        $processo = ($p['processo_ativo'] ?? null) === true ? 'Processo do agente ativo.'
            : (($p['processo_ativo'] ?? null) === false ? 'Processo do agente encerrado.' : 'Processo do agente ainda não iniciado.');
        return "$cabecalho\nEm execução há {$tempo}: {$etapa}. {$processo} {$atividade} Confirmação do runner há ".$this->tempo($e->progresso_em).'.';
    }

    public function resumo(User $user, ?string $projeto = null, ?int $tarefa = null): string
    {
        $consulta = CentralIaExecucao::where('user_id', $user->id)
            ->when($projeto !== null, fn ($q) => $q->where('projeto', $projeto))
            ->when($tarefa, fn ($q) => $q->where('tarefa_id', $tarefa));
        $ativas = (clone $consulta)->whereIn('status', [CentralIaExecucao::PENDENTE, CentralIaExecucao::EXECUTANDO])->orderBy('id')->get();
        $linhas = $ativas->map(fn ($e) => $this->texto($e))->all();
        foreach (CentralIaTarefa::de($user)->where('status', CentralIaTarefa::AGUARDANDO_VOCE)
            ->when($projeto !== null, fn ($q) => $q->where('projeto', $projeto))->when($tarefa, fn ($q) => $q->whereKey($tarefa))->get() as $t) {
            $linhas[] = "Tarefa #{$t->id} em {$t->projeto} aguarda você: ".Str::limit($t->dossie['duvida'] ?? $t->titulo, 300);
        }
        if (! $linhas && ($ultima = (clone $consulta)->orderByDesc('id')->first())) {
            $linhas[] = 'Último pedido #'.$ultima->id.' em '.($ultima->tarefa?->ehAssunto() ? '“'.$ultima->tarefa->titulo.'”' : ($ultima->projeto ?: 'Geral')).': '
                .(['concluida' => 'concluído', 'falhou' => 'falhou', 'expirada' => 'expirou'][$ultima->status] ?? $ultima->status)
                .($ultima->erro ? '. '.Str::limit($ultima->erro, 300) : '.');
        }
        return "Status observado pela Central:\n".($linhas ? implode("\n\n", $linhas) : 'Nada rodando nem esperando você.');
    }

    private function tempo(Carbon $desde): string
    {
        $s = max(0, (int) $desde->diffInSeconds(now(), false));
        return $s < 60 ? "{$s} s" : intdiv($s, 60).' min '.($s % 60).' s';
    }
}
