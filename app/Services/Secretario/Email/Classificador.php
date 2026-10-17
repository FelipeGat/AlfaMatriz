<?php

namespace App\Services\Secretario\Email;

use Carbon\CarbonImmutable;

/**
 * Classificação por REGRA, sem IA (research D3). O que ela não sabe vira
 * "outro" e "normal" — nunca um palpite apresentado como fato.
 */
class Classificador
{
    private const TIPOS = [
        'orcamento' => '/\b(or[cç]amento|cota[cç][aã]o|cotacao)\b/u',
        'proposta' => '/\bproposta\b/u',
        'cobranca' => '/\b(pagamento|fatura|cobran[cç]a|em atraso|boleto vencido)\b/u',
        'documento' => '/\b(contrato|nota fiscal|\bnf\b|nfs-?e|boleto|segue (em )?anexo|anexo)\b/u',
        'reuniao' => '/\b(reuni[aã]o|reunioes|agenda|call|meet|videoconfer[eê]ncia)\b/u',
        'prazo' => '/\b(prazo|at[eé] (o )?dia|vencimento|deadline)\b/u',
    ];

    private const URGENTE = '/\b(urgente|urg[eê]ncia|imediato|imediatamente|o quanto antes|asap|hoje)\b/u';
    private const PEDE = '/(\?|\b(aguardo|aguardamos|retorno|pode(ria)? me (enviar|mandar|passar)|consegue|conseguiria|favor|por gentileza|poderia|preciso de|precisamos de|confirma[r]?)\b)/u';

    /** @return array{tipo: string, urgencia: string, pede_retorno: bool} */
    public function classificar(Mensagem $m, string $dominioDaCaixa): array
    {
        $assunto = mb_strtolower($m->assunto);
        $corpo = mb_strtolower(mb_substr($m->texto, 0, 1500));
        $tudo = $assunto."\n".$corpo;
        $tipo = 'outro';
        foreach (self::TIPOS as $t => $re) {
            if (preg_match($re, $assunto) || preg_match($re, $corpo)) {
                $tipo = $t;
                break;
            }
        }
        $deFora = $dominioDaCaixa === '' || ! str_ends_with($m->deEmail, '@'.$dominioDaCaixa);
        $urgente = (bool) preg_match(self::URGENTE, $assunto) || (bool) preg_match('/\b(urgente|o quanto antes|imediato)\b/u', $corpo)
            || $this->prazoHojeOuAmanha($tudo);

        return [
            'tipo' => $tipo,
            'urgencia' => $urgente ? 'alta' : 'normal',
            'pede_retorno' => $deFora && (bool) preg_match(self::PEDE, $tudo) && ! preg_match('/\b(no-?reply|naoresponda|nao-responda|mailer-daemon)\b/u', $m->deEmail),
        ];
    }

    private function prazoHojeOuAmanha(string $t): bool
    {
        if (preg_match('/\b(at[eé] amanh[aã]|para amanh[aã]|at[eé] hoje)\b/u', $t)) {
            return true;
        }
        $hoje = CarbonImmutable::now();
        foreach ([$hoje, $hoje->addDay()] as $d) {
            if (preg_match('/\b(prazo|at[eé]|vence|vencimento)[^.\n]{0,20}\b0?'.$d->day.'\/0?'.$d->month.'\b/u', $t)) {
                return true;
            }
        }

        return false;
    }
}
