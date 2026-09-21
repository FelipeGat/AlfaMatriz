{{--
    A legenda das cores dos compromissos — o que cada tom quer dizer.

    Discreta e à direita, uma linha só: existe para o time aprender o código,
    não para disputar espaço com a barra de ações. Sai do MESMO
    `Compromisso::CATEGORIAS` que pinta a grade e o seletor do modal — uma fonte
    só, então legenda e cor nunca divergem.
--}}
<div class="flex shrink-0 flex-wrap items-center justify-end gap-x-3 gap-y-1 px-0.5">
    @foreach (\App\Models\Compromisso::CATEGORIAS as $cat)
        <span class="flex items-center gap-1.5 whitespace-nowrap">
            <span class="h-2 w-2 shrink-0 rounded-full" style="background: rgb(var(--{{ $cat['tom'] }}))"></span>
            <span class="text-[10.5px] text-ink-mute">{{ $cat['rotulo'] }}</span>
        </span>
    @endforeach
</div>
