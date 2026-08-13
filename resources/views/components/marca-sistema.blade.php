@props([
    'sistema',
    'formato' => 'icone',   // icone | wordmark
    'tamanho' => 'h-full w-full',
])

@php
    /**
     * A marca de cada produto, resolvida pelo slug. Os arquivos vivem em
     * `public/marcas/` e vêm da pasta de marcas da casa; a regra de qual
     * usar mora aqui, e não espalhada nas telas.
     *
     * A pasta se chamava `public/sistemas/` e NÃO PODE voltar a se chamar:
     * o nginx da instalação resolve `try_files $uri $uri/ /index.php`, então
     * uma pasta com o nome de uma rota é encontrada no disco antes de o
     * Laravel ser consultado. Com `autoindex` desligado, servir um diretório é
     * 403 — e foi assim que `GET /sistemas` e `POST /sistemas` morreram no
     * staging, com cara de problema de permissão. `tests/Feature/Deploy/
     * RotaSombreadaPorArquivoTest` guarda isso, porque nenhum outro teste
     * consegue: a suíte fala com o Laravel direto e nunca passa pelo nginx.
     *
     * Ícone e wordmark servem a lugares diferentes: o ícone é quadrado e
     * identifica de relance numa tabela; o wordmark é comprido e só cabe onde
     * o produto é o assunto, sem comparação lado a lado.
     *
     * Alguns wordmarks são monocromáticos e precisam de uma versão por tema
     * (a do AlfaJornada tem "Alfa" em preto: no tema escuro sumiria). Outros
     * são coloridos e leem nos dois — esses têm só a versão base.
     */
    $slug = $sistema->slug ?? '';

    /**
     * O ícone é achado por CONVENÇÃO — `public/marcas/<slug>.svg` ou `.png` —
     * e não por uma lista escrita aqui dentro.
     *
     * A lista existia e tinha as seis marcas da casa, todas com o arquivo já
     * nomeado pelo slug: ela não decidia nada que o nome do arquivo não
     * decidisse. O que ela fazia era transformar "dar ícone a um sistema novo"
     * numa alteração de código — desde que dá para cadastrar sistema pela tela,
     * isso passou a significar que todo sistema cadastrado nasce sem ícone e só
     * um deploy o dá. Agora basta largar o arquivo na pasta.
     *
     * SVG antes de PNG porque é o formato das marcas mais recentes e escala sem
     * borrar nos 26px do tile. `is_file` e não `file_exists`: diretório com o
     * nome do slug passaria no segundo e devolveria um <img> quebrado.
     */
    $icone = null;

    if ($slug !== '') {
        // A ENVIADA pela tela vem primeiro. Ela mora no disco `public`, que em
        // produção aponta para `compartilhado/` — e não para dentro da versão,
        // que troca a cada publicação (azul/verde). Marca gravada na pasta da
        // aplicação sumiria no deploy seguinte, sem erro nenhum: só um ícone
        // que um dia deixa de aparecer.
        //
        // Vem primeiro porque é a mais recente por definição: quem acabou de
        // enviar espera ver o que enviou, mesmo que exista uma versão embutida
        // no repositório para o mesmo slug.
        foreach (['png', 'webp', 'jpg'] as $extensao) {
            if (Storage::disk('public')->exists('marcas/'.$slug.'.'.$extensao)) {
                $icone = Storage::disk('public')->url('marcas/'.$slug.'.'.$extensao);
                break;
            }
        }

        // As seis marcas da casa, que viajam no repositório. SVG antes de PNG
        // porque é o formato das mais recentes e escala sem borrar nos 26px do
        // tile.
        foreach ($icone ? [] : ['svg', 'png'] as $extensao) {
            $caminho = '/marcas/'.$slug.'.'.$extensao;

            if (is_file(public_path($caminho))) {
                $icone = $caminho;
                break;
            }
        }
    }

    // base = tema escuro (o padrão do painel) · claro = quando houver troca
    $wordmarks = [
        'alfacontrol' => ['/marcas/alfacontrol-wordmark.png', null],
        'alfagym' => ['/marcas/alfagym-wordmark.png', null],
        'alfahome' => ['/marcas/alfahome-wordmark.png', '/marcas/alfahome-wordmark-claro.png'],
        'alfajornada' => ['/marcas/alfajornada-wordmark.png', '/marcas/alfajornada-wordmark-claro.png'],
        'alfamed' => ['/marcas/alfamed-wordmark.png', '/marcas/alfamed-wordmark-claro.png'],
        // Gestor ainda não tem wordmark na pasta de marcas.
    ];
@endphp

@if ($formato === 'wordmark')
    @php [$escuro, $claro] = $wordmarks[$slug] ?? [null, null]; @endphp

    @if ($escuro && $claro)
        {{--
            Duas versões: a claridade do tema decide qual aparece. Trocar por
            filtro CSS (invert) sujaria a cor da metade colorida da marca.

            `loading="lazy"` na escondida não é otimização de rolagem: imagem
            com `display:none` continua sendo baixada, e sem isto o navegador
            puxava as DUAS versões de cada marca — quem tem par pagava o dobro
            de bytes para mostrar metade.
        --}}
        <img src="{{ $escuro }}" alt="{{ $sistema->nome }}" decoding="async"
             {{ $attributes->merge(['class' => 'w-auto object-contain object-left light:hidden '.$tamanho]) }}>
        <img src="{{ $claro }}" alt="{{ $sistema->nome }}" loading="lazy" decoding="async"
             {{ $attributes->merge(['class' => 'hidden w-auto object-contain object-left light:block '.$tamanho]) }}>
    @elseif ($escuro)
        <img src="{{ $escuro }}" alt="{{ $sistema->nome }}" decoding="async"
             {{ $attributes->merge(['class' => 'w-auto object-contain object-left '.$tamanho]) }}>
    @else
        {{-- Sem arquivo de wordmark: o nome na tipografia de destaque diz a
             mesma coisa, sem inventar uma marca que não existe. --}}
        <span {{ $attributes->merge(['class' => 'font-display text-[17px] font-semibold text-ink']) }}>
            {{ $sistema->nome }}
        </span>
    @endif
@elseif ($icone)
    <img src="{{ $icone }}" alt=""
         {{ $attributes->merge(['class' => 'object-contain '.$tamanho]) }}>
@else
    {{-- Sistema sem ícone: iniciais, como Revendas e Clientes já fazem. --}}
    <span {{ $attributes->merge(['class' => 'flex items-center justify-center font-display text-[12.5px] font-semibold text-brand-text '.$tamanho]) }}>
        {{ Str::of($sistema->nome)->replace('Alfa', '')->substr(0, 2)->upper() }}
    </span>
@endif
