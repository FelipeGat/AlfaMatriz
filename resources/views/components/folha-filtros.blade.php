{{--
    A porta da folha de filtros no celular. Vai DENTRO do <form> de filtros,
    que leva `data-folha-filtros`.

    No computador a barra de filtros fica como sempre, numa linha, e este botão
    nem aparece. A 390px os selects empilhavam um por linha e a barra das
    Tarefas ocupava a primeira tela inteira antes do primeiro card. Aqui fica à
    vista só a busca (o filtro de todo dia) e este botão, que diz quantos
    filtros estão ligados: filtro escondido sem o número é tela recortada que
    parece tela vazia. O resto abre numa folha que sobe de baixo, como nos apps
    do iPhone.

    O formulário é o MESMO nos dois tamanhos — a folha é ele em outra
    posição (`[data-folha-aberta]`, no `app.css`), e não uma segunda cópia dos
    campos, que ficaria para trás no primeiro filtro novo.
--}}
<button type="button" data-folha-botao x-data="folhaDeFiltros"
        @click="alternar()" @keydown.escape.window="fechar()"
        :aria-expanded="aberta.toString()"
        class="md:hidden h-[34px] shrink-0 px-3 rounded-control border border-btn-line text-ink-dim
               flex items-center gap-1.5 text-[13px] font-semibold">
    <span class="h-3.5 w-3.5"><x-nav-icon name="funil" :peso="1.8" /></span>
    <span x-text="aberta ? 'Fechar' : 'Filtros'">Filtros</span>
    <span x-show="! aberta && ativos" x-cloak x-text="ativos"
          class="min-w-[18px] h-[18px] px-1 rounded-full bg-brand text-on-brand text-[11px] leading-[18px] text-center tabular"></span>

    {{-- O fundo escurecido mora no <body>: dentro do formulário ele cairia no
         mesmo empilhamento da folha e escureceria a própria folha. --}}
    <template x-teleport="body">
        <div x-show="aberta" x-cloak x-transition.opacity @click="fechar()"
             class="md:hidden fixed inset-0 z-[46] bg-black/40" aria-hidden="true"></div>
    </template>
</button>
