@php
    use App\Models\Orcamento;
    use App\Services\Orcamentos\Modelos;
    $brl = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    $botao = 'inline-flex h-[28px] items-center gap-1 whitespace-nowrap rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim hover:border-brand hover:text-brand';
    $cor = ['rascunho' => 'neutro', 'enviado' => 'marca', 'aprovado' => 'bom', 'recusado' => 'critico', 'substituido' => 'atencao'];
@endphp
<x-app-layout>
    <x-slot name="titulo">Orçamentos</x-slot>
    <x-slot name="contexto"><span>Comercial · VixCard · PDF no padrão das propostas</span></x-slot>

    {{-- docs/specs/orcamentos-vixcard: a lista. Substituído só aparece filtrando — a
         revisão nova já está na lista com o mesmo número. --}}
    <div class="mx-auto w-full max-w-7xl space-y-4">
        @if (session('status'))<x-aviso>{{ session('status') }}</x-aviso>@endif

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ([
                ['Em aberto', $kpis['abertos'], 'text-ink'],
                ['Enviados no mês', $brl($kpis['enviados']), 'text-brand-text'],
                ['Aprovados no mês', $brl($kpis['aprovados']), 'text-good'],
                ['Vencem em 3 dias', $kpis['vencendo'], $kpis['vencendo'] ? 'text-warn' : 'text-ink'],
            ] as [$rotulo, $valor, $tom])
                <div class="rounded-panel border border-line bg-surface px-3.5 py-3">
                    <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">{{ $rotulo }}</p>
                    <p class="font-display text-[20px] font-semibold leading-tight tabular-nums {{ $tom }}">{{ $valor }}</p>
                </div>
            @endforeach
        </div>

        <section class="rounded-panel border border-line bg-surface">
            <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-line p-3">
                <label class="relative min-w-[220px] flex-1">
                    <span class="pointer-events-none absolute left-2.5 top-2 h-4 w-4 text-ink-faint"><x-nav-icon name="search" /></span>
                    <input type="search" name="q" value="{{ $busca }}" placeholder="Buscar cliente, contato, título ou número (15/2026)…" class="w-full rounded-control border border-btn-line bg-canvas py-1.5 pl-8 pr-2 text-[13px] text-ink placeholder:text-ink-faint focus:border-brand focus:outline-none">
                </label>
                <select name="situacao" onchange="this.form.submit()" class="rounded-control border border-btn-line bg-canvas py-1.5 pl-2 pr-7 text-[13px] text-ink">
                    <option value="">Todas (sem as substituídas)</option>
                    @foreach (Orcamento::SITUACOES as $k => $nome)<option value="{{ $k }}" @selected($situacao === $k)>{{ $nome }}</option>@endforeach
                </select>
                @if (auth()->user()->canPermissao('orcamentos', 'incluir'))
                    <a href="{{ route('orcamentos.create') }}" class="inline-flex h-[30px] items-center gap-1.5 rounded-control bg-brand px-3 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright"><span class="h-4 w-4"><x-nav-icon name="plus" /></span> Novo orçamento</a>
                @endif
            </form>
            <x-tabela min="860px" class="!rounded-none !border-0 !bg-transparent">
                <thead>
                    <tr class="border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                        <th class="px-4 py-2.5 font-normal">Número</th><th class="px-3 py-2.5 font-normal">Cliente</th><th class="px-3 py-2.5 font-normal">Produto</th>
                        <th class="px-3 py-2.5 text-right font-normal">Total</th><th class="px-3 py-2.5 font-normal">Validade</th><th class="px-3 py-2.5 font-normal">Situação</th><th class="px-3 py-2.5 font-normal">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($lista as $o)
                        @php $vence = $o->situacao === 'enviado' ? $o->validoAte() : null; @endphp
                        <tr class="align-top hover:bg-head/50">
                            <td class="whitespace-nowrap px-4 py-3"><a href="{{ route('orcamentos.edit', $o) }}" class="text-[13.5px] font-semibold text-ink hover:text-brand">{{ $o->codigo(false) }}</a>
                                @if ($o->revisao > 1)<p class="text-[11.5px] text-ink-mute">Rev. {{ str_pad((string) $o->revisao, 2, '0', STR_PAD_LEFT) }}</p>@endif</td>
                            <td class="px-3 py-3"><a href="{{ route('orcamentos.edit', $o) }}" class="text-[13.5px] font-medium text-ink hover:text-brand">{{ $o->cliente }}</a>
                                <p class="text-[12px] text-ink-mute">{{ collect([$o->contato, $o->cidade])->filter()->implode(' · ') ?: $o->titulo }}</p></td>
                            <td class="px-3 py-3 text-[13px] text-ink-dim">{{ Modelos::nome($o->produto) }}</td>
                            <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums text-[13px] text-ink">{{ $o->total_unico > 0 ? $brl($o->total_unico) : '—' }}
                                @if ($o->total_mensal > 0)<p class="text-[11.5px] text-ink-mute">+ {{ $brl($o->total_mensal) }}/mês</p>@endif</td>
                            <td class="whitespace-nowrap px-3 py-3 text-[12.5px]">
                                @if ($vence)
                                    {{ $vence->format('d/m') }}
                                    <p class="text-[11.5px] {{ $vence->isPast() ? 'text-crit' : ($vence->lte(now()->addDays(3)) ? 'text-warn' : 'text-ink-mute') }}">{{ $vence->isPast() ? 'vencido' : 'em '.(int) ceil(now()->diffInDays($vence)).' dia(s)' }}</p>
                                @else <span class="text-ink-faint">—</span> @endif
                            </td>
                            <td class="px-3 py-3"><x-badge :tom="$cor[$o->situacao]" ponto>{{ Orcamento::SITUACOES[$o->situacao] }}</x-badge></td>
                            <td class="px-3 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <a href="{{ route('orcamentos.pdf', $o) }}" target="_blank" rel="noopener" class="{{ $botao }}">PDF</a>
                                    <a href="{{ route('orcamentos.edit', $o) }}" class="{{ $botao }}">{{ $o->editavel() ? 'Editar' : 'Abrir' }}</a>
                                    <form method="POST" action="{{ route('orcamentos.duplicar', $o) }}">@csrf<button class="{{ $botao }}">Duplicar</button></form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-[13.5px] text-ink-mute">
                            {{ $busca !== '' || $situacao ? 'Nenhum orçamento neste filtro.' : 'Nenhum orçamento ainda. Comece pelo Novo orçamento: escolha o produto e ajuste o cliente e os valores.' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </x-tabela>
            <div class="px-4 py-2">{{ $lista->links() }}</div>
        </section>
    </div>
</x-app-layout>
