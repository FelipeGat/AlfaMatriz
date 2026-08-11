@props([
    'tom' => 'bom',       // bom | atencao | critico
    'segundos' => 6,      // 0 mantém na tela até alguém fechar
])

@php
    $token = match ($tom) {
        'critico' => 'crit',
        'atencao' => 'warn',
        default => 'good',
    };
@endphp

{{--
    Aviso de resultado — "lead movido", "cliente salvo", "baixa concluída".

    Ele FLUTUA e SOME sozinho, e as duas coisas são o ponto.

    Flutua porque nascer no meio do conteúdo custava caro: a página inteira
    descia a altura do aviso no momento em que ele aparecia, e voltava a subir
    quando ele saía. Quem estava lendo perdia a linha, quem estava mirando um
    botão clicava no de baixo. Agora ele é levado para a pilha fixa do layout
    (`#pilha-avisos`), por cima da tela — o conteúdo não se mexe nem quando
    chega, nem quando some.

    O <template> que fica para trás não ocupa espaço nenhum, e é `hidden` por
    isso mesmo: sem o atributo ele ainda contaria como irmão nas telas com
    `space-y-*`, e o primeiro bloco depois dele ganharia uma margem que não
    existia — o mesmo empurrão, de novo, só que menor.

    Some por conta em `segundos`, e antes disso se alguém fechar. `segundos=0`
    para o caso raro em que a mensagem precisa esperar leitura.
--}}
{{-- O `x-init` vazio não faz nada — ele existe para o Alpine ENXERGAR este
     template. Na varredura inicial o Alpine só entra em árvores que começam
     num `x-data` ou num `x-init`, e um aviso solto numa tela sem componente
     Alpine por perto simplesmente nunca seria teleportado: some da página sem
     erro nenhum, que é o pior jeito de quebrar. --}}
<template hidden x-init x-teleport="#pilha-avisos">
    {{-- Nasce visível, e sem transição de entrada: o aviso chega junto com a
         pintura da página inteira, não depois dela — não há de onde animar. --}}
    <div x-data="{ visivel: true }"
         x-show="visivel"
         x-init="@if ($segundos > 0) setTimeout(() => visivel = false, {{ $segundos * 1000 }}) @endif"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         role="status"
         {{ $attributes->merge(['class' => 'pointer-events-auto max-w-[560px] flex items-center gap-3 rounded-panel border px-4 py-2.5 text-[13px]']) }}
         {{-- O tinte é o mesmo de sempre, só que agora sobre um fundo opaco:
              flutuando, um aviso translúcido deixaria o texto da tela passar
              por baixo da própria mensagem. --}}
         style="background: linear-gradient(rgb(var(--{{ $token }}) / var(--tint-alpha)),
                                            rgb(var(--{{ $token }}) / var(--tint-alpha))),
                            rgb(var(--panel));
                border-color: rgb(var(--{{ $token }}) / 0.25);
                color: rgb(var(--{{ $token }}));
                box-shadow: 0 12px 32px rgb(0 0 0 / 0.32)">
        <span class="h-4 w-4 shrink-0">
            <x-nav-icon :name="$tom === 'bom' ? 'check-circle' : 'alert-triangle'" :peso="1.7" />
        </span>

        <span class="min-w-0 flex-1">{{ $slot }}</span>

        <button type="button" @click="visivel = false"
                class="shrink-0 h-5 w-5 rounded-badge opacity-60 hover:opacity-100 transition flex items-center justify-center"
                aria-label="Fechar aviso">
            <span class="h-3.5 w-3.5"><x-nav-icon name="x-mark" :peso="1.7" /></span>
        </button>
    </div>
</template>
