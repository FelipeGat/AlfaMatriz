<?php

namespace App\Http\Controllers;

use App\Models\CentralIaTelegramBot;
use App\Models\CentralIaTelegramVinculo;
use App\Services\CentralIa\Maquinas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Conectar minha máquina" (T-374) — ver App\Services\CentralIa\Maquinas.
 *
 * Duas metades. A da tela (sessão, CSRF, permissão): lista, gera o código de
 * instalação e desconecta. A do terminal (fora do grupo `web`, sem sessão):
 * entrega o instalador e os arquivos do runner — que não têm segredo nenhum —
 * e troca o código de uso único pelo token.
 */
class CentralIaMaquinasController extends Controller
{
    public function __construct(private readonly Maquinas $maquinas) {}

    public function index(Request $request): JsonResponse
    {
        return $this->semCache([
            'maquinas' => $this->maquinas->lista($request->user()),
            'pode_conectar' => $this->maquinas->podeConectar($request->user()),
            'comando_atualizar' => $this->maquinas->comando(),
            // Sem bot vinculado, a máquina conecta, mas recusa modificar
            // arquivos: não teria por onde mandar o código.
            'telegram_pronto' => CentralIaTelegramBot::desteServidor()->where('user_id', $request->user()->id)->exists()
                && CentralIaTelegramVinculo::where('user_id', $request->user()->id)->exists(),
        ]);
    }

    public function instalacao(Request $request): JsonResponse
    {
        $codigo = $this->maquinas->iniciar($request->user());

        return $this->semCache([
            'comando' => $this->maquinas->comando($codigo),
            'minutos' => Maquinas::VALIDADE_MINUTOS,
        ]);
    }

    public function desconectar(Request $request, int $token): JsonResponse
    {
        $this->maquinas->desconectar($request->user(), $token);

        return $this->semCache(['maquinas' => $this->maquinas->lista($request->user())]);
    }

    /* ---------- do terminal, sem sessão ---------- */

    public function instalador(): Response
    {
        return response($this->maquinas->instalador(), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function arquivo(string $arquivo): Response
    {
        abort_unless(in_array($arquivo, Maquinas::ARQUIVOS, true), 404);

        return response(file_get_contents(base_path('deploy/central-ia/'.$arquivo)), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function trocarCodigo(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'codigo' => ['required', 'string', 'size:40', 'alpha_num'],
            'maquina' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'nova' => ['sometimes', 'boolean'],
        ]);
        $resposta = $this->maquinas->concluir($dados['codigo'], $dados['maquina'], (bool) ($dados['nova'] ?? false));
        // Código vencido, já usado ou de conta que perdeu o acesso: a mesma
        // resposta para os três, sem dizer qual.
        abort_unless($resposta, 422, 'Código de instalação inválido ou vencido. Gere outro em Ajustes da Central de IA.');

        return $this->semCache($resposta);
    }

    private function semCache(array $dados): JsonResponse
    {
        return response()->json($dados)->header('Cache-Control', 'no-store, private');
    }
}
