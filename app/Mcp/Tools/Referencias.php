<?php

namespace App\Mcp\Tools;

use App\Models\Compromisso;
use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * O vocabulário do quadro, para o agente não adivinhar nomes.
 *
 * As outras ferramentas aceitam NOMES — de pessoa, de sistema, de etapa — e
 * não ids, porque é assim que quem comanda o agente fala ("passa para a
 * Ana", "no AlfaGym"). Esta é a lista de onde esses nomes saem.
 */
class Referencias extends Ferramenta
{
    protected string $name = 'referencias';

    protected string $title = 'Referências';

    protected string $description = 'Os nomes que as outras ferramentas aceitam: pessoas do time (e quem faz triagem), sistemas, etapas do quadro, prioridades, tipos de tarefa e categorias de compromisso. Chame quando não souber um nome.';

    protected array $permissao = ['tarefas', 'ler'];

    protected function executar(Request $request, User $usuario): Response
    {
        $pessoas = User::query()
            ->where('ativo', true)
            ->whereNull('revenda_id')
            ->orderBy('name')
            ->get()
            ->map(fn (User $pessoa) => '- '.$pessoa->name
                .($pessoa->id === $usuario->id ? ' (você)' : '')
                .($pessoa->podeTriarTarefas() ? ' · faz triagem' : ''));

        $sistemas = Sistema::query()
            ->where('ativo', true)
            ->orderBy('nome')
            ->pluck('nome')
            ->map(fn (string $nome) => '- '.$nome);

        $vocabulario = fn (array $mapa) => collect($mapa)
            ->map(fn ($rotulo, $chave) => $chave.' = '.(is_array($rotulo) ? $rotulo['rotulo'] : $rotulo))
            ->implode(', ');

        return Response::text(implode("\n", [
            'Você: '.$usuario->name.($usuario->podeTriarTarefas() ? ' (faz triagem)' : ' (não faz triagem: prioridade e responsável ficam para a triagem)'),
            '',
            'Pessoas:',
            $pessoas->implode("\n"),
            '',
            'Sistemas:',
            $sistemas->isEmpty() ? '- (nenhum ativo)' : $sistemas->implode("\n"),
            '',
            'Etapas (chave = nome): '.$vocabulario(Tarefa::STATUS),
            'Prioridades: '.$vocabulario(Tarefa::PRIORIDADES),
            'Tipos de tarefa: '.$vocabulario(Tarefa::TIPOS),
            'Categorias de compromisso: '.$vocabulario(Compromisso::CATEGORIAS),
        ]));
    }
}
