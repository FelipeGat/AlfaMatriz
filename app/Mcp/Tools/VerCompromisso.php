<?php

namespace App\Mcp\Tools;

use App\Models\Compromisso;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Um compromisso inteiro, pelo número — o que o modal da Agenda mostra.
 *
 * `ver_agenda` lista o dia; esta abre UM. Sem ela o agente marcava um
 * compromisso e não tinha como conferir o que tinha ficado gravado: em
 * 01/10/2026 ele disse ao dono "confira na tela se o #7 ficou certo".
 */
class VerCompromisso extends Ferramenta
{
    protected string $name = 'ver_compromisso';

    protected string $title = 'Ver compromisso';

    protected string $description = 'Tudo sobre um compromisso da agenda, pelo número que ver_agenda mostra: título, pauta, início e término, participantes, categoria, tarefa vinculada e se VOCÊ pode alterá-lo. Use para conferir depois de marcar ou remarcar.';

    protected array $permissao = ['agenda', 'ler'];

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

        return Response::text($this->fichaDoCompromisso($compromisso, $usuario));
    }
}
