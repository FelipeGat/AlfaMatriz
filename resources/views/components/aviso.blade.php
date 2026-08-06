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

    Ele SOME sozinho, e isso é o ponto: antes ficava na tela para sempre, e
    depois de mover três cards você tinha um aviso de uma ação que já não
    lembrava mais, empurrando o conteúdo para baixo. Aviso de confirmação tem
    prazo de validade — quem quiser reler o que aconteceu olha a própria tela,
    que já mudou.

    Some por conta em `segundos`, e antes disso se alguém fechar. `segundos=0`
    para o caso raro em que a mensagem precisa esperar leitura.
--}}
<div x-data="{ visivel: true }"
     x-show="visivel"
     x-init="@if ($segundos > 0) setTimeout(() => visivel = false, {{ $segundos * 1000 }}) @endif"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     role="status"
     {{ $attributes->merge(['class' => 'flex items-center gap-3 rounded-panel border px-4 py-2.5 text-[13px]']) }}
     style="background: rgb(var(--{{ $token }}) / var(--tint-alpha));
            border-color: rgb(var(--{{ $token }}) / 0.25);
            color: rgb(var(--{{ $token }}))">
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
