<?php

namespace App\Services\Suporte;

use App\Http\Controllers\SuporteConversasController;
use App\Models\SuporteAvaliacao;
use App\Models\SuporteConversa;
use App\Models\SuporteMensagem;
use App\Models\SuportePedido;

/**
 * Os números do dia do Pedro, sobre as mensagens e os pedidos dele (não sobre
 * a lista filtrada da tela). Saiu do controller para a tela e a API Executiva
 * lerem a MESMA conta, e nunca discordarem.
 */
class IndicadoresDoDia
{
    public function calcular(): array
    {
        $ativas = SuporteConversa::where('ultima_entrada_em', '>', now()->subHours(SuporteConversa::JANELA_HORAS))->get();
        $hoje = SuporteMensagem::where('direcao', 'entrada')->where('created_at', '>=', today());
        // 1ª resposta: da primeira mensagem do cliente no dia até a primeira resposta depois dela.
        $tempos = [];
        foreach ((clone $hoje)->selectRaw('conversa_id, MIN(id) as primeira')->groupBy('conversa_id')->get() as $r) {
            $entrada = SuporteMensagem::find($r->primeira);
            $resposta = SuporteMensagem::where('conversa_id', $r->conversa_id)->where('id', '>', $r->primeira)->where('direcao', '!=', 'entrada')->orderBy('id')->first();
            if ($entrada && $resposta) {
                $tempos[] = $entrada->created_at->diffInSeconds($resposta->created_at);
            }
        }
        $pedidos = fn (string $acao) => SuportePedido::where('acao', $acao)->where('created_at', '>=', today())->count();

        return [
            'recebidas_hoje' => (clone $hoje)->distinct()->count('conversa_id'),
            'com_pedro' => $ativas->where('situacao', 'pedro')->count(),
            'com_humano' => $ativas->filter->comHumano()->count(),
            'no_gestor' => $ativas->where('situacao', 'gestor')->count(),
            'esperando' => $ativas->filter(fn ($c) => SuporteConversasController::atrasada($c))->count(),
            'primeira_resposta' => $tempos ? (int) round(array_sum($tempos) / count($tempos)) : null,
            'especialista_hoje' => $pedidos('especialista'),
            'atendimentos_hoje' => $pedidos('abrir_atendimento'),
            'na_fila' => $ativas->filter(fn ($c) => $c->situacao === 'humano' && $c->fila_desde)->count(),
            // A nota média dos últimos 30 dias e quantas responderam (encerramento.md).
            'avaliacao' => SuporteAvaliacao::whereNotNull('nota')->where('respondida_em', '>=', now()->subDays(30))->selectRaw('AVG(nota) as media, COUNT(*) as n')->first(),
        ];
    }
}
