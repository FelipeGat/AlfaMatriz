@props([
    'name',
    'show' => false,
    'maxWidth' => '2xl'
])

@php
$maxWidth = [
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-2xl',
    // O modal da tarefa: 620px é o valor do desenho, e não há passo da escala
    // do Tailwind nesse ponto — `xl` dá 576 e `2xl` dá 672.
    'tarefa' => 'sm:max-w-[620px]',
][$maxWidth];
@endphp

<div
    x-data="{
        show: @js($show),
        focusables() {
            // All focusable element types...
            let selector = 'a, button, input:not([type=\'hidden\']), textarea, select, details, [tabindex]:not([tabindex=\'-1\'])'
            return [...$el.querySelectorAll(selector)]
                // All non-disabled elements...
                .filter(el => ! el.hasAttribute('disabled'))
        },
        firstFocusable() { return this.focusables()[0] },
        lastFocusable() { return this.focusables().slice(-1)[0] },
        nextFocusable() { return this.focusables()[this.nextFocusableIndex()] || this.firstFocusable() },
        prevFocusable() { return this.focusables()[this.prevFocusableIndex()] || this.lastFocusable() },
        nextFocusableIndex() { return (this.focusables().indexOf(document.activeElement) + 1) % (this.focusables().length + 1) },
        prevFocusableIndex() { return Math.max(0, this.focusables().indexOf(document.activeElement)) -1 },
    }"
    x-init="$watch('show', value => {
        if (value) {
            document.body.classList.add('overflow-y-hidden');
            {{ $attributes->has('focusable') ? 'setTimeout(() => firstFocusable().focus(), 100)' : '' }}
        } else {
            document.body.classList.remove('overflow-y-hidden');
        }
    })"
    {{-- O nome também no DOM: quem fecha o modal por evento às vezes precisa
         alcançar o conteúdo dele em seguida — o quadro de tarefas esvazia o
         formulário de "nova tarefa" depois de gravar, porque esse modal não é
         redesenhado e guardaria o que acabou de ser salvo. --}}
    data-modal="{{ $name }}"
    {{--
        Abrir esvazia o formulário que pediu — `data-esvazia-ao-abrir`.

        Fechar um modal só o ESCONDE: o HTML fica de pé, com o que estava
        digitado dentro. Para o "nova tarefa", que o servidor nunca redesenha,
        isso virava rascunho involuntário — quem começava uma tarefa, desistia e
        clicava em "Nova tarefa" de novo reencontrava o título, o checklist e os
        anexos da tentativa anterior, prontos para nascerem como tarefa errada.

        É opt-in porque o modal de EDIÇÃO não pode ser esvaziado: lá os campos
        JÁ são o que está gravado, e um `reset()` desfaria na tela a edição que
        acabou de ser salva — o mesmo motivo que separa `limparModal` de
        `fecharModal` no quadro.

        Ao ABRIR, e não ao fechar: a saída tem transição de 150ms e o modal
        continua na tela durante ela, então esvaziar ali seria VISTO, como um
        piscar do formulário no meio do fecha. E só quando ele estava fechado:
        com o modal aberto e o foco fora de um campo, um segundo `open-modal` —
        a tecla `n` do quadro — apagaria o que está sendo digitado.

        `reset()` e não campo a campo: ele devolve cada um ao valor que o
        servidor imprimiu (o `old()`, quando a validação devolveu a página
        inteira) e dispara o evento `reset`, de que dependem as listas em Alpine
        do checklist e dos anexos da criação.
    --}}
    x-on:open-modal.window="if ($event.detail == '{{ $name }}' && ! show) {
        $el.querySelectorAll('form[data-esvazia-ao-abrir]').forEach((form) => form.reset());
        show = true;
    }"
    x-on:close-modal.window="$event.detail == '{{ $name }}' ? show = false : null"
    x-on:close.stop="show = false"
    x-on:keydown.escape.window="show = false"
    x-on:keydown.tab.prevent="$event.shiftKey || nextFocusable().focus()"
    x-on:keydown.shift.tab.prevent="prevFocusable().focus()"
    x-show="show"
    class="fixed inset-0 overflow-y-auto px-4 py-6 sm:px-0 z-50"
    style="display: {{ $show ? 'block' : 'none' }};"
>
    <div
        x-show="show"
        class="fixed inset-0 transform transition-all"
        x-on:click="show = false"
        x-transition:enter="ease-out duration-150"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    >
        <div class="absolute inset-0 bg-black/70"></div>
    </div>

    <div
        x-show="show"
        {{--
            `max-h` + rolagem AQUI, no painel, e não só no container externo:
            com um formulário alto (o de cliente tem seis seções), rolar o
            container faz o modal inteiro subir e sair da tela. Rolando por
            dentro, a moldura fica parada e o conteúdo é que anda — que é o
            comportamento que se espera de um diálogo.
        --}}
        class="mb-6 max-h-[85vh] overflow-y-auto overscroll-contain rounded-panel border border-line bg-panel shadow-none transform transition-all sm:w-full {{ $maxWidth }} sm:mx-auto"
        x-transition:enter="ease-out duration-150"
        x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave="ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
    >
        {{ $slot }}
    </div>
</div>
