<?php

namespace App\Services;

use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * O mesmo pedido aberto duas vezes (#205): achar antes, e registrar depois.
 *
 * Nasceu da #185 e da #191 — o mesmo defeito, aberto no sistema errado e de
 * novo quatro dias depois, com outra prioridade. Ninguém errou de má vontade:
 * quem abriu a segunda não tinha como saber da primeira sem procurar, e a
 * triagem não tinha por que procurar.
 *
 * As duas metades moram juntas porque respondem à mesma pergunta — "isto já
 * existe?" —, e a tela, o servidor MCP e o lembrete da triagem perguntam por
 * aqui. Copiada na ferramenta, a régua de "parecida" divergiria da tela na
 * primeira vez que alguém a ajustasse de um lado só.
 */
class DuplicidadeDeTarefas
{
    /**
     * Palavras que aparecem em quase todo título e não distinguem pedido
     * nenhum: as vazias do português e as genéricas do quadro. "Corrigir" está
     * em metade dos defeitos; casar por ela traria a metade inteira.
     *
     * Sem acento, porque a comparação é feita depois de `Str::ascii`.
     */
    private const PALAVRAS_VAZIAS = [
        'a', 'o', 'e', 'as', 'os', 'ao', 'aos', 'um', 'uma', 'uns', 'umas',
        'de', 'da', 'do', 'das', 'dos', 'em', 'no', 'na', 'nos', 'nas',
        'para', 'pra', 'pro', 'por', 'pelo', 'pela', 'pelos', 'pelas', 'com', 'sem',
        'que', 'se', 'ou', 'mas', 'mais', 'menos', 'muito', 'muita', 'como', 'quando',
        'onde', 'qual', 'quais', 'quem', 'nao', 'sim', 'ja', 'ainda', 'tambem', 'so',
        'ser', 'esta', 'este', 'estao', 'isso', 'isto', 'essa', 'esse', 'aquele', 'aquela',
        'seu', 'sua', 'seus', 'suas', 'ele', 'ela', 'eles', 'elas', 'nem', 'tem', 'ter',
        'foi', 'sao', 'vai', 'era', 'entre', 'sobre', 'ate', 'apos', 'depois', 'antes',
        'todo', 'toda', 'todos', 'todas', 'outro', 'outra', 'cada', 'aqui', 'ali', 'agora',
        'tarefa', 'tarefas', 'sistema', 'tela', 'erro', 'erros', 'bug', 'problema',
        'corrigir', 'correcao', 'ajuste', 'ajustar', 'ajustes', 'fazer', 'criar',
        'melhoria', 'melhorar', 'novo', 'nova', 'deve', 'precisa', 'esta',
    ];

    /**
     * Quantas tarefas EM CURSO entram na comparação, das mais novas para as
     * mais antigas. É teto de segurança, não recorte: o quadro em curso do
     * time é de dezenas — e o pedido repetido costuma ser recente.
     */
    private const TETO_DE_CANDIDATAS = 1000;

    /**
     * As tarefas em curso que parecem o mesmo pedido, da mais parecida para a
     * menos.
     *
     * A régua é de propósito simples — palavras em comum —, porque o aviso não
     * bloqueia nada: errar para mais custa uma linha a ler, errar para menos
     * custa o card repetido que motivou a #205. Por isso o sistema SOMA e não
     * filtra: a #191 só não era a #185 porque a #185 estava no sistema errado,
     * e filtrar por sistema esconderia exatamente o caso que se quer pegar.
     *
     * A comparação acontece aqui, e não num `LIKE` por palavra: um `LIKE
     * '%x%'` não usa índice, então o banco leria as mesmas linhas de qualquer
     * jeito — e o MySQL de produção ignora acento enquanto o SQLite dos testes
     * não, o que faria a suíte aprovar uma régua diferente da que roda no ar.
     * Normalizada no PHP, "integração" e "integracao" casam nos dois.
     *
     * @return Collection<int, Tarefa> cada uma com `sistema` carregado
     */
    public function parecidas(string $titulo, ?string $resumo = null, ?int $sistemaId = null, ?int $ignorarId = null, int $limite = 5): Collection
    {
        $doTitulo = $this->termos($titulo);

        if ($doTitulo === []) {
            return collect();
        }

        $todos = array_values(array_unique([...$doTitulo, ...$this->termos((string) $resumo)]));

        // Título de uma palavra só ("Wellhub") casa com uma; os demais pedem
        // duas, para "relatório de vendas" não trazer todo relatório do quadro.
        $minimo = min(2, count($doTitulo));

        return Tarefa::query()
            ->with('sistema')
            ->whereNotIn('status', Tarefa::STATUS_TERMINAIS)
            ->when($ignorarId, fn ($consulta) => $consulta->whereKeyNot($ignorarId))
            ->latest('id')
            ->limit(self::TETO_DE_CANDIDATAS)
            ->get()
            ->map(function (Tarefa $candidata) use ($doTitulo, $todos, $minimo, $sistemaId) {
                $tituloDela = $this->termos($candidata->titulo);
                $dela = array_unique([...$tituloDela, ...$this->termos((string) $candidata->resumo)]);

                // O TÍTULO de quem está criando é o que decide se parece: um
                // resumo longo casaria por acaso com metade do quadro.
                if (count(array_intersect($doTitulo, $dela)) < $minimo) {
                    return null;
                }

                $pontos = 3 * count(array_intersect($doTitulo, $tituloDela))
                    + count(array_intersect($todos, $dela))
                    + ($sistemaId && $candidata->sistema_id === $sistemaId ? 2 : 0);

                return ['tarefa' => $candidata, 'pontos' => $pontos];
            })
            ->filter()
            ->sortBy([['pontos', 'desc'], [fn ($a, $b) => $b['tarefa']->id <=> $a['tarefa']->id]])
            ->take($limite)
            ->pluck('tarefa')
            ->values();
    }

    /**
     * Marca a tarefa como duplicada da original — o que a CANCELA, com o
     * vínculo gravado.
     *
     * Cancelar e não excluir: excluir é para a tarefa que nunca deveria ter
     * existido, e a duplicada existiu — alguém sentiu o defeito de novo, e
     * isso é informação para quem triaga a original. O cancelamento passa
     * pelo motor do fluxo, com o motivo dito, para o histórico e o aviso de
     * encerramento serem os de qualquer cancelamento.
     *
     * Recusa com a frase pronta (`RuntimeException`), como o motor: a tela
     * mostra no flash, e o servidor MCP devolve ao agente.
     */
    public function marcar(Tarefa $tarefa, Tarefa $original, User $autor): Tarefa
    {
        if ($impedimento = $tarefa->motivoParaNaoMover($autor)) {
            throw new \RuntimeException($impedimento);
        }

        if ($tarefa->is($original)) {
            throw new \RuntimeException('Uma tarefa não é duplicada de si mesma.');
        }

        if (in_array($tarefa->status, Tarefa::STATUS_TERMINAIS, true)) {
            throw new \RuntimeException('A tarefa '.$tarefa->codigo().' já está encerrada. Só tarefa em curso é marcada como duplicada.');
        }

        // A cadeia é cortada na origem: apontar para uma duplicada faria quem
        // segue o vínculo chegar a uma tarefa cancelada e ter de seguir de novo.
        if ($original->duplicada_de_id) {
            throw new \RuntimeException('A tarefa '.$original->codigo().' já é duplicada da #'.$original->duplicada_de_id.'. Aponte para a #'.$original->duplicada_de_id.'.');
        }

        if ($original->status === 'cancelada') {
            throw new \RuntimeException('A tarefa '.$original->codigo().' está cancelada e não pode ser a original. Reabra-a ou mantenha esta.');
        }

        return DB::transaction(function () use ($tarefa, $original) {
            app(FluxoTarefaService::class)->mover($tarefa, 'cancelada', [
                'motivo' => 'Duplicada de '.$original->codigo().' · '.Str::limit($original->titulo, 120),
            ]);

            $tarefa->forceFill(['duplicada_de_id' => $original->id])->save();

            return $tarefa->refresh();
        });
    }

    /**
     * As palavras que distinguem um texto, reduzidas a uma raiz curta.
     *
     * Raiz de seis letras, sem o plural: "integração", "integrações" e
     * "integrar" viram "integr"; "alunos" vira "aluno". É o radical barato do
     * português — não acerta tudo, e não precisa: o aviso é para ler, não para
     * decidir. Abaixo de três letras não distingue nada.
     *
     * @return list<string>
     */
    private function termos(string $texto): array
    {
        $palavras = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($texto)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $termos = [];

        foreach ($palavras as $palavra) {
            if (strlen($palavra) < 3 || ctype_digit($palavra) || in_array($palavra, self::PALAVRAS_VAZIAS, true)) {
                continue;
            }

            if (strlen($palavra) > 4 && str_ends_with($palavra, 's')) {
                $palavra = substr($palavra, 0, -1);
            }

            $termos[] = substr($palavra, 0, 6);
        }

        return array_values(array_unique($termos));
    }
}
