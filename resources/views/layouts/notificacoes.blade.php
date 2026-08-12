{{--
    O painel do sino.

    Ele é IRMÃO do <aside>, e não filho: a sidebar tem `overflow: hidden` (para
    a transição de largura do rail) e um `transform` (para a gaveta do celular).
    O primeiro corta qualquer filho que passe da borda; o segundo faz o aside
    virar bloco contido, o que impede até `position: fixed` de escapar. Um
    painel ancorado lá dentro simplesmente não apareceria.

    A outra armadilha, a que o handoff nomeia: o aside é `position: sticky`, e
    sticky cria contexto de empilhamento mesmo com `z-index: auto`. Ele já
    carrega `z-40`; o painel fica acima disso para não ser pintado atrás do
    <main>.

    Espera (do composer de `layouts.navigation`): $notificacoes, $naoLidas.
--}}

@php
    /**
     * O rodapé leva ao Centro de Controle — quando a conta o alcança.
     *
     * São DUAS condições, e não uma: a permissão e o escopo. Conta de revenda
     * pode ter `dashboard` no perfil e mesmo assim não ver a tela, porque o
     * menu barra tudo que é da matriz antes de olhar permissão. Checar só a
     * permissão deixaria o endereço vazar pelo painel — e o menu é montado a
     * partir do que a pessoa PODE ver justamente para não contar a ela sobre
     * telas que não existem do lado dela.
     */
    $usuarioDoSino = auth()->user();
    $veCentroDeControle = $usuarioDoSino
        && ! $usuarioDoSino->temEscopoDeRevenda()
        && $usuarioDoSino->canPermissao('dashboard', 'ler');
@endphp

{{-- Overlay: fecha ao clicar fora, e fica ABAIXO do painel. --}}
<div x-show="sinoAberto" x-cloak @click="sinoAberto = false"
     class="fixed inset-0 z-40" x-transition.opacity.duration.150ms></div>

{{--
    Ancorado ao rodapé da sidebar, e não ao sino: o rodapé está no mesmo lugar
    com o menu aberto e recolhido, então o painel acompanha o rail sem precisar
    recalcular posição. A largura é a do handoff (352px), com teto para não
    estourar a tela estreita.
--}}
<div x-show="sinoAberto" x-cloak
     @keydown.escape.window="sinoAberto = false"
     x-transition:enter="transition ease-out duration-150"
     x-transition:enter-start="opacity-0 translate-y-1"
     x-transition:leave="transition ease-in duration-100"
     x-transition:leave-end="opacity-0"
     class="fixed bottom-3 z-50 w-[352px] max-w-[calc(100vw-24px)]
            left-3 lg:left-[calc(theme(spacing.sidebar)+12px)] rail:lg:left-[calc(theme(spacing.rail)+12px)]
            rounded-panel border border-line bg-panel overflow-hidden flex flex-col">

    <header class="h-[38px] shrink-0 flex items-center gap-2 px-4 border-b border-line bg-head">
        <h2 class="font-display text-[13.5px] font-semibold text-ink">Notificações</h2>

        @if (($naoLidas ?? 0) > 0)
            <form method="POST" action="{{ route('notificacoes.lidas') }}" class="ml-auto">
                @csrf
                <button type="submit"
                        class="font-mono text-[10px] uppercase tracking-caps text-ink-faint hover:text-brand transition">
                    Marcar lidas
                </button>
            </form>
        @endif
    </header>

    <div class="min-h-0 flex-1 max-h-[min(420px,60vh)] overflow-y-auto">
        @forelse ($notificacoes ?? [] as $aviso)
            @php
                // O mesmo vocabulário de gravidade da fila de ação: o aviso não
                // pode mudar de cor conforme a tela em que aparece.
                $tom = ['critico' => 'crit', 'atencao' => 'warn', 'marca' => 'brand'][$aviso->nivel] ?? 'brand';
            @endphp

            {{--
                Não lida = barra de 2px na cor do tipo + um degrau de superfície.
                Só o fundo não bastaria: num painel de doze linhas, um cinza
                levemente mais claro sem a barra se lê como zebra de tabela, e
                não como "isto é novo".
            --}}
            <a href="{{ $aviso->rota ?? url()->current() }}"
               @class([
                   'flex items-start gap-2.5 px-4 py-2.5 border-b border-rule last:border-b-0 border-l-2 transition hover:bg-chip',
                   'bg-chip' => $aviso->naoLida(),
                   'border-l-transparent' => ! $aviso->naoLida(),
               ])
               @if ($aviso->naoLida()) style="border-left-color: rgb(var(--{{ $tom }}))" @endif>
                <span class="shrink-0 mt-px h-6 w-6 rounded-tile flex items-center justify-center"
                      style="background: rgb(var(--{{ $tom }}) / var(--tint-alpha)); color: rgb(var(--{{ $tom }}))">
                    <span class="h-[13px] w-[13px]"><x-nav-icon :name="$aviso->icone" :peso="1.8" /></span>
                </span>

                <span class="min-w-0 flex-1">
                    {{-- Uma linha e truncado: o painel é relance. O título
                         inteiro vai no `title`, que entrega o resto sem custar
                         altura — e ou aparece inteiro, ou não aparece. --}}
                    <span class="block truncate text-[12.5px] leading-snug text-ink" title="{{ $aviso->titulo }}">
                        {{ $aviso->titulo }}
                    </span>
                    <span class="block truncate font-mono text-[10px] uppercase tracking-caps text-ink-faint">
                        {{ $aviso->meta ? $aviso->meta.' · ' : '' }}{{ $aviso->created_at->diffForHumans(short: true) }}
                    </span>
                </span>
            </a>
        @empty
            <p class="px-4 py-6 text-center text-[12.5px] text-ink-faint">Nada de novo por aqui.</p>
        @endforelse
    </div>

    {{--
        O sino conta o que MUDOU; a fila do Centro de Controle mostra o que
        EXIGE AÇÃO e vale enquanto durar. São perguntas diferentes — "3 receitas
        em atraso" não é um evento, é um estado —, e é por isso que o rodapé
        leva de uma à outra em vez de as duas listas tentarem ser a mesma.
    --}}
    @if ($veCentroDeControle)
        <footer class="h-[38px] shrink-0 flex items-center px-4 border-t border-line bg-head">
            <a href="{{ route('centro-controle') }}"
               class="font-mono text-[10px] uppercase tracking-caps text-ink-faint hover:text-brand transition">
                Ver tudo que exige ação
            </a>
        </footer>
    @endif
</div>
