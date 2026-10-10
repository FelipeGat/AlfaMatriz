<?php

namespace App\Services\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * O log de eventos da Central: quem pediu, qual agente, em qual projeto, e o
 * que deu errado. Só acrescenta.
 *
 * Gravar o evento nunca pode derrubar a operação que ele descreve — uma falha
 * aqui é engolida (o log é testemunha, não porta).
 */
class Eventos
{
    public static function registrar(User|int $user, string $tipo, string $descricao, array $dados = [], string $nivel = 'info'): void
    {
        try {
            DB::table('central_ia_eventos')->insert([
                'user_id' => $user instanceof User ? $user->id : $user,
                'tipo' => $tipo,
                'nivel' => $nivel,
                'origem' => $dados['origem'] ?? null,
                'agente' => $dados['agente'] ?? null,
                'projeto' => isset($dados['projeto']) ? ($dados['projeto'] ?: 'Geral') : null,
                'tarefa_id' => $dados['tarefa_id'] ?? null,
                'execucao_id' => $dados['execucao_id'] ?? null,
                'descricao' => Str::limit($descricao, 495),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // ver o docblock
        }
    }

    /** Os dados de contexto de uma execução, para não repetir em todo lugar. */
    public static function da(CentralIaExecucao $e): array
    {
        return ['agente' => $e->agente, 'projeto' => $e->projeto, 'tarefa_id' => $e->tarefa_id, 'execucao_id' => $e->id];
    }

    public static function recentes(User $user, int $quantos = 80): array
    {
        return DB::table('central_ia_eventos')->where('user_id', $user->id)->orderByDesc('id')->limit($quantos)->get()
            ->map(fn ($e) => [
                'id' => $e->id, 'tipo' => $e->tipo, 'nivel' => $e->nivel, 'origem' => $e->origem,
                'agente' => $e->agente ? (CentralIaExecucao::AGENTES[$e->agente] ?? $e->agente) : null,
                'projeto' => $e->projeto, 'tarefa_id' => $e->tarefa_id, 'execucao_id' => $e->execucao_id,
                'descricao' => $e->descricao, 'em' => \Illuminate\Support\Carbon::parse($e->created_at)->toIso8601String(),
            ])->all();
    }
}
