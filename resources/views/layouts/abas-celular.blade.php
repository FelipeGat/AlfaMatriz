{{--
    A barra de abas do celular (pedido do dono, 23/10/2026: o Matriz no iPhone
    com cara de app, "da mesma forma que o painel" das centrais).

    Só abaixo de 768px. As quatro telas são as do dia a dia no celular; o resto
    do menu mora no "Mais", que é a própria gaveta — uma lista só, e não uma
    segunda cópia do menu que um dia discordaria da primeira.

    Aba sem permissão não aparece, pela mesma régua do menu: porta que dá 403 é
    pior que porta ausente. A Agenda abre na Lista: a semana tem sete colunas
    de hora, e a 390px cada uma fica com ~45px, onde nenhum título cabe. A
    Semana e o Mês continuam a um toque, na barra da própria Agenda. A pílula flutuante e o vidro seguem o painel
    (`.abas-celular` no app.css).
--}}
@php
    $usuarioAbas = Auth::user();
    $abasCelular = collect([
        ['route' => 'secretario.meu-dia', 'recurso' => 'secretario', 'pattern' => 'secretario.meu-dia', 'label' => 'Hoje', 'icon' => 'sun'],
        ['route' => 'tarefas.index', 'recurso' => 'tarefas', 'pattern' => 'tarefas.*', 'label' => 'Tarefas', 'icon' => 'view-grid'],
        ['route' => 'agenda.index', 'recurso' => 'agenda', 'pattern' => 'agenda.*', 'label' => 'Agenda', 'icon' => 'calendar', 'parametros' => ['visao' => 'lista']],
        ['route' => 'central-ia.index', 'recurso' => 'central_ia', 'pattern' => 'central-ia.*', 'label' => 'Central IA', 'icon' => 'chat'],
    ])->filter(fn ($aba) => ! $usuarioAbas->temEscopoDeRevenda() && $usuarioAbas->canPermissao($aba['recurso'], 'ler'));

    $algumaAbaAtiva = $abasCelular->contains(fn ($aba) => request()->routeIs($aba['pattern']));
@endphp

<nav class="abas-celular md:hidden" aria-label="Abas">
    @foreach ($abasCelular as $aba)
        @php $ativa = request()->routeIs($aba['pattern']); @endphp
        <a href="{{ route($aba['route'], $aba['parametros'] ?? []) }}"
           class="aba-celular"
           :class="gavetaAberta && 'aba-celular--apagada'"
           @if ($ativa) data-ativa aria-current="page" @endif>
            <span class="aba-celular__icone"><x-nav-icon :name="$aba['icon']" :peso="1.7" /></span>
            <span>{{ $aba['label'] }}</span>
        </a>
    @endforeach

    <button type="button" class="aba-celular"
            @click="gavetaAberta = ! gavetaAberta"
            :data-ativa="(gavetaAberta || {{ $algumaAbaAtiva ? 'false' : 'true' }}) ? '' : null"
            :aria-expanded="gavetaAberta.toString()"
            aria-controls="menu-principal">
        <span class="aba-celular__icone"><x-nav-icon name="ellipsis-horizontal" :peso="1.7" /></span>
        <span>Mais</span>
    </button>
</nav>
