<?php

namespace App\Services\Secretario;

use App\Models\SecretarioEmailConta;
use App\Models\SecretarioGestorRetrato;
use App\Models\SecretarioItem;
use App\Models\SecretarioReuniaoProposta;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * "O que estou devendo aos clientes?" (critério de aceite 6, FR-014): junta,
 * sem IA, as quatro fontes — e-mails aguardando resposta, tarefas de reunião
 * aprovadas e abertas, acompanhamentos do Secretário e o Gestor — cada item
 * com a fonte e a data. Fonte que faltou é DITA, nunca contada como zero.
 */
class ConsultaDePendencias
{
    private const PERGUNTA = '/\b(o que|oq|que) (eu )?(estou devendo|to devendo|devo)\b|\bdevendo aos? clientes?\b|\bpend[eê]ncias? (com|dos|de|aos) clientes?\b|\bminhas pend[eê]ncias\b/u';

    public function responder(User $user, string $texto): ?string
    {
        $t = mb_strtolower(trim($texto), 'UTF-8');

        return preg_match(self::PERGUNTA, $t) ? $this->montar($user) : null;
    }

    public function montar(User $user): string
    {
        $linhas = ['📋 O que você está devendo:'];
        $limitacoes = [];

        // 1. E-mails aguardando resposta.
        if (SecretarioEmailConta::de($user)->exists()) {
            $emails = app(ConsultaDeEmails::class)->aguardandoLista($user);
            $linhas[] = "\n📨 E-mails aguardando resposta (".$emails->count().')';
            foreach ($emails->take(8) as $e) {
                $linhas[] = '• '.app(ConsultaDeEmails::class)->linha($e, true).' [e-mail]';
            }
            if (SecretarioEmailConta::de($user)->where('situacao', 'erro')->exists()) {
                $limitacoes[] = 'uma ou mais caixas de e-mail com erro de leitura';
            }
        } else {
            $limitacoes[] = 'nenhuma caixa de e-mail ligada';
        }

        // 2. Tarefas de reunião aprovadas e ainda abertas.
        $daReuniao = SecretarioItem::de($user)->where('status', SecretarioItem::ABERTO)->where('origem', 'reuniao')->orderByRaw('quando is null')->orderBy('quando')->get();
        if ($daReuniao->isNotEmpty()) {
            $linhas[] = "\n🎧 De reuniões (".$daReuniao->count().')';
            foreach ($daReuniao->take(8) as $i) {
                $linhas[] = '• '.$i->titulo.($i->quando ? ' — prazo '.Datas::porExtenso($i->quando, $i->so_data) : '').' [reunião]';
            }
        }
        $pendentesDeRevisao = SecretarioReuniaoProposta::where('user_id', $user->id)->where('status', 'pendente')->whereIn('categoria', ['tarefa', 'pendencia'])->count();
        if ($pendentesDeRevisao) {
            $linhas[] = "• {$pendentesDeRevisao} tarefa(s) de reunião ainda esperando sua revisão (Secretário → Reuniões)";
        }

        // 3. Acompanhamentos e atrasados do Secretário (fora os de reunião, já listados).
        $acomp = SecretarioItem::de($user)->where('status', SecretarioItem::ABERTO)->where('origem', '!=', 'reuniao')
            ->where(fn ($q) => $q->where('tipo', 'acompanhamento')->orWhere('quando', '<', now()))->orderBy('quando')->get();
        if ($acomp->isNotEmpty()) {
            $linhas[] = "\n📌 Acompanhamentos e atrasados (".$acomp->count().')';
            foreach ($acomp->take(8) as $i) {
                $linhas[] = '• '.$i->titulo.($i->quando ? ' — desde '.Datas::porExtenso($i->quando, $i->so_data) : '').' [Secretário]';
            }
        }

        // 4. Gestor (retrato da máquina).
        $retrato = SecretarioGestorRetrato::where('user_id', $user->id)->first();
        if ($retrato && ! $retrato->velho()) {
            $linhas[] = "\n🏢 Gestor (lido às ".$retrato->lido_em->format('H:i').')';
            foreach (['orcamentos_aprovacao', 'reunioes_proximo_passo', 'cobrancas_vencidas'] as $chave) {
                $b = $retrato->dados['blocos'][$chave] ?? null;
                if ($b && $b['quantidade'] > 0) {
                    $linhas[] = '• '.$b['quantidade'].' '.SecretarioGestorRetrato::BLOCOS[$chave].(isset($b['valor']) ? ' (R$ '.number_format($b['valor'], 2, ',', '.').')' : '');
                    foreach (array_slice($b['itens'] ?? [], 0, 5) as $it) {
                        $linhas[] = '   – '.Str::limit($it['texto'], 100).(isset($it['data']) ? ' ('.$it['data'].')' : '').' [Gestor]';
                    }
                }
            }
        } else {
            $limitacoes[] = $retrato ? 'o Gestor não é lido desde '.$retrato->lido_em->format('d/m H:i') : 'o Gestor ainda não foi lido pela sua máquina';
        }

        if (count($linhas) === 1) {
            $linhas[] = 'Nada encontrado nas fontes que consegui ler.';
        }
        if ($limitacoes) {
            $linhas[] = "\n⚠ Limitações: ".implode('; ', $limitacoes).'.';
        }

        return implode("\n", $linhas);
    }
}
