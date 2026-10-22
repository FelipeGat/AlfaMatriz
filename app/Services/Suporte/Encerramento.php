<?php

namespace App\Services\Suporte;

use App\Models\Notificacao;
use App\Models\SuporteAvaliacao;
use App\Models\SuporteConversa;
use App\Models\User;
use App\Services\AvisoNoTelegram;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Encerrar o atendimento e pedir a avaliação (Felipe, 21/10/2026, como no chat da
 * Intelbras). Encerra quem atende (botão na tela) ou o Pedro, quando o cliente diz que
 * está resolvido. A conversa fica "avaliação" até a próxima mensagem do cliente: um
 * número de 1 a 5 é a nota (o resto do texto, o comentário); outra coisa é conversa
 * nova, que volta ao Pedro sem nota. Quem é da casa não é avaliado.
 */
class Encerramento
{
    /** Avaliação pedida há mais tempo que isso já não vale como resposta. */
    public const VALIDADE_HORAS = 24;

    // No tom de uma pessoa da equipe (T-478, dono em 21/10/2026).
    public const PEDIDO = "Fico feliz em ter ajudado! ✅\n\nAntes de você ir: como foi o seu atendimento? Me responde com um número:\n"
        ."5 - Excelente\n4 - Bom\n3 - Regular\n2 - Ruim\n1 - Péssimo\n\nSe quiser, escreva um comentário junto.";

    public const OBRIGADO = 'Obrigado pela avaliação! Se precisar de algo mais, estou à disposição por aqui.';

    public function __construct(private CanalWhatsApp $canal, private AvisoNoTelegram $telegram) {}

    /** Encerra e pede a nota. @param  User|null  $quem  quem encerrou (null: o Pedro) */
    public function encerrar(SuporteConversa $c, ?User $quem): ?SuporteAvaliacao
    {
        if (app(AcessoInterno::class)->deQuem((string) $c->telefone)) {
            $c->update(['situacao' => 'pedro', 'humano_id' => null, 'humano_ate' => null, 'fila_desde' => null, 'fila_lembrada_em' => null]);

            return null;
        }
        $atendente = $c->humano_id ?: $quem?->id;
        $c->update(['situacao' => 'avaliacao', 'humano_id' => null, 'humano_ate' => null, 'fila_desde' => null, 'fila_lembrada_em' => null]);
        $a = SuporteAvaliacao::create(['conversa_id' => $c->id, 'atendente_id' => $atendente, 'encerrado_por_id' => $quem?->id]);
        try {
            $this->canal->enviar($c, self::PEDIDO, $quem ? 'humano_matriz' : 'pedro', $quem);
        } catch (ValidationException) {
            // Fora da janela de 24 h: encerrado sem pedir nota.
            $c->update(['situacao' => 'pedro']);
        }

        return $a;
    }

    /**
     * A mensagem que chegou numa conversa em avaliação. Devolve true se ela era a nota
     * (e já foi agradecida); false se é conversa nova, que segue para o Pedro.
     */
    public function receber(SuporteConversa $c): bool
    {
        if ($c->situacao !== 'avaliacao') {
            return false;
        }
        $c->update(['situacao' => 'pedro']);
        $a = SuporteAvaliacao::where('conversa_id', $c->id)->whereNull('respondida_em')->where('created_at', '>=', now()->subHours(self::VALIDADE_HORAS))->latest('id')->first();
        $m = $c->mensagens()->reorder()->where('direcao', 'entrada')->orderByDesc('id')->first();
        $texto = trim((string) ($m?->transcricao ?: $m?->texto));
        if (! $a || ! preg_match('/^\s*([1-5])(?!\d)\s*[-–—.,:)]?\s*(.*)$/su', $texto, $r)) {
            return false;
        }
        $a->update(['nota' => (int) $r[1], 'comentario' => Str::limit(trim($r[2]), 1000, '…') ?: null, 'respondida_em' => now()]);
        try {
            $this->canal->enviar($c, self::OBRIGADO, 'pedro');
        } catch (ValidationException) {
        }
        if ($a->nota <= 2) {
            $this->avisarNotaBaixa($c, $a);
        }

        return true;
    }

    /** Nota 1 ou 2: quem atende o suporte fica sabendo na hora. */
    private function avisarNotaBaixa(SuporteConversa $c, SuporteAvaliacao $a): void
    {
        $quem = $c->nome_perfil ?: $c->telefoneLegivel();
        $titulo = "Avaliação {$a->nota} (".SuporteAvaliacao::NOTAS[$a->nota]."): {$quem}".($a->comentario ? ' — '.$a->comentario : '');
        foreach (app(Transferencia::class)->atendentes() as $u) {
            Notificacao::create(['destinatario_id' => $u->id, 'tipo' => 'direcionamento', 'nivel' => 'critico', 'icone' => 'balao',
                'titulo' => Notificacao::caber($titulo), 'meta' => 'Suporte WhatsApp', 'rota' => route('suporte.conversas', $c->id)]);
        }
        if ($this->telegram->configurado()) {
            $this->telegram->enviar('⚠️ <b>'.e($titulo).'</b>'."\n".e(route('suporte.conversas', $c->id)));
        }
    }
}
