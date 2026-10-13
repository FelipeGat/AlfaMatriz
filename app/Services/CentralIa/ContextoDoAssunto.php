<?php

namespace App\Services\CentralIa;

use App\Models\CentralIaMensagem;
use App\Models\CentralIaTarefa;
use Illuminate\Support\Str;

/**
 * O que um agente recebe ao atender um chat de assunto (Chats Business):
 * a memória do assunto e o histórico do chat, dentro de um orçamento por
 * pedido (dono, 13/10/2026: "todo o histórico, desde que não gaste todo o
 * uso"; research D3).
 *
 * Memória sempre vai. Se o histórico inteiro cabe, vai inteiro; senão vai a
 * parte mais recente (até 60% do orçamento) e o condensado do que ficou para
 * trás, quando houver. Tokens são estimados por caracteres ÷ 3,5.
 */
class ContextoDoAssunto
{
    public const CARACTERES_POR_TOKEN = 3.5;

    /** No modo reduzido (cota perto do fim): só as últimas mensagens. */
    public const MENSAGENS_NO_REDUZIDO = 10;

    /**
     * @return array{texto: string, resumo: array{modo: string, tokens_estimados: int, mensagens: int, mensagens_fora: int}}
     */
    public function montar(CentralIaTarefa $assunto, int $orcamentoTokens, ?int $excetoMensagemId = null, bool $reduzido = false): array
    {
        $teto = (int) ($orcamentoTokens * self::CARACTERES_POR_TOKEN);
        $memoria = Str::limit($this->memoria($assunto), (int) ($teto * 0.4), '…');

        $mensagens = CentralIaMensagem::where('user_id', $assunto->user_id)->where('tarefa_id', $assunto->id)
            ->when($excetoMensagemId, fn ($q) => $q->where('id', '!=', $excetoMensagemId))
            ->orderBy('id')->get(['id', 'autor_tipo', 'autor_nome', 'conteudo', 'created_at']);
        $linhas = $mensagens->map(fn (CentralIaMensagem $m) => $this->linha($m))->all();

        $condensado = $assunto->dossie['condensado'] ?? null;
        $total = array_sum(array_map('mb_strlen', $linhas));

        if (! $reduzido && mb_strlen($memoria) + $total <= $teto) {
            $modo = 'inteiro';
            $recentes = $linhas;
        } else {
            $modo = $reduzido ? 'reduzido' : 'condensado';
            $cabe = $reduzido ? PHP_INT_MAX : (int) ($teto * 0.6);
            $recentes = [];
            $usado = 0;
            foreach (array_reverse($linhas) as $l) {
                if ($reduzido && count($recentes) >= self::MENSAGENS_NO_REDUZIDO) break;
                if (! $reduzido && $usado + mb_strlen($l) > $cabe) break;
                array_unshift($recentes, $l);
                $usado += mb_strlen($l);
            }
        }
        $fora = count($linhas) - count($recentes);

        $partes = ["## Chat de assunto: {$assunto->titulo}",
            'Este é um chat contínuo sobre um assunto. Abaixo, a memória do assunto e o histórico do chat. Use-os: não peça à pessoa o que já está aqui.',
            $memoria];
        if ($fora > 0 && ! $reduzido && filled($condensado['texto'] ?? null)) {
            $partes[] = "### Resumo do histórico mais antigo\n".Str::limit($condensado['texto'], (int) ($teto * 0.25), '…');
        }
        if ($fora > 0) {
            $partes[] = $reduzido
                ? "(Cota da IA perto do fim: foram enviadas só as {$this->mensagens(count($recentes))} mais recentes; {$fora} ficaram de fora.)"
                : "({$fora} mensagens mais antigas ficaram de fora deste pedido.)";
        }
        if ($recentes) {
            $partes[] = "### Histórico do chat (mais antigas primeiro)\n".implode("\n\n", $recentes);
        }
        $texto = ProtecaoDeSegredos::texto(implode("\n\n", array_filter($partes)));

        return ['texto' => $texto, 'resumo' => [
            'modo' => $modo,
            'tokens_estimados' => (int) ceil(mb_strlen($texto) / self::CARACTERES_POR_TOKEN),
            'mensagens' => count($recentes),
            'mensagens_fora' => $fora,
        ]];
    }

    private function mensagens(int $n): string
    {
        return $n === 1 ? '1 mensagem' : "{$n} mensagens";
    }

    private function linha(CentralIaMensagem $m): string
    {
        $quem = match ($m->autor_tipo) {
            CentralIaMensagem::AUTOR_USUARIO => 'Pessoa',
            CentralIaMensagem::AUTOR_SISTEMA => 'Central',
            default => $m->autor_nome ?: 'Agente',
        };

        return '['.$m->created_at?->format('d/m H:i')."] {$quem}: ".Str::limit(trim((string) $m->conteudo), 6000, '…');
    }

    /** A memória do assunto em texto: o que o agente precisa saber antes de tudo. */
    private function memoria(CentralIaTarefa $t): string
    {
        $d = $t->dossie ?? [];
        $l = ['### Memória do assunto'];
        if (filled($d['objetivo'] ?? null)) $l[] = "Objetivo: {$d['objetivo']}";

        $secao = function (string $titulo, array $itens) use (&$l) {
            $itens = array_values(array_filter(array_map(fn ($i) => is_array($i) ? trim(($i['texto'] ?? '').(filled($i['caminho'] ?? null) ? " ({$i['caminho']})" : '').(filled($i['etapa'] ?? null) ? " — etapa: {$i['etapa']}" : '')) : trim((string) $i), $itens)));
            if ($itens) $l[] = "\n{$titulo}:\n- ".implode("\n- ", $itens);
        };
        $secao('Onde fica cada coisa e em que etapa está', $d['mapa'] ?? []);
        $secao('Jeito de fazer', $d['jeito_de_fazer'] ?? []);
        $secao('Decisões tomadas', array_slice($d['decisoes'] ?? [], -30));
        $secao('Pendências', $d['pendencias'] ?? []);
        $secao('Próximos passos', $d['proximos_passos'] ?? []);

        return count($l) === 1 ? "### Memória do assunto\n(vazia: este chat está começando)" : implode("\n", $l);
    }
}
