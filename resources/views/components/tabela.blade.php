@props([
    'min' => '1000px',   // largura mínima da tabela; abaixo disso ela rola
    'titulo' => null,
    'sub' => null,
    // Colunas pelas larguras declaradas, e não pelo conteúdo. Para quem
    // empilha várias tabelas que precisam das MESMAS colunas (o Acesso do
    // Monitor, #402): sem isso cada uma acharia as próprias larguras.
    'fixa' => false,
    // No celular cada linha vira um cartão, com o nome da coluna ao lado de
    // cada valor (ver `app.css` e `rotularTabelas`, no `app.js`). Desligue
    // só onde a tabela não tem cabeçalho próprio para dar nome aos valores.
    'cartoes' => true,
])

{{--
    Tabela do painel — e a resposta a duas armadilhas que já custaram caro.

    (1) A COLUNA DE AÇÕES SUMIA. Painel com raio precisa de `overflow-hidden`
        para o canto cortar o conteúdo; só que isso corta a tabela larga SEM
        oferecer rolagem, e a última coluna — justamente onde moram editar e
        remover — fica inalcançável. Por isso a rolagem mora num wrapper
        INTERNO: o raio fica na moldura, a rolagem fica na tabela.

    (2) NÚMERO QUEBRANDO NO MEIO. `min-width` na <table> garante que a tabela
        prefira rolar a espremer coluna até "R$" e "52.430" caírem em linhas
        diferentes.

    Quem quiser cabeçalho e rodapé próprios usa os slots `cabecalho` e
    `rodape` — eles ficam FORA do wrapper que rola, senão rolariam junto.
--}}
<section {{ $attributes->merge(['class' => 'rounded-panel border border-line bg-subtle overflow-hidden max-md:min-w-0']) }}>
    @if ($titulo || isset($cabecalho))
        <div data-cabeca-do-painel class="h-[38px] flex items-center gap-3 px-4 bg-head border-b border-line">
            @if ($titulo)
                <h2 class="font-display text-[15px] font-semibold text-ink truncate">{{ $titulo }}</h2>
            @endif
            @if ($sub)
                <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint truncate">{{ $sub }}</span>
            @endif
            <div class="ml-auto flex items-center gap-2 shrink-0">{{ $cabecalho ?? '' }}</div>
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse {{ $fixa ? 'table-fixed' : '' }}" style="min-width: {{ $min }}" @if ($cartoes) data-cartoes @endif>
            {{ $slot }}
        </table>
    </div>

    @isset($rodape)
        <div class="flex flex-wrap items-center gap-3 px-4 py-3 bg-head border-t border-line
                    font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
            {{ $rodape }}
        </div>
    @endisset
</section>
