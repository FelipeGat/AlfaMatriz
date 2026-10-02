<?php

namespace App\Services;

use App\Models\Notificacao;
use App\Models\Tarefa;
use App\Models\TarefaReferenciaGit;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * O que o GitHub conta ao quadro (#211): commits e PRs que citam `T-N` viram
 * referências na tarefa N.
 *
 * Os devs trabalham por PR, e o que fizeram não chegava ao quadro — hashes e
 * links eram colados à mão, quando eram. Aqui o repositório alimenta a
 * tarefa sozinho, e a entrega (#210) nasce pré-preenchida com o que chegou.
 *
 * Ninguém está logado: quem chama é o webhook. Por isso nada aqui move
 * tarefa nem fala em nome de alguém — registra, e no máximo avisa pelo sino.
 */
class ReferenciasDoGitHub
{
    /**
     * A marca da tarefa: `T-173` (ou `t-173`), decisão do dono em 02/10/2026.
     *
     * NÃO `#173`: nos repositórios `#N` já é PR ou issue do próprio GitHub
     * ("(#74)", "revisão do PR #76"), e ligaria o commit à tarefa errada sem
     * ninguém perceber. As fronteiras são de palavra dos dois lados: `AT-173`
     * é outra coisa, e `T-1734` é a 1734, nunca a 173.
     */
    private const MARCA = '/(?<![\p{L}\p{N}_])[Tt]-(\d{1,9})(?![\p{L}\p{N}_])/u';

    /** As ações de PR que este quadro escuta. O resto chega e é ignorado. */
    private const ACOES_DO_PR = ['opened', 'reopened', 'edited', 'closed', 'synchronize'];

    /**
     * Os números de tarefa citados num texto, sem repetição.
     *
     * @return list<int>
     */
    public static function marcas(?string $texto): array
    {
        preg_match_all(self::MARCA, (string) $texto, $achados);

        return array_values(array_unique(array_map('intval', $achados[1] ?? [])));
    }

    /**
     * Trata um evento do GitHub e diz quantas referências criou ou mexeu.
     *
     * Evento que não interessa devolve 0 sem erro: o webhook de um repositório
     * costuma mandar de tudo, e cada recusa viraria um "falhou" vermelho na
     * tela de entregas do GitHub, escondendo as falhas de verdade.
     */
    public function tratar(string $evento, array $carga): int
    {
        return match ($evento) {
            'push' => $this->push($carga),
            'pull_request' => $this->pullRequest($carga),
            default => 0,
        };
    }

    private function push(array $carga): int
    {
        $ref = (string) ($carga['ref'] ?? '');

        // Só branch. Push de tag traz o commit que JÁ chegou pela branch — e
        // a tag é o gesto de publicar, que é do dono, não do código.
        if (! str_starts_with($ref, 'refs/heads/')) {
            return 0;
        }

        $branch = substr($ref, strlen('refs/heads/'));
        $repositorio = (string) data_get($carga, 'repository.full_name', '');
        $mexidas = 0;

        foreach ((array) ($carga['commits'] ?? []) as $commit) {
            $sha = (string) ($commit['id'] ?? '');
            $mensagem = (string) ($commit['message'] ?? '');

            if ($sha === '' || $repositorio === '') {
                continue;
            }

            foreach ($this->tarefasAbertas(self::marcas($mensagem)) as $tarefa) {
                // `createOrFirst`, e não um `exists` antes do `create`: o
                // GitHub manda o mesmo commit em dois webhooks quase juntos
                // (push na branch e o merge), e a corrida entre a consulta e a
                // gravação é resolvida pelo índice único, não pela sorte.
                $referencia = TarefaReferenciaGit::createOrFirst(
                    ['tarefa_id' => $tarefa->id, 'tipo' => TarefaReferenciaGit::TIPO_COMMIT, 'chave' => $sha],
                    [
                        'repositorio' => $repositorio,
                        // Só a primeira linha: o corpo da mensagem é para o
                        // `git log`, e no modal empurraria a lista para baixo.
                        'titulo' => Str::limit(strtok($mensagem, "\n") ?: $sha, 250),
                        'url' => Str::limit((string) ($commit['url'] ?? ''), 500, ''),
                        'autor_github' => $this->autorDoCommit($commit),
                        'branch' => Str::limit($branch, 255, ''),
                    ],
                );

                $mexidas += $referencia->wasRecentlyCreated ? 1 : 0;
            }
        }

        return $mexidas;
    }

    private function pullRequest(array $carga): int
    {
        $acao = (string) ($carga['action'] ?? '');
        $pr = (array) ($carga['pull_request'] ?? []);
        $repositorio = (string) data_get($carga, 'repository.full_name', '');
        $numero = (int) ($pr['number'] ?? $carga['number'] ?? 0);

        if (! in_array($acao, self::ACOES_DO_PR, true) || $numero <= 0 || $repositorio === '') {
            return 0;
        }

        $chave = $repositorio.'#'.$numero;

        // As tarefas citadas AGORA, mais as que o PR já tinha: o PR que teve
        // a marca tirada do título na edição e depois foi mesclado precisa
        // continuar dizendo "mesclado" na tarefa que ele de fato entregou.
        // Desligar uma referência por edição não é decisão que o quadro tome
        // sozinho.
        $citadas = self::marcas(($pr['title'] ?? '')."\n".($pr['body'] ?? ''));
        $jaLigadas = TarefaReferenciaGit::where('tipo', TarefaReferenciaGit::TIPO_PR)
            ->where('chave', $chave)->pluck('tarefa_id')->all();

        $estado = ! empty($pr['merged']) || ! empty($pr['merged_at'])
            ? 'mesclado'
            : (($pr['state'] ?? 'open') === 'closed' ? 'fechado' : 'aberto');

        $mexidas = 0;

        foreach ($this->tarefasAbertas(array_merge($citadas, $jaLigadas)) as $tarefa) {
            $referencia = TarefaReferenciaGit::createOrFirst(
                ['tarefa_id' => $tarefa->id, 'tipo' => TarefaReferenciaGit::TIPO_PR, 'chave' => $chave],
                ['repositorio' => $repositorio, 'numero' => $numero, 'titulo' => '', 'url' => ''],
            );

            // Toda chegada reescreve título, estado e branch — o PR muda de
            // nome e de estado — e TOCA o `updated_at` mesmo sem mudança: é
            // ele que diz à sugestão da próxima entrega que o PR se mexeu
            // (os commits da correção chegam como `synchronize`).
            $referencia->fill([
                'repositorio' => $repositorio,
                'numero' => $numero,
                'titulo' => Str::limit((string) ($pr['title'] ?? $chave), 250),
                'url' => Str::limit((string) ($pr['html_url'] ?? ''), 500, ''),
                'autor_github' => Str::limit((string) data_get($pr, 'user.login', ''), 100, '') ?: null,
                'estado' => $estado,
                'branch' => Str::limit((string) data_get($pr, 'head.ref', ''), 255, '') ?: null,
            ]);
            $referencia->updated_at = now();
            $referencia->save();

            $mexidas++;

            if ($acao === 'opened') {
                $this->avisarPrAberto($tarefa, $referencia);
            }
        }

        return $mexidas;
    }

    /**
     * PR aberto avisa o responsável — e NÃO move a tarefa (decisão do dono).
     *
     * Abrir PR não é mandar para a revisão: a entrega (#210) pede o que foi
     * feito e como testar, que só o dev escreve. O sino lembra o passo, e o
     * painel do envio já vem com o PR escrito. Só em Em andamento: fora da
     * bancada o lembrete chegaria tarde ou cedo demais.
     */
    private function avisarPrAberto(Tarefa $tarefa, TarefaReferenciaGit $pr): void
    {
        if ($tarefa->status !== 'em_desenvolvimento') {
            return;
        }

        Notificacao::avisar($tarefa->responsavel_id, null, [
            'tipo' => 'pr_aberto',
            'nivel' => 'marca',
            'icone' => 'code',
            'titulo' => 'PR aberto em «'.$tarefa->titulo.'»',
            'meta' => 'PR #'.$pr->numero.' · '.$pr->repositorio.' — mande para revisão com a entrega',
            'rota' => route('tarefas.index'),
            'tarefa_id' => $tarefa->id,
        ]);
    }

    /**
     * As tarefas citadas que ainda recebem código — encerrada ou apagada fica
     * de fora, sem erro: commit com `T-N` antigo (um revert, um cherry-pick)
     * não reabre nem enfeita tarefa que já fechou.
     *
     * @param  array<int>  $numeros
     * @return Collection<int, Tarefa>
     */
    private function tarefasAbertas(array $numeros): Collection
    {
        $numeros = array_values(array_unique(array_filter($numeros)));

        if ($numeros === []) {
            return collect();
        }

        return Tarefa::whereIn('id', $numeros)
            ->whereNotIn('status', Tarefa::STATUS_TERMINAIS)
            ->get();
    }

    /** O login do GitHub quando veio; o nome do git, quando não. */
    private function autorDoCommit(array $commit): ?string
    {
        $autor = (string) (data_get($commit, 'author.username') ?: data_get($commit, 'author.name', ''));

        return $autor === '' ? null : Str::limit($autor, 100, '');
    }
}
