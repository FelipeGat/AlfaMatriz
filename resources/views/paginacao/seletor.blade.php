{{--
    O seletor de páginas do painel.

    MORA AQUI, e não em `resources/views/vendor/pagination/`, que é onde a
    convenção do Laravel manda publicar view de pacote. O motivo é o
    `view:cache`: o `ViewCacheCommand::bladeFilesIn()` monta o Finder com
    `->exclude('vendor')`, então NADA sob `resources/views/vendor/` é
    pré-compilado. Em desenvolvimento não se nota — o servidor compila sob
    demanda com um usuário que pode escrever em `storage/framework/views`. Em
    produção o `publicar.sh` roda `view:cache` como root e o `www-data` não
    escreve ali: a view ficava de fora do cache, tentava compilar no primeiro
    pedido com paginador, o `tempnam()` caía no temp do sistema e a listagem
    devolvia 500. A página de erro do Laravel compila sob demanda também, falha
    pelo mesmo motivo, e o rastro mostrava só o `tempnam` — escondendo a causa.

    Existe porque a view de paginação do Laravel é `bg-white` / `gray-300` /
    `blue-300` no claro e resolve o escuro com variantes `dark:` — e o
    `tailwind.config.js` não declara `darkMode`, então `dark:` cai no padrão
    `media` e obedece ao SISTEMA OPERACIONAL, não à classe `.theme-light` do
    <html> que comanda o tema aqui. O resultado era um seletor que ignorava o
    botão de tema: caixa branca no escuro, e caixa escura no claro quando o SO
    estava no escuro. Nenhuma classe daquela view podia ser aproveitada.

    A forma é a do protótipo (`design/AlfaMatriz Sistema.dc.html`, rodapé da
    tabela): controles de 26px de altura, raio 4px, fio `btn-line` e fundo
    transparente. Ele desenha só Anterior/Próxima; os números entram porque a
    Auditoria passa de dez páginas e sem eles não há salto direto. O único
    valor que o protótipo não define é o da página atual — `bg-chip`, a
    superfície neutra de selecionado que o resto do painel já usa.

    O `ml-auto` mora aqui, e não em cada chamada, porque o lugar deste bloco é
    um só: a direita do slot `rodape` do `x-tabela`, com a contagem à esquerda.
    Junto com ele vêm os resets de `font-sans` e `normal-case` — o rodapé é
    mono/caixa-alta, e sem isso "Anterior" viria "ANTERIOR" e espaçado.

    Abaixo de `sm` sobram Anterior e Próxima: número de página em tela estreita
    vira alvo pequeno demais, e a contagem à esquerda já diz onde se está.
--}}

@php
    // A receita do protótipo, uma vez só: o que separa um botão do outro é a
    // cor e o estado, não a caixa.
    // Sem família de fonte aqui de propósito: `font-sans` e `font-mono` na
    // mesma lista de classes não se resolvem pela ordem do atributo, e sim
    // pela ordem em que o Tailwind emite as regras — decidir isso por acaso
    // de configuração é o tipo de coisa que quebra num upgrade. Cada variante
    // declara a sua, uma vez.
    $base = 'inline-flex h-[26px] items-center justify-center rounded-tile border '
        .'border-btn-line px-[10px] text-[11.5px] normal-case tracking-normal '
        .'transition-colors';

    $rotulo = $base.' font-sans';

    $ativo = $rotulo.' text-ink-mute hover:text-brand hover:border-brand';

    // Desabilitado não é link, é <span>: cinza mais fraco e sem cursor de mão.
    $inerte = $rotulo.' text-ink-faint cursor-default';

    // Número tabular vai em mono — é a regra de tipografia do sistema, e de
    // quebra impede o botão de mudar de largura entre a página 9 e a 10.
    $numero = $base.' min-w-[26px] font-mono';
@endphp

@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Navegação entre páginas" class="ml-auto flex items-center gap-1.5">

        {{-- Anterior --}}
        @if ($paginator->onFirstPage())
            <span class="{{ $inerte }}" aria-disabled="true">
                <span class="mr-1.5 h-[11px] w-[11px]"><x-nav-icon name="chevron-left" :peso="1.8" /></span>
                Anterior
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $ativo }}" aria-label="Página anterior">
                <span class="mr-1.5 h-[11px] w-[11px]"><x-nav-icon name="chevron-left" :peso="1.8" /></span>
                Anterior
            </a>
        @endif

        {{-- Números --}}
        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="hidden px-1 font-mono text-[11.5px] tracking-normal text-ink-faint sm:inline" aria-hidden="true">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $pagina => $url)
                    @if ($pagina == $paginator->currentPage())
                        <span class="{{ $numero }} hidden bg-chip text-ink sm:inline-flex" aria-current="page">{{ $pagina }}</span>
                    @else
                        <a href="{{ $url }}" class="{{ $numero }} hidden text-ink-mute hover:border-brand hover:text-brand sm:inline-flex"
                           aria-label="Ir para a página {{ $pagina }}">{{ $pagina }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        {{-- Próxima: a única com tinta cheia, porque avançar é o que se faz
             com uma listagem paginada — o protótipo já a destaca assim. --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $rotulo }} text-ink hover:text-brand hover:border-brand" aria-label="Próxima página">
                Próxima
                <span class="ml-1.5 h-[11px] w-[11px]"><x-nav-icon name="chevron-right" :peso="1.8" /></span>
            </a>
        @else
            <span class="{{ $inerte }}" aria-disabled="true">
                Próxima
                <span class="ml-1.5 h-[11px] w-[11px]"><x-nav-icon name="chevron-right" :peso="1.8" /></span>
            </span>
        @endif
    </nav>
@endif
