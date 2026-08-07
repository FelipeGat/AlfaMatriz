<x-app-layout>
    <x-slot name="titulo">Licenças</x-slot>
    <x-slot name="contexto">{{ number_format($licencas->total(), 0, ',', '.') }} nesta faixa</x-slot>

    {{--
        Só leitura por enquanto. Liberar, renovar e bloquear entram quando a
        matriz passar a ser dona do cadastro — um botão que não faz nada seria
        pior que a ausência dele, porque promete um poder que ainda não existe.
    --}}
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            @php
                $faixas = [
                    'pendentes' => ['Pendentes', 'atencao'],
                    'vencendo' => ['Vencendo em '.$dias.' dias', 'atencao'],
                    'vencidas' => ['Vencidas', 'critico'],
                    'ativas' => ['Ativas', 'bom'],
                ];
            @endphp

            <div class="flex flex-wrap items-center gap-0.5 rounded-control border border-line bg-input" style="padding: 3px">
                @foreach ($faixas as $chave => [$rotulo, $tom])
                    <a href="{{ route('integracao.licencas', array_merge($filtros, ['faixa' => $chave])) }}"
                       class="h-7 px-2.5 rounded-tile inline-flex items-center gap-1.5 font-mono text-[10.5px] uppercase tracking-caps transition
                              {{ $faixa === $chave ? 'text-brand-text' : 'text-ink-mute hover:text-ink' }}"
                       @style(['background: rgb(var(--brand) / 0.14)' => $faixa === $chave])>
                        {{ $rotulo }}
                        <span class="tabular">{{ number_format($contagens[$chave], 0, ',', '.') }}</span>
                    </a>
                @endforeach
            </div>

            <form method="GET" class="flex items-center gap-2">
                <input type="hidden" name="faixa" value="{{ $faixa }}">
                <select name="sistema" onchange="this.form.submit()"
                        class="h-8 rounded-control border-line bg-input text-[12px] text-ink">
                    <option value="">Todos os sistemas</option>
                    @foreach ($sistemas as $sistema)
                        <option value="{{ $sistema->id }}" @selected(($filtros['sistema'] ?? null) == $sistema->id)>{{ $sistema->nome }}</option>
                    @endforeach
                </select>
            </form>

            <div class="ml-auto">
                <x-atualizado-em :em="$atualizadoEm" vazio="nunca sincronizado" />
            </div>
        </div>

        <x-tabela min="1080px">
            <thead>
                <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                    <th class="px-4 py-2.5 font-semibold">Sistema</th>
                    <th class="px-4 py-2.5 font-semibold">Cliente</th>
                    <th class="px-4 py-2.5 font-semibold">Plano</th>
                    <th class="px-4 py-2.5 font-semibold">Situação</th>
                    <th class="px-4 py-2.5 font-semibold">Vigência</th>
                    <th class="px-4 py-2.5 font-semibold">Vence</th>
                    <th class="px-4 py-2.5 font-semibold">Efeito do bloqueio</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($licencas as $licenca)
                    @php($restam = $licenca->diasParaVencer())
                    <tr class="border-b border-line last:border-0 hover:bg-chip transition">
                        <td class="px-4 py-2.5">
                            <span class="inline-flex items-center gap-2">
                                <span class="h-5 w-5 shrink-0"><x-marca-sistema :sistema="$licenca->sistema" /></span>
                                <span class="text-[12px] text-ink-dim">{{ $licenca->sistema?->nome }}</span>
                            </span>
                        </td>

                        <td class="px-4 py-2.5">
                            <p class="text-[13px] text-ink">{{ $licenca->sistemaCliente?->nome }}</p>
                            @unless ($licenca->sistemaCliente?->cliente)
                                <span class="font-mono text-[10px] uppercase tracking-caps text-warn">sem vínculo na matriz</span>
                            @endunless
                        </td>

                        <td class="px-4 py-2.5 text-[12px] text-ink-dim">{{ $licenca->plano ?? '—' }}</td>

                        <td class="px-4 py-2.5">
                            <x-badge :tom="match ($licenca->status) {
                                'ativa' => 'bom',
                                'pendente' => 'atencao',
                                'vencida', 'bloqueada', 'cancelada' => 'critico',
                                default => 'neutro',
                            }">{{ $licenca->status }}</x-badge>
                        </td>

                        <td class="px-4 py-2.5 font-mono text-[12px] text-ink-dim tabular whitespace-nowrap">
                            {{ $licenca->inicio_em?->format('d/m/Y') ?? '—' }}
                            →
                            {{ $licenca->fim_em?->format('d/m/Y') ?? 'sem fim' }}
                        </td>

                        <td class="px-4 py-2.5 whitespace-nowrap">
                            @if ($restam === null)
                                <span class="text-[12px] text-ink-faint">não expira</span>
                            @elseif ($restam < 0)
                                <span class="text-[12px] text-crit">venceu há {{ abs($restam) }} {{ abs($restam) === 1 ? 'dia' : 'dias' }}</span>
                            @else
                                <span class="text-[12px] {{ $restam <= 7 ? 'text-warn' : 'text-ink-dim' }}">
                                    em {{ $restam }} {{ $restam === 1 ? 'dia' : 'dias' }}
                                </span>
                            @endif
                        </td>

                        {{--
                            Esta coluna existe para a matriz não mentir: em
                            alguns sistemas vencer a licença barra o login, em
                            outros é decorativo. Sem dizer qual é qual, a tela
                            prometeria um efeito que não acontece.
                        --}}
                        <td class="px-4 py-2.5">
                            @if ($licenca->bloqueia_acesso)
                                <x-badge tom="critico">barra o acesso</x-badge>
                            @else
                                <x-badge tom="neutro">só marca, não barra</x-badge>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-[13px] text-ink-dim">
                            Nenhuma licença nesta faixa.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-tabela>

        {{ $licencas->links() }}
    </div>
</x-app-layout>
