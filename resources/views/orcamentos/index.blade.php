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

    {{-- docs/specs/orcamentos-vixcard: a lista, agrupada por cliente desde 20/10/2026.
         Substituído só aparece filtrando — a revisão nova já está na lista com o mesmo número. --}}
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
            {{-- Por cliente (padrao-e-historico.md, FR-H2), o mais recente primeiro: uma tabela só,
                 com cabeçalho único e uma faixa por cliente — as colunas alinham entre os clientes. --}}
            @if ($grupos->isEmpty())
                <p class="px-4 py-10 text-center text-[13.5px] text-ink-mute">
                    {{ $busca !== '' || $situacao ? 'Nenhum orçamento neste filtro.' : 'Nenhum orçamento ainda. Comece pelo Novo orçamento: escolha o produto e ajuste o cliente e os valores.' }}
                </p>
            @else
                <x-tabela min="760px" :fixa="true" class="!rounded-none !border-0 !bg-transparent">
                    <colgroup><col style="width: 96px"><col><col style="width: 170px"><col style="width: 130px"><col style="width: 120px"></colgroup>
                    <thead>
                        <tr class="border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                            <th class="px-4 py-2 text-left font-normal">Número</th><th class="px-3 py-2 text-left font-normal">Proposta</th>
                            <th class="px-3 py-2 text-right font-normal">Valor</th><th class="px-3 py-2 text-left font-normal">Situação</th><th class="px-4 py-2 text-right font-normal">Arquivos</th>
                        </tr>
                    </thead>
                    @foreach ($grupos as $g)
                        @php $vigentes = $g['orcamentos']->where('situacao', '!=', 'substituido'); @endphp
                        <tbody x-data="{ aberto: true }" class="border-b border-line last:border-b-0">
                            <tr class="bg-head/50">
                                <td colspan="5" class="px-4 py-2">
                                    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                        <button type="button" @click="aberto = ! aberto" class="flex min-w-0 items-center gap-2 text-left" :aria-expanded="aberto.toString()">
                                            <span class="inline-block w-3 text-[10px] text-ink-faint" x-text="aberto ? '▾' : '▸'"></span>
                                            <span class="truncate text-[14px] font-semibold text-ink">{{ $g['nome'] }}</span>
                                        </button>
                                        <span class="rounded-badge bg-surface px-1.5 py-0.5 text-[11px] text-ink-dim">{{ $g['projeto'] }}</span>
                                        @if ($g['lead'])
                                            <a href="{{ route('leads.index', ['lead' => $g['lead']->id]) }}" class="rounded-badge border border-line px-1.5 py-0.5 text-[11px] text-ink-dim hover:border-brand hover:text-brand" title="Abrir no Funil de Vendas">{{ \App\Models\Lead::ESTAGIOS[$g['lead']->estagio] ?? $g['lead']->estagio }}</a>
                                        @endif
                                        <span class="ml-auto text-[12px] tabular-nums text-ink-mute">
                                            {{ $vigentes->count() }} {{ $vigentes->count() === 1 ? 'proposta' : 'propostas' }}@if ($vigentes->sum('total_unico') > 0) · {{ $brl($vigentes->sum('total_unico')) }}@endif
                                        </span>
                                        @if ($g['lead'] && auth()->user()->canPermissao('orcamentos', 'incluir'))
                                            <a href="{{ route('orcamentos.create', ['lead' => $g['lead']->id]) }}" class="flex h-6 w-6 items-center justify-center rounded-control border border-btn-line text-[14px] leading-none text-ink-dim hover:border-brand hover:text-brand" title="Novo orçamento para {{ $g['nome'] }}" aria-label="Novo orçamento para {{ $g['nome'] }}">+</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @foreach ($g['orcamentos'] as $o)
                                @php $vence = $o->situacao === 'enviado' ? $o->validoAte() : null; @endphp
                                <tr x-show="aberto" class="cursor-pointer align-top hover:bg-head/40" onclick="if (! event.target.closest('a,button')) location.href = @js(route('orcamentos.edit', $o))">
                                    <td class="whitespace-nowrap px-4 py-2.5 font-mono text-[12.5px] text-ink-dim">{{ $o->codigo(false) }}@if ($o->revisao > 1)<span class="block text-[11px] text-ink-faint">Rev. {{ str_pad((string) $o->revisao, 2, '0', STR_PAD_LEFT) }}</span>@endif</td>
                                    <td class="min-w-0 px-3 py-2.5">
                                        <a href="{{ route('orcamentos.edit', $o) }}" class="block truncate text-[13.5px] font-medium text-ink hover:text-brand" title="{{ $o->titulo }}">{{ $o->titulo }}</a>
                                        <p class="truncate text-[11.5px] text-ink-mute">{{ $o->tipo ?: 'Proposta comercial' }} · {{ ($o->enviado_em ?? $o->created_at)->format('d/m/Y') }}</p>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right tabular-nums">
                                        <span class="text-[13px] text-ink">{{ $o->total_unico > 0 ? $brl($o->total_unico) : '—' }}</span>
                                        @if ($o->total_material > 0 && $o->total_implantacao > 0)<p class="text-[11px] text-ink-faint" title="Material · Implantação">{{ $brl($o->total_material) }} · {{ $brl($o->total_implantacao) }}</p>
                                        @elseif ($o->total_unico > 0)<p class="text-[11px] text-ink-faint">{{ $o->total_material > 0 ? 'material' : 'implantação' }}</p>@endif
                                        @if ($o->total_mensal > 0)<p class="text-[11.5px] font-medium text-brand-text">+ {{ $brl($o->total_mensal) }}/mês</p>@endif
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <x-badge :tom="$cor[$o->situacao]" ponto>{{ Orcamento::SITUACOES[$o->situacao] }}</x-badge>
                                        @if ($vence)<p class="mt-0.5 text-[11px] {{ $vence->isPast() ? 'text-crit' : ($vence->lte(now()->addDays(3)) ? 'text-warn' : 'text-ink-faint') }}">{{ $vence->isPast() ? 'venceu '.$vence->format('d/m') : 'vale até '.$vence->format('d/m') }}</p>@endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-right text-[12px]">
                                        <a href="{{ route('orcamentos.pdf', $o) }}" target="_blank" rel="noopener" class="font-semibold text-brand-text hover:underline" title="PDF no padrão novo">PDF</a>
                                        @if ($o->pdf_original)<span class="text-ink-faint"> · </span><a href="{{ route('orcamentos.original', $o) }}" target="_blank" rel="noopener" class="text-ink-dim hover:text-brand hover:underline" title="O PDF que foi enviado ao cliente">Original</a>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach
                </x-tabela>
            @endif

        </section>
    </div>
</x-app-layout>
