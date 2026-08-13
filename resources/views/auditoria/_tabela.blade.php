{{-- O rastro. Espera: $registros, $filtros, $recursos, $acoes, $usuarios. --}}

<form method="GET" class="flex flex-wrap items-center gap-2">
    <label class="flex items-center gap-2 h-[34px] flex-1 min-w-[220px] max-w-[320px] px-3
                  rounded-control bg-input border border-line text-ink-faint focus-within:border-brand transition">
        <span class="h-4 w-4 shrink-0"><x-nav-icon name="search" /></span>
        <input type="search" name="busca" value="{{ $filtros['busca'] }}"
               placeholder="Buscar pessoa ou registro…"
               class="flex-1 min-w-0 bg-transparent border-0 p-0 text-[13px] text-ink placeholder-ink-faint focus:ring-0">
    </label>

    <select name="usuario" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
        <option value="">Todas as pessoas</option>
        @foreach ($usuarios as $usuario)
            <option value="{{ $usuario->id }}" @selected($filtros['usuario'] === (string) $usuario->id)>
                {{ $usuario->name }}{{ $usuario->trashed() ? ' (excluída)' : '' }}
            </option>
        @endforeach
    </select>

    <select name="recurso" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
        <option value="">Todas as áreas</option>
        @foreach ($recursos as $chave => $rotulo)
            <option value="{{ $chave }}" @selected($filtros['recurso'] === $chave)>{{ $rotulo }}</option>
        @endforeach
    </select>

    <select name="acao" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
        <option value="">Todas as ações</option>
        @foreach ($acoes as $chave => $rotulo)
            <option value="{{ $chave }}" @selected($filtros['acao'] === $chave)>{{ $rotulo }}</option>
        @endforeach
    </select>

    {{-- As duas datas ficam num bloco só, com o "até" grudado no "de": elas são
         UM filtro, e separadas pelo mesmo gap dos selects se leriam como dois. --}}
    <span class="flex items-center gap-1.5 h-[34px] px-2.5 rounded-control bg-input border border-line">
        <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint shrink-0">de</span>
        <input type="date" name="de" value="{{ $filtros['de'] }}"
               class="bg-transparent border-0 p-0 text-[12.5px] text-ink-dim focus:ring-0">
        <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint shrink-0">até</span>
        <input type="date" name="ate" value="{{ $filtros['ate'] }}"
               class="bg-transparent border-0 p-0 text-[12.5px] text-ink-dim focus:ring-0">
    </span>

    <button type="submit"
            class="h-[34px] px-3 rounded-control border border-btn-line text-ink-dim
                   text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
        Filtrar
    </button>

    @if (array_filter($filtros) !== [])
        <a href="{{ route('auditoria.index') }}"
           class="h-[34px] px-3 inline-flex items-center rounded-control text-ink-faint
                  text-[12.5px] font-semibold hover:text-ink transition">
            Limpar
        </a>
    @endif
</form>

<x-tabela min="1020px" class="tabela-zebrada">
    <thead>
        <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
            <th class="px-4 py-2.5 font-semibold">Quando</th>
            <th class="px-4 py-2.5 font-semibold">Quem</th>
            <th class="px-4 py-2.5 font-semibold">O quê</th>
            <th class="px-4 py-2.5 font-semibold">Sobre</th>
            <th class="px-4 py-2.5 font-semibold">Mudou</th>
        </tr>
    </thead>

    {{--
        O estado das linhas abertas mora no <tbody>, e não em cada <tr>.

        O detalhe do antes/depois precisa de uma linha PRÓPRIA, ocupando as
        cinco colunas: espremido na última célula, ele nasceria numa coluna de
        120px. E duas <tr> irmãs não compartilham escopo do Alpine — o `x-data`
        vale para o elemento e seus filhos, então o estado na primeira linha
        seria invisível para a segunda, que abriria e nunca fecharia.

        Um objeto, e não um id só: o dono do produto compara duas edições da
        mesma receita lado a lado, e um acordeão que fecha a anterior a cada
        clique obrigaria a decorar o que a primeira dizia.
    --}}
    <tbody x-data="{ abertas: {} }">
        @forelse ($registros as $registro)
            <tr class="border-b border-rule hover:bg-chip transition">
                <td class="px-4 py-3 whitespace-nowrap">
                    <p class="font-mono text-[12px] text-ink-dim">{{ $registro->created_at->format('d/m/Y') }}</p>
                    <p class="font-mono text-[11px] text-ink-faint">{{ $registro->created_at->format('H:i:s') }}</p>
                </td>

                <td class="px-4 py-3">
                    <div class="min-w-0">
                        {{-- O nome congelado na linha, e não o da conta hoje: a
                             linha tem de continuar dizendo quem era mesmo
                             depois de a conta ser renomeada ou excluída. --}}
                        <p class="text-[13px] text-ink truncate">{{ $registro->usuario_nome }}</p>
                        @if ($registro->ip)
                            <p class="font-mono text-[10.5px] text-ink-faint truncate">{{ $registro->ip }}</p>
                        @endif
                    </div>
                </td>

                <td class="px-4 py-3">
                    <div class="flex flex-col items-start gap-1">
                        <x-badge :tom="$registro->tomDaAcao()">{{ $registro->rotuloDaAcao() }}</x-badge>
                        <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                            {{ $recursos[$registro->recurso] ?? $registro->recurso }}
                        </span>
                    </div>
                </td>

                <td class="px-4 py-3">
                    <p class="text-[13px] text-ink-dim break-words">{{ $registro->descricao ?? '—' }}</p>
                </td>

                <td class="px-4 py-3 align-top">
                    @if ($registro->alteracoes)
                        @php $campos = count($registro->alteracoes); @endphp

                        <button type="button" @click="abertas[{{ $registro->id }}] = ! abertas[{{ $registro->id }}]"
                                class="inline-flex items-center gap-1.5 text-[12.5px] font-semibold
                                       text-ink-dim hover:text-brand transition">
                            <span x-text="abertas[{{ $registro->id }}] ? 'ocultar' : '{{ $campos }} campo{{ $campos > 1 ? 's' : '' }}'"></span>
                            <span class="h-3.5 w-3.5 shrink-0 transition"
                                  :class="abertas[{{ $registro->id }}] && 'rotate-180'"><x-nav-icon name="chevron-down" /></span>
                        </button>
                    @else
                        <span class="text-[13px] text-ink-mute">—</span>
                    @endif
                </td>
            </tr>

            @if ($registro->alteracoes)
                <tr x-show="abertas[{{ $registro->id }}]" x-cloak class="border-b border-rule bg-chip/40">
                    <td colspan="5" class="px-4 py-3">
                        @include('auditoria._alteracoes', ['alteracoes' => $registro->alteracoes])
                    </td>
                </tr>
            @endif
        @empty
            <tr>
                <td colspan="5" class="px-4 py-8 text-center text-[13px] text-ink-mute">
                    Nenhum registro com esse recorte.
                </td>
            </tr>
        @endforelse
    </tbody>

    <x-slot name="rodape">
        <span>{{ $registros->count() }} de {{ $registros->total() }} registros</span>
        <span>· página {{ $registros->currentPage() }} de {{ $registros->lastPage() }}</span>
    </x-slot>
</x-tabela>

@if ($registros->hasPages())
    <div>{{ $registros->links() }}</div>
@endif
