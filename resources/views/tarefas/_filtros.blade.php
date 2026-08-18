{{--
    Barra de busca e filtros das duas abas de Tarefas (quadro e histórico).

    Uma partial só porque o recorte é o mesmo nas duas telas: quem procurou
    algo no quadro e não achou repete a busca no histórico, e o formulário tem
    de aceitar as mesmas perguntas nos dois lugares. O `desfecho` é a única
    diferença — no quadro não existe status terminal para escolher.

    Tudo na query string, como em Revendas e Clientes: o recorte fica
    compartilhável por link e sobrevive ao F5.

    Espera: $filtros, $sistemas, $usuarios. `$comDesfecho` só o histórico passa.
--}}
@props(['comDesfecho' => false, 'raias' => null])

@php
    // Se nada foi pedido, não há o que limpar — e um botão "Limpar" aceso
    // sobre uma tela sem filtro só ensina que ele não faz nada. Prioridade é
    // lista, e lista vazia também é "nada pedido".
    $temFiltro = collect($filtros)->contains(fn ($valor) => $valor !== '' && $valor !== []);
@endphp

<form method="GET" class="shrink-0 flex flex-wrap items-center gap-2">
    <label class="flex items-center gap-2 h-[34px] flex-1 min-w-[220px] max-w-[380px] px-3
                  rounded-control bg-input border border-line text-ink-faint focus-within:border-brand transition">
        <span class="h-4 w-4 shrink-0"><x-nav-icon name="search" /></span>
        {{-- "Qualquer texto ou número" em vez de enumerar campos: a lista já
             ficou incompleta uma vez, e o alcance agora é a tarefa inteira —
             checklist, motivos, relatórios, anexos e versão inclusos. --}}
        <input type="search" name="busca" value="{{ $filtros['busca'] }}"
               placeholder="Buscar por qualquer texto ou número da tarefa…"
               class="flex-1 min-w-0 bg-transparent border-0 p-0 text-[13px] text-ink placeholder-ink-faint focus:ring-0">
    </label>

    <select name="sistema" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
        <option value="">Todos os sistemas</option>
        {{-- "Nenhum" e não "Sem sistema": essa frase é o texto que o CARD usa
             para anunciar a falta (AC-084), e repeti-la aqui a colocaria na
             página mesmo quando nenhum card tem lacuna nenhuma. --}}
        <option value="sem" @selected($filtros['sistema'] === 'sem')>Nenhum sistema</option>
        {{-- Agrupado como no formulário da tarefa, pelo mesmo motivo: filtrar
             por "AlfaMatriz" e por "AlfaGym" são perguntas de tipos
             diferentes. O grupo some quando só há uma família. --}}
        @php $agruparSistemas = $sistemas->pluck('natureza')->unique()->count() > 1; @endphp
        @foreach ($sistemas->groupBy('natureza') as $natureza => $doGrupo)
            @if ($agruparSistemas) <optgroup label="{{ \App\Models\Sistema::NATUREZAS[$natureza] ?? $natureza }}"> @endif
            @foreach ($doGrupo as $sistema)
                <option value="{{ $sistema->id }}" @selected($filtros['sistema'] === (string) $sistema->id)>{{ $sistema->nome }}</option>
            @endforeach
            @if ($agruparSistemas) </optgroup> @endif
        @endforeach
    </select>

    <select name="responsavel" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
        <option value="">Todos os responsáveis</option>
        {{-- A fila de triagem em uma opção: é a pergunta que a coluna Aberta
             faz. "Nenhum" pelo mesmo motivo do select acima (AC-130). --}}
        <option value="sem" @selected($filtros['responsavel'] === 'sem')>Nenhum responsável</option>
        @foreach ($usuarios as $usuario)
            <option value="{{ $usuario->id }}" @selected($filtros['responsavel'] === (string) $usuario->id)>{{ $usuario->name }}</option>
        @endforeach
    </select>

    {{-- Com desenvolvimento e operacional no mesmo quadro, isolar um dos dois
         passou a ser pergunta de rotina: o quadro do ciclo continua existindo,
         agora como um recorte em vez de uma tela. --}}
    <select name="tipo" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
        <option value="">Todos os tipos</option>
        @foreach (\App\Models\Tarefa::TIPOS as $chave => $rotulo)
            <option value="{{ $chave }}" @selected($filtros['tipo'] === $chave)>{{ $rotulo }}</option>
        @endforeach
    </select>

    {{--
        Prioridade aceita MAIS DE UMA ao mesmo tempo (pedido do dono em
        18/08/2026): "as altas e as críticas" é uma pergunta só, e o select de
        valor único obrigava a fazê-la em duas viagens. Caixas num dropdown, e
        não um `<select multiple>` — segurar Ctrl para somar opções não é gesto
        que ninguém descobre. Diferente da situação, logo abaixo, as
        prioridades NÃO são mutuamente exclusivas: somá-las é a pergunta.

        Cada prioridade ligada vira pílula própria no cabeçalho do quadro, com
        o ✕ que tira só ela; o "limpar" aqui dentro zera só este campo, e o
        "Limpar recorte" no fim da barra continua zerando tudo.
    --}}
    <div class="relative"
         x-data="{
             aberto: false,
             prioridades: @js($filtros['prioridade']),
             rotulos: @js(\App\Models\Tarefa::PRIORIDADES),
         }"
         @click.outside="aberto = false" @keydown.escape.stop="aberto = false">
        {{-- O rótulo NOMEIA as ligadas, na ordem da escala e não na do clique:
             "Alta, Crítica" diz o recorte; "2 prioridades" obrigaria a abrir
             para saber quais. --}}
        {{-- Largura FIXA, a mesma do painel. O rótulo muda de tamanho a cada
             caixa marcada, e com largura automática o botão trocava de linha
             do `flex-wrap` DEBAIXO do painel aberto — o menu fugia do cursor
             no meio do gesto. Fixa, nada se move; e o painel, com a largura
             do próprio botão, nunca passa da borda da tela. --}}
        <button type="button" @click="aberto = ! aberto" :aria-expanded="aberto.toString()"
                title="Filtrar por prioridade — mais de uma pode ficar ligada"
                class="h-[34px] px-3 w-[220px] rounded-control bg-input border border-line
                       flex items-center gap-2 text-[13px] text-ink-dim transition"
                :class="aberto && 'border-brand'">
            <span class="flex-1 min-w-0 truncate text-left"
                  x-text="prioridades.length
                      ? Object.entries(rotulos).filter(([chave]) => prioridades.includes(chave)).map(([, rotulo]) => rotulo).join(', ')
                      : 'Todas as prioridades'"></span>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 class="h-[11px] w-[11px] shrink-0 transition-transform duration-150"
                 :class="aberto && 'rotate-180'">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
            </svg>
        </button>

        <div x-show="aberto" x-cloak
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0 -translate-y-1"
             class="absolute left-0 top-full z-30 mt-1 w-[220px] rounded-control border border-line
                    bg-panel p-1.5 shadow-xl flex flex-col gap-1">
            @foreach (\App\Models\Tarefa::PRIORIDADES as $chave => $rotulo)
                <label class="flex items-center gap-2.5 h-[30px] px-2 rounded-tile cursor-pointer hover:bg-chip transition">
                    {{-- Sem `bg-input`, como nos perfis de acesso: classe de
                         fundo vence o `:checked` do plugin de forms, e a caixa
                         marcada fica branca sobre branca no tema claro. --}}
                    <input type="checkbox" name="prioridade[]" value="{{ $chave }}" x-model="prioridades"
                           class="h-4 w-4 rounded border-btn-line text-brand focus:ring-brand">
                    <span class="text-[13px] text-ink-dim">{{ $rotulo }}</span>
                </label>
            @endforeach

            {{-- Zera SÓ as prioridades, de uma vez — desmarcar cinco caixas
                 uma a uma não é "limpar". Some sem nada ligado, como o
                 "Limpar recorte": botão sobre campo vazio ensina que ele não
                 faz nada. Não envia o formulário: como nos selects vizinhos,
                 o recorte só muda no "Filtrar". --}}
            <button type="button" x-show="prioridades.length" @click="prioridades = []"
                    class="h-[26px] px-2 text-left rounded-tile
                           font-mono text-[10.5px] uppercase tracking-caps text-ink-faint hover:text-brand transition">
                Limpar prioridades
            </button>
        </div>
    </div>

    {{--
        "Só as que esperam por você" é o mesmo recorte do chip do cabeçalho,
        aqui em forma de caixa: quem chegou pelos filtros não deveria precisar
        descobrir que o atalho existe lá em cima. Fora do histórico, porque
        tarefa encerrada não tem conversa em aberto.
    --}}
    @unless ($comDesfecho)
        {{-- A situação é UM campo com quatro respostas, e não quatro caixas:
             elas são mutuamente exclusivas — ninguém pergunta "as travadas que
             também esperam por mim" — e caixas independentes permitiriam
             justamente essa combinação sem sentido. É o mesmo recorte que os
             chips do cabeçalho aplicam. --}}
        <select name="situacao" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
            <option value="">Qualquer situação</option>
            @foreach ([
                'esperando_mim' => 'Só as que esperam por você',
                'travadas' => 'Só as travadas',
                'em_curso' => 'Só as em curso',
                'prontas' => 'Só as prontas p/ subir',
            ] as $chave => $rotulo)
                <option value="{{ $chave }}" @selected(($filtros['situacao'] ?? '') === $chave)>{{ $rotulo }}</option>
            @endforeach
        </select>
    @endunless

    @if ($comDesfecho)
        <select name="desfecho" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
            <option value="">Todos os desfechos</option>
            @foreach (\App\Models\Tarefa::STATUS_TERMINAIS as $terminal)
                <option value="{{ $terminal }}" @selected($filtros['desfecho'] === $terminal)>
                    {{ \App\Models\Tarefa::STATUS[$terminal] ?? $terminal }}
                </option>
            @endforeach
        </select>
    @endif

    {{-- O agrupamento viaja junto do recorte: sem este campo, filtrar
         desmancharia as raias, e a pessoa refaria a escolha a cada busca. --}}
    @if ($raias)
        <input type="hidden" name="raias" value="{{ $raias['modo'] }}">
    @endif

    <button type="submit"
            class="h-[34px] px-3 rounded-control border border-btn-line text-ink-dim
                   text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
        Filtrar
    </button>

    {{--
        As raias não são filtro, e por isso ficam separadas dos selects: filtro
        ESCONDE o que não interessa, raia mostra tudo separado. A pergunta delas
        é de distribuição — quem está com o quê, onde cada sistema travou —, e
        essa some quando se olha coluna por coluna com todo mundo junto.

        Links, e não select: cada opção é um endereço, então a visão agrupada
        pode ser guardada nos favoritos ou mandada pronta para alguém.
    --}}
    @if ($raias)
        {{-- Segmented control, o mesmo do Quadro/Histórico: as três opções são
             uma escolha ENTRE si, e como texto solto elas se liam como três
             links independentes — nada dizia que ligar uma desliga as outras,
             nem qual estava valendo agora. --}}
        <span class="ml-auto flex items-center gap-2">
            <span class="font-mono text-[10px] uppercase tracking-caps text-ink-faint">Raias</span>

            {{-- Sem ícone: o desenho não tem nenhum aqui, e "Nenhuma" com um
                 ícone de grade ao lado prometia uma vista que não existe. Três
                 palavras curtas se distinguem sozinhas. --}}
            <x-abas>
                @foreach (['nenhuma' => 'Nenhuma', 'responsavel' => 'Responsável', 'sistema' => 'Sistema'] as $modo => $rotulo)
                    <x-abas.item :href="request()->fullUrlWithQuery(['raias' => $modo])"
                                 :ativo="$raias['modo'] === $modo">
                        {{ $rotulo }}
                    </x-abas.item>
                @endforeach
            </x-abas>
        </span>
    @endif

    @if ($temFiltro)
        {{-- Link e não botão: limpar é ir para a mesma tela sem query nenhuma. --}}
        <a href="{{ url()->current() }}"
           class="h-[34px] px-3 inline-flex items-center rounded-control
                  font-mono text-[10.5px] uppercase tracking-caps text-ink-faint hover:text-brand transition">
            Limpar recorte
        </a>
    @endif
</form>
