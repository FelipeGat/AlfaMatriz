@props([
    'caminho' => [],   // [['rotulo' => 'Clientes', 'rota' => route(...)], ...]
    'atual',           // onde a pessoa está — não é link
])

{{--
    Migalhas de navegação.

    Só fazem sentido em tela FILHA. Numa tela de topo elas repetiriam o que o
    menu lateral já diz — e migalha que só tem o próprio nome é enfeite.

    Elas respondem duas perguntas que o menu não responde: onde exatamente eu
    estou dentro desta seção, e como volto. O botão do navegador não serve
    para a segunda: depois de salvar um formulário, voltar significa
    reenviar — a migalha dá o caminho limpo.
--}}
<nav aria-label="Você está em" {{ $attributes->merge(['class' => 'flex items-center gap-1.5 min-w-0']) }}>
    @foreach ($caminho as $passo)
        <a href="{{ $passo['rota'] }}"
           class="shrink-0 font-mono text-[11px] uppercase tracking-caps text-ink-faint
                  hover:text-brand-text transition whitespace-nowrap">
            {{ $passo['rotulo'] }}
        </a>
        <span class="shrink-0 text-ink-faint" aria-hidden="true">›</span>
    @endforeach

    {{-- O último degrau é o título da tela: ele não vira link para lá mesmo. --}}
    <h1 class="min-w-0 truncate font-display text-[17px] font-semibold text-ink" aria-current="page">
        {{ $atual }}
    </h1>
</nav>
