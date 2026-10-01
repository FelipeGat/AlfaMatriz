<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * O veredito do teste — o botão "Testar" do card (`TarefaController::testar`).
 *
 * É o carimbo que libera a etapa seguinte: aprovado no staging deixa a tarefa
 * subir para produção, aprovado em produção deixa encerrá-la. Quem carimba
 * afirma ter CONFERIDO; a descrição diz isso ao agente com todas as letras,
 * porque um veredito dado sem conferir é o pior dado que o quadro pode ter.
 */
class RegistrarVeredito extends Ferramenta
{
    protected string $name = 'registrar_veredito';

    protected string $title = 'Registrar veredito do teste';

    protected string $description = 'Registra o veredito do teste de uma tarefa de desenvolvimento que está em Em staging ou Em produção: aprovado ou reprovado. Só registre o que foi de fato conferido, por você ou pela pessoa — é este carimbo que libera a etapa seguinte. Reprovar exige dizer o que reprovou. Se a validação foi apontada para alguém, só essa pessoa registra.';

    protected array $permissao = ['tarefas', 'editar'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tarefa' => $schema->string()->required()->description('O código, como "#128".'),
            'aprovado' => $schema->boolean()->required()->description('true se passou, false se reprovou.'),
            'notas' => $schema->string()->max(2000)->description('O que foi conferido. Obrigatório ao reprovar: o que não passou.'),
        ];
    }

    protected function executar(Request $request, User $usuario): Response
    {
        $dados = $request->validate([
            'tarefa' => 'required|string|max:20',
            'aprovado' => 'required|boolean',
            'notas' => 'nullable|string|max:2000',
        ]);

        $tarefa = $this->tarefaPeloCodigo($dados['tarefa']);

        if (! $tarefa) {
            return Response::error('Não há tarefa '.$dados['tarefa'].'.');
        }

        $aprovado = filter_var($dados['aprovado'], FILTER_VALIDATE_BOOLEAN);

        app(FluxoTarefaService::class)->registrarVeredito($tarefa, $usuario, $aprovado, $dados['notas'] ?? null);

        return Response::text(
            ($tarefa->status === 'em_producao' ? 'Validação em produção' : 'Teste do staging')
            .' da tarefa '.$tarefa->codigo().' registrado: '.($aprovado ? 'aprovado' : 'reprovado').'.'
        );
    }
}
