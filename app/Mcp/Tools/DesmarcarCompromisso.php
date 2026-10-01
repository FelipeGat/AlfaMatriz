<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\AgendaService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Desmarcar pela porta do agente — o `CompromissoController::destroy`.
 * Quem pode é quem marcou, ou quem faz triagem; a regra e a frase são do
 * `AgendaService`, as mesmas da tela.
 */
class DesmarcarCompromisso extends Ferramenta
{
    protected string $name = 'desmarcar_compromisso';

    protected string $title = 'Desmarcar compromisso';

    protected string $description = 'Desmarca (apaga) um compromisso da agenda e avisa quem participava. Só quem marcou, ou quem faz triagem, pode. Não tem volta: confirme o número com ver_compromisso antes.';

    // A rota de desmarcar é `permissao:agenda,editar`, e não `excluir`.
    protected array $permissao = ['agenda', 'editar'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'compromisso' => $schema->string()->required()->description('O número, como "#7" ou 7.'),
        ];
    }

    protected function executar(Request $request, User $usuario): Response
    {
        $dados = $request->validate(['compromisso' => 'required|string|max:20']);

        $compromisso = $this->compromissoPeloNumero($dados['compromisso']);

        if (! $compromisso) {
            return Response::error('Não há compromisso '.$dados['compromisso'].'. Veja os números em ver_agenda.');
        }

        $descricao = '#'.$compromisso->id.' "'.$compromisso->titulo.'", de '
            .$compromisso->comecaEm()->format('d/m/Y').', '.$compromisso->intervalo();

        app(AgendaService::class)->desmarcar($compromisso, $usuario);

        return Response::text('Compromisso '.$descricao.' desmarcado.');
    }
}
