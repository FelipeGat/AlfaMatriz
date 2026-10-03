<?php

namespace App\Http\Controllers;

use App\Services\RegistroDeAtualizacoes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * Recebe o changelog do `deploy/publicar-changelog.sh` (#225).
 *
 * Responde em JSON e diz em frase o que recusou: quem lê a resposta é o
 * script, que a repete no terminal de quem publicou. Sem escapar os acentos
 * (`JSON_UNESCAPED_UNICODE`): o script recorta a frase com `sed`, e "N\u00e3o
 * h\u00e1" no terminal não é frase.
 */
class AtualizacaoController extends Controller
{
    public function __invoke(Request $request, RegistroDeAtualizacoes $registro): JsonResponse
    {
        $usuario = $request->user();

        // As mesmas portas da tela: conta desativada e revenda não entram, e
        // quem registra precisa poder incluir na tela de Manutenção. O token
        // sobrevive a quem o emitiu perder o acesso; a permissão, não.
        if (! $usuario->ativo || $usuario->temEscopoDeRevenda() || ! $usuario->canPermissao('manutencao', 'incluir')) {
            return response()->json(['message' => 'Esta conta não pode registrar atualizações.'], 403, [], JSON_UNESCAPED_UNICODE);
        }

        $validacao = Validator::make($request->all(), [
            'texto' => 'required|string|max:60000',
            'versao' => 'nullable|string|max:60',
            'arquivo' => 'nullable|string|max:255',
            'origem' => 'nullable|in:script,importado',
            'tarefas' => 'nullable|string|max:2000',
        ]);

        if ($validacao->fails()) {
            return response()->json(['message' => $validacao->errors()->first(), 'errors' => $validacao->errors()], 422, [], JSON_UNESCAPED_UNICODE);
        }

        $dados = $validacao->validated();

        // `tarefas` chega como texto ("T-224 T-225", "224,225"): é o que um
        // script em bash monta sem esforço. Só os números importam.
        preg_match_all('/\d+/', (string) ($dados['tarefas'] ?? ''), $numeros);

        try {
            ['atualizacao' => $atualizacao, 'nova' => $nova] = $registro->registrar(
                $dados['texto'],
                $dados['versao'] ?? null,
                $numeros[0],
                $usuario,
                $dados['origem'] ?? 'script',
                $dados['arquivo'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422, [], JSON_UNESCAPED_UNICODE);
        }

        return response()->json([
            'message' => $nova ? 'registrada' : 'já estava registrada',
            'id' => $atualizacao->id,
            'sistema' => $atualizacao->sistema->nome,
            'data' => $atualizacao->data->format('d/m/Y'),
            'versao' => $atualizacao->versao,
            'tarefas' => $atualizacao->tarefas->map->codigo()->values(),
        ], $nova ? 201 : 200, [], JSON_UNESCAPED_UNICODE);
    }
}
