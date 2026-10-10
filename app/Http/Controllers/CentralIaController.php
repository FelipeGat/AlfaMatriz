<?php

namespace App\Http\Controllers;

use App\Models\CentralIaMensagem;
use App\Services\CentralIa\ConversaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A tela da Central de IA e as duas chamadas que ela faz: buscar e enviar.
 *
 * Tudo é da conta logada — não há id de conversa nem de usuário na rota, de
 * propósito: o que não chega pela requisição não pode ser trocado por outro.
 */
class CentralIaController extends Controller
{
    public function __construct(private readonly ConversaService $conversas) {}

    public function index(Request $request): View
    {
        return view('central-ia.index', [
            'podeEnviar' => $request->user()->canPermissao('central_ia', 'incluir'),
        ]);
    }

    public function mensagens(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'antes' => ['nullable', 'integer', 'min:1', 'prohibits:depois'],
            'depois' => ['nullable', 'integer', 'min:0'],
        ]);

        [$mensagens, $haMais] = $this->conversas->historico(
            $request->user(),
            isset($dados['antes']) ? (int) $dados['antes'] : null,
            isset($dados['depois']) ? (int) $dados['depois'] : null,
        );

        return $this->semCache([
            'mensagens' => $mensagens->map(fn (CentralIaMensagem $m) => $this->publica($m))->all(),
            'ha_mais' => $haMais,
            'telegram_vinculado' => false,
        ]);
    }

    public function enviar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'conteudo' => ['required', 'string', 'max:'.config('central_ia.max_texto')],
            'client_id' => ['required', 'uuid'],
        ]);

        $mensagem = $this->conversas->registrarDoUsuario(
            $request->user(), $dados['conteudo'], CentralIaMensagem::ORIGEM_WEB, $dados['client_id'],
        );

        return $this->semCache(['id' => $mensagem->id], $mensagem->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Só o que a tela mostra. `user_id`, `conversa_id` e o id do Telegram
     * ficam no servidor.
     */
    private function publica(CentralIaMensagem $m): array
    {
        return [
            'id' => $m->id,
            'client_id' => $m->client_id,
            'autor_tipo' => $m->autor_tipo,
            'autor_nome' => $m->autor_nome,
            'origem' => $m->origem,
            'conteudo' => $m->conteudo,
            'criada_em' => $m->created_at->toIso8601String(),
        ];
    }

    /**
     * Conversa privada não vai para cache de navegador nem de proxy.
     */
    private function semCache(array $dados, int $status = 200): JsonResponse
    {
        return response()->json($dados, $status)->header('Cache-Control', 'no-store, private');
    }
}
