@props([
    'em' => null,            // quando o dado foi obtido
    'vazio' => 'nunca',      // o que dizer quando nunca houve leitura
    'prefixo' => 'há',
])

@php
    /**
     * De quando é o dado que esta tela está mostrando.
     *
     * Existe um só, usado por todas as telas de integração, porque a pergunta
     * é sempre a mesma e a resposta precisa ser sempre a mesma. Sem isto, cada
     * tela inventa o seu formato e o painel passa a dizer "há 12 min" numa
     * página e "07/08 15:12" na outra.
     *
     * O tom é a informação principal: retrato de duas horas é normal, de dois
     * dias é problema. Quem olha de relance precisa ver isso antes de ler.
     */
    $momento = $em ? \Illuminate\Support\Carbon::parse($em) : null;
    $horasLimite = (int) config('integracao.horas_para_retrato_velho', 24);
    $horas = $momento?->diffInHours(now());

    [$tom, $texto] = match (true) {
        $momento === null => ['critico', $vazio],
        $horas >= $horasLimite => ['critico', $prefixo.' '.$momento->diffForHumans(null, true)],
        $horas >= 2 => ['atencao', $prefixo.' '.$momento->diffForHumans(null, true)],
        default => ['bom', $prefixo.' '.$momento->diffForHumans(null, true)],
    };

    // O carimbo absoluto vive no title: a leitura de relance é relativa, mas
    // quem for investigar um número torto precisa da hora exata.
    $absoluto = $momento?->format('d/m/Y H:i');
@endphp

<x-badge :tom="$tom" ponto
         {{ $attributes->merge(['title' => $absoluto ? 'Atualizado em '.$absoluto : 'Nunca sincronizado']) }}>
    {{ $texto }}
</x-badge>
