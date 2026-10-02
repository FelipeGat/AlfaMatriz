@php
    /**
     * O código ligado à tarefa pelo GitHub (#211): PRs com o estado e commits
     * com o sha curto, mais recentes primeiro.
     *
     * Só aparece quando há o que mostrar: tarefa sem commit (a operacional, a
     * que ainda não começou) não precisa de uma seção vazia dizendo isso.
     *
     * PRs antes dos commits: o PR é a unidade que se revisa e se mescla, e é o
     * link que quem abre a tarefa procura primeiro. Recolhe depois de cinco
     * linhas — uma tarefa grande junta dezenas de commits, e a lista inteira
     * empurraria anexos e conversa para fora do modal.
     *
     * Tudo que vem do GitHub é escapado pelo `{{ }}`, e o `href` só existe
     * quando o endereço é http/https (`urlSegura`).
     *
     * Espera: $tarefa.
     */
    $referencias = $tarefa->referenciasGit;
    $linhas = $referencias->filter->ehPr()->concat($referencias->reject->ehPr())->values();
    $visiveis = 5;
@endphp

@if ($linhas->isNotEmpty())
    <div class="pt-4 border-t border-rule-strong" x-data="{ todas: false }">
        <div class="flex items-center gap-2">
            <span class="h-3.5 w-3.5 shrink-0 text-ink-mute"><x-nav-icon name="code" :peso="1.8" /></span>
            <h4 class="font-mono text-[11.5px] font-semibold uppercase tracking-caps-wide text-ink">Código</h4>
            <span class="font-sans tabular text-[10.5px] text-ink-mute">{{ $linhas->count() }}</span>
        </div>

        <ul class="mt-2 space-y-1">
            @foreach ($linhas as $i => $ref)
                @php
                    $url = $ref->urlSegura();
                    $corDoEstado = match ($ref->estado) {
                        'mesclado' => 'good',
                        'aberto' => 'brand',
                        default => 'ink-faint',
                    };
                @endphp
                <li @if ($i >= $visiveis) x-show="todas" x-cloak @endif
                    class="flex items-center gap-2 rounded-control px-1 py-1 hover:bg-chip transition">
                    {{-- O identificador é o link: no PR o número, no commit o sha
                         curto — é o que se cola numa conversa sobre o código. --}}
                    @if ($url)
                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"
                           class="shrink-0 font-mono text-[11px] text-brand-text underline transition hover:text-brand">
                            {{ $ref->ehPr() ? 'PR #'.$ref->numero : $ref->shaCurto() }}
                        </a>
                    @else
                        <span class="shrink-0 font-mono text-[11px] text-ink-mute">
                            {{ $ref->ehPr() ? 'PR #'.$ref->numero : $ref->shaCurto() }}
                        </span>
                    @endif

                    {{-- `truncate`, e não `nowrap` solto: sem o corte, a
                         mensagem longa pinta por cima do estado (armadilha 1). --}}
                    <span class="min-w-0 flex-1 truncate text-[12.5px] text-ink"
                          title="{{ $ref->titulo }}{{ $ref->autor_github ? ' — '.$ref->autor_github : '' }}{{ $ref->branch ? ' · '.$ref->branch : '' }}">
                        {{ $ref->titulo }}
                    </span>

                    @if ($ref->ehPr())
                        <span class="shrink-0 font-mono text-[9.5px] uppercase tracking-[0.06em]"
                              style="color: rgb(var(--{{ $corDoEstado }}))">
                            {{ $ref->rotuloDoEstado() }}
                        </span>
                    @elseif ($ref->autor_github)
                        <span class="shrink-0 text-[11px] text-ink-faint">{{ $ref->autor_github }}</span>
                    @endif
                </li>
            @endforeach
        </ul>

        @if ($linhas->count() > $visiveis)
            <button type="button" @click="todas = ! todas"
                    class="mt-1.5 inline-flex items-center gap-1.5 px-1 text-[11.5px] font-medium text-ink-dim transition hover:text-ink">
                <span class="h-3 w-3 transition" :class="todas && 'rotate-180'">
                    <x-nav-icon name="chevron-down" :peso="1.8" />
                </span>
                <span x-text="todas ? 'Mostrar menos' : 'Ver mais {{ $linhas->count() - $visiveis }}'"></span>
            </button>
        @endif
    </div>
@endif
