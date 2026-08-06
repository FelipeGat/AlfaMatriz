@props([
    'action' => null,      // rota do formulário próprio
    'form' => null,        // ou o id de um formulário que já existe na tela
    'method' => 'POST',
    'icone' => null,       // gatilho como ação de tabela
    'rotulo' => null,      // ou como botão de texto
    'titulo',              // pergunta, em uma linha
    'mensagem' => null,    // o que acontece se confirmar
    'confirmar' => 'Confirmar',
    'destrutivo' => false,
])

@php
    // Um id por instância: a tela tem várias, e elas não podem se confundir.
    $id = 'confirmar-'.Str::random(8);

    $tomBotao = $destrutivo
        ? 'text-white hover:opacity-90'
        : 'bg-brand text-on-brand hover:bg-brand-bright';

    $hoverGatilho = $destrutivo
        ? 'hover:text-crit hover:bg-crit-tint'
        : 'hover:text-brand hover:bg-chip';
@endphp

{{--
    Confirmação com a cara do sistema.

    O `confirm()` do navegador é desenhado pelo sistema operacional: não aceita
    tema, não aceita tipografia, e mostra a URL da página junto — num painel
    interno isso lê como aviso de golpe. Pior: ele NÃO DIZ O QUE VAI ACONTECER,
    só repete a pergunta. Aqui o título pergunta e a mensagem explica a
    consequência, que é a informação que faz alguém desistir a tempo.

    O diálogo é teletransportado para o fim do <body> porque, dentro de uma
    célula de tabela, ele herdaria `overflow` e recortes do painel.
--}}
<div x-data="{ aberto: false }" class="inline-flex">
    @if ($action)
        <form method="POST" action="{{ $action }}" x-ref="formulario" class="hidden">
            @csrf
            @if (! in_array(strtoupper($method), ['POST', 'GET']))
                @method($method)
            @endif
            {{ $campos ?? '' }}
        </form>
    @endif

    @if ($icone)
        <button type="button" @click="aberto = true"
                class="inline-flex h-7 w-7 items-center justify-center rounded-tile text-ink-mute transition {{ $hoverGatilho }}"
                title="{{ $titulo }}" aria-label="{{ $titulo }}">
            <span class="h-[15px] w-[15px]"><x-nav-icon :name="$icone" /></span>
        </button>
    @else
        <button type="button" @click="aberto = true"
                {{ $attributes->merge(['class' => 'inline-flex items-center']) }}>
            {{ $rotulo ?? $slot }}
        </button>
    @endif

    <template x-teleport="body">
        <div x-show="aberto" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
             @keydown.escape.window="aberto = false" role="dialog" aria-modal="true"
             aria-labelledby="{{ $id }}-titulo">
            <div x-show="aberto" x-transition.opacity class="absolute inset-0 bg-black/60"
                 @click="aberto = false"></div>

            <div x-show="aberto" x-transition
                 class="relative w-full max-w-[420px] rounded-panel border border-line bg-panel p-5">
                <div class="flex items-start gap-3">
                    <span class="h-8 w-8 shrink-0 rounded-tile flex items-center justify-center"
                          style="background: rgb(var(--{{ $destrutivo ? 'crit' : 'brand' }}) / var(--tint-alpha));
                                 color: rgb(var(--{{ $destrutivo ? 'crit' : 'brand-text' }}))">
                        <span class="h-[17px] w-[17px]">
                            <x-nav-icon :name="$destrutivo ? 'alert-triangle' : 'check-circle'" />
                        </span>
                    </span>

                    <div class="min-w-0">
                        <h2 id="{{ $id }}-titulo" class="font-display text-[15.5px] font-semibold text-ink">
                            {{ $titulo }}
                        </h2>
                        @if ($mensagem)
                            <p class="mt-1 text-[13px] text-ink-dim">{{ $mensagem }}</p>
                        @endif
                    </div>
                </div>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" @click="aberto = false"
                            class="h-9 px-3.5 rounded-control border border-btn-line text-[12.5px] font-semibold
                                   text-ink-dim hover:text-ink transition">
                        Cancelar
                    </button>

                    <button type="button"
                            @click="aberto = false; {{ $action ? '$refs.formulario.submit()' : "document.getElementById('".$form."').submit()" }}"
                            class="h-9 px-3.5 rounded-control text-[12.5px] font-semibold transition {{ $tomBotao }}"
                            @if ($destrutivo) style="background: rgb(var(--crit))" @endif>
                        {{ $confirmar }}
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
