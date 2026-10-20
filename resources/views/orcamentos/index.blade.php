@php
    use App\Models\Orcamento;
    use App\Services\Orcamentos\Painel;
    $brl = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    $brlCurto = fn ($v) => $v >= 1000 ? 'R$ '.number_format($v / 1000, $v >= 10000 ? 0 : 1, ',', '.').' mil' : $brl($v);
    $campo = 'rounded-control border border-btn-line bg-canvas py-1.5 pl-2 pr-7 text-[13px] text-ink';
    $cor = ['rascunho' => 'neutro', 'enviado' => 'marca', 'negociacao' => 'ambar', 'aprovado' => 'bom', 'recusado' => 'critico', 'substituido' => 'atencao'];
    // Uma cor por situação, a mesma nos cartões, no gráfico e na rosca.
    $tons = ['aberto' => 'accent', 'negociacao' => 'amber', 'aprovado' => 'good', 'recusado' => 'crit'];
    $nomes = ['aberto' => 'Em aberto', 'negociacao' => 'Em negociação', 'aprovado' => 'Aprovados', 'recusado' => 'Recusados'];
    $p = $painel;
@endphp
<x-app-layout>
    <x-slot name="titulo">Orçamentos</x-slot>
    <x-slot name="contexto"><span>Comercial · VixSoftHouse · propostas, conversão e valores</span></x-slot>

    {{-- docs/specs/orcamentos-vixcard/padrao-e-historico.md (FR-H11): o painel da tela no
         molde que o Felipe mandou em 20/10/2026 — indicadores e gráficos do período, sobre as
         versões vigentes; a lista embaixo tem os próprios filtros. --}}
    <div class="mx-auto w-full max-w-[1500px] space-y-3">
        @if (session('status'))<x-aviso>{{ session('status') }}</x-aviso>@endif

        <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
                <h1 class="font-display text-[20px] font-semibold text-ink">Orçamentos</h1>
                <p class="text-[12.5px] text-ink-mute">Acompanhe propostas, valores e conversão. Indicadores do período escolhido.</p>
            </div>
            <form method="GET" class="flex items-center gap-2">
                @foreach (collect($filtros)->except('periodo')->filter() as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                <select name="periodo" onchange="this.form.submit()" class="{{ $campo }}" aria-label="Período dos indicadores">
                    @foreach (Painel::PERIODOS as $k => $nome)<option value="{{ $k }}" @selected($p['periodo'] === (string) $k)>{{ $nome }}</option>@endforeach
                </select>
                <span class="hidden text-[12px] text-ink-faint sm:inline">{{ $p['de'] ? $p['de']->format('d/m/Y').' → '.$p['ate']->format('d/m/Y') : 'todo o histórico' }}</span>
            </form>
        </div>

        {{-- Indicadores --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <div class="col-span-2 rounded-panel border border-line bg-card-grad px-4 py-3.5 lg:col-span-1">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-tile" style="background: rgb(var(--accent) / var(--tint-alpha)); color: rgb(var(--accent))"><span class="h-5 w-5"><x-nav-icon name="document" /></span></span>
                    <div class="min-w-0"><p class="text-[11.5px] text-ink-mute">Total no período</p><p class="font-display text-[22px] font-semibold leading-tight tabular-nums text-ink">{{ $brl($p['total']['valor']) }}</p></div>
                </div>
                <p class="mt-2 text-[11.5px] text-ink-mute">
                    {{ $p['total']['qtd'] }} {{ $p['total']['qtd'] === 1 ? 'proposta' : 'propostas' }}@if ($p['total']['mensal'] > 0) · + {{ $brl($p['total']['mensal']) }}/mês @endif
                    @if ($p['total']['delta'] !== null)<span class="ml-1 rounded-badge px-1.5 py-0.5 font-semibold {{ $p['total']['delta'] >= 0 ? 'bg-good-tint text-good' : 'bg-crit-tint text-crit' }}">{{ $p['total']['delta'] >= 0 ? '↑' : '↓' }} {{ abs($p['total']['delta']) }}%</span> vs. anterior @endif
                </p>
            </div>
            @foreach (['aberto' => 'clock', 'aprovado' => 'check-circle', 'negociacao' => 'repeat', 'recusado' => 'x-mark'] as $chave => $icone)
                @php $k = $p[$chave]; $tom = $tons[$chave]; @endphp
                <div class="rounded-panel border border-line bg-card-grad px-4 py-3.5">
                    <div class="flex items-center gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-tile" style="background: rgb(var(--{{ $tom }}) / var(--tint-alpha)); color: rgb(var(--{{ $tom }}))"><span class="h-5 w-5"><x-nav-icon :name="$icone" /></span></span>
                        <div class="min-w-0"><p class="text-[11.5px] text-ink-mute">{{ $nomes[$chave] }}</p><p class="font-display text-[22px] font-semibold leading-tight tabular-nums text-ink">{{ $k['qtd'] }}</p></div>
                    </div>
                    <p class="mt-2 text-[11.5px] tabular-nums text-ink-mute">{{ $brl($k['valor']) }}@if ($k['mensal'] > 0) <span class="text-ink-faint">+ {{ $brlCurto($k['mensal']) }}/mês</span>@endif · {{ $k['pct'] }}%</p>
                    <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-head"><div class="h-full rounded-full" style="width: {{ max(2, $k['pct']) }}%; background: rgb(var(--{{ $tom }}))"></div></div>
                </div>
            @endforeach
        </div>

        {{-- Gráficos --}}
        <div class="grid gap-3 xl:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)_minmax(0,1fr)]">
            {{-- Evolução: barras empilhadas por situação (valor do projeto). --}}
            <section class="rounded-panel border border-line bg-surface p-4">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-[13.5px] font-semibold text-ink">Evolução dos orçamentos <small class="font-normal text-ink-faint">valor por {{ $p['porSemana'] ? 'semana' : 'dia' }}</small></h2>
                    <div class="flex flex-wrap gap-2.5 text-[11px] text-ink-mute">
                        @foreach (['aprovado', 'negociacao', 'aberto', 'recusado'] as $s)<span class="flex items-center gap-1"><i class="inline-block h-2 w-2 rounded-full" style="background: rgb(var(--{{ $tons[$s] }}))"></i>{{ $nomes[$s] }}</span>@endforeach
                    </div>
                </div>
                @php
                    $serie = $p['serie'];
                    $maior = max(1, collect($serie)->map(fn ($d) => $d['aberto'] + $d['negociacao'] + $d['aprovado'] + $d['recusado'])->max());
                    $n = max(1, count($serie)); $larg = 620; $alt = 170; $passo = $larg / $n; $barra = max(3, min(16, $passo * 0.62));
                    $rotulosA = $n > 12 ? (int) ceil($n / 6) : 1;
                @endphp
                <svg viewBox="0 0 {{ $larg }} {{ $alt + 22 }}" class="h-auto w-full" role="img" aria-label="Evolução do valor dos orçamentos">
                    @foreach ([0.25, 0.5, 0.75, 1] as $f)<line x1="0" x2="{{ $larg }}" y1="{{ $alt - $alt * $f }}" y2="{{ $alt - $alt * $f }}" stroke="var(--line)" stroke-dasharray="3 4"/>@endforeach
                    <text x="2" y="10" font-size="10" fill="rgb(var(--ink-faint))">{{ $brlCurto($maior) }}</text>
                    @foreach ($serie as $i => $d)
                        @php $y = $alt; $x = $i * $passo + ($passo - $barra) / 2; @endphp
                        @foreach (['aprovado', 'negociacao', 'aberto', 'recusado'] as $s)
                            @if ($d[$s] > 0)
                                @php $h = max(1.5, $alt * $d[$s] / $maior); $y -= $h; @endphp
                                <rect x="{{ $x }}" y="{{ $y }}" width="{{ $barra }}" height="{{ $h }}" rx="1.5" fill="rgb(var(--{{ $tons[$s] }}))"><title>{{ $d['rotulo'] }} · {{ $nomes[$s] }}: {{ $brl($d[$s]) }}</title></rect>
                            @endif
                        @endforeach
                        @if ($i % $rotulosA === 0)<text x="{{ $x + $barra / 2 }}" y="{{ $alt + 15 }}" font-size="10" text-anchor="middle" fill="rgb(var(--ink-faint))">{{ $d['rotulo'] }}</text>@endif
                    @endforeach
                </svg>
            </section>

            {{-- Rosca por situação (quantidade). --}}
            <section class="rounded-panel border border-line bg-surface p-4">
                <h2 class="mb-2 text-[13.5px] font-semibold text-ink">Orçamentos por situação</h2>
                @php $totalSit = max(1, array_sum($p['situacoes'])); $r = 52; $circ = 2 * M_PI * $r; $acum = 0; @endphp
                <div class="flex items-center gap-4">
                    <svg viewBox="0 0 140 140" class="h-[140px] w-[140px] shrink-0 -rotate-90" role="img" aria-label="Orçamentos por situação">
                        <circle cx="70" cy="70" r="{{ $r }}" fill="none" stroke="rgb(var(--head))" stroke-width="18"/>
                        @foreach ($p['situacoes'] as $s => $qtd)
                            @if ($qtd > 0)
                                @php $frac = $qtd / $totalSit; @endphp
                                <circle cx="70" cy="70" r="{{ $r }}" fill="none" stroke="rgb(var(--{{ $tons[$s] }}))" stroke-width="18"
                                        stroke-dasharray="{{ $circ * $frac }} {{ $circ }}" stroke-dashoffset="{{ -$circ * $acum }}"><title>{{ $nomes[$s] }}: {{ $qtd }}</title></circle>
                                @php $acum += $frac; @endphp
                            @endif
                        @endforeach
                    </svg>
                    <div class="min-w-0 flex-1">
                        <p class="font-display text-[22px] font-semibold leading-none text-ink">{{ array_sum($p['situacoes']) }}</p>
                        <p class="mb-2 text-[11px] text-ink-mute">orçamentos</p>
                        @foreach ($p['situacoes'] as $s => $qtd)
                            <div class="flex items-center gap-2 py-0.5 text-[12px]">
                                <i class="inline-block h-2.5 w-2.5 rounded-full" style="background: rgb(var(--{{ $tons[$s] }}))"></i>
                                <span class="flex-1 text-ink-dim">{{ $nomes[$s] }}</span>
                                <b class="tabular-nums text-ink">{{ $qtd }}</b><span class="w-9 text-right tabular-nums text-ink-faint">{{ round(100 * $qtd / $totalSit) }}%</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            {{-- Top clientes por valor do projeto. --}}
            <section class="rounded-panel border border-line bg-surface p-4">
                <h2 class="mb-3 text-[13.5px] font-semibold text-ink">Top clientes <small class="font-normal text-ink-faint">valor do projeto</small></h2>
                @php $lider = max(1, collect($p['top'])->max('valor') ?? 1); @endphp
                @forelse ($p['top'] as $t)
                    <div class="mb-2.5 grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1">
                        <span class="truncate text-[12.5px] text-ink" title="{{ $t['nome'] }}">{{ $t['nome'] }}</span>
                        <span class="text-right text-[12.5px] font-semibold tabular-nums text-ink">{{ $brl($t['valor']) }}</span>
                        <div class="col-span-2 h-1.5 overflow-hidden rounded-full bg-head"><div class="h-full rounded-full bg-brand" style="width: {{ max(3, 100 * $t['valor'] / $lider) }}%"></div></div>
                    </div>
                @empty
                    <p class="text-[12.5px] text-ink-mute">Sem valores no período.</p>
                @endforelse
            </section>
        </div>

        {{-- Filtros e lista --}}
        <section class="rounded-panel border border-line bg-surface">
            <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-line p-3">
                <input type="hidden" name="periodo" value="{{ $p['periodo'] }}">
                <label class="relative min-w-[220px] flex-1">
                    <span class="pointer-events-none absolute left-2.5 top-2 h-4 w-4 text-ink-faint"><x-nav-icon name="search" /></span>
                    <input type="search" name="q" value="{{ $busca }}" placeholder="Buscar por cliente, contato, título ou número (001)…" class="w-full rounded-control border border-btn-line bg-canvas py-1.5 pl-8 pr-2 text-[13px] text-ink placeholder:text-ink-faint focus:border-brand focus:outline-none">
                </label>
                <select name="situacao" onchange="this.form.submit()" class="{{ $campo }}" aria-label="Situação">
                    <option value="">Todas as situações</option>
                    @foreach (Orcamento::SITUACOES as $k => $nome)<option value="{{ $k }}" @selected(($filtros['situacao'] ?? null) === $k)>{{ $nome }}</option>@endforeach
                </select>
                <select name="projeto" onchange="this.form.submit()" class="{{ $campo }}" aria-label="Projeto">
                    <option value="">Todos os projetos</option>
                    @foreach ($projetos as $pr)<option value="{{ $pr->id }}" @selected((int) ($filtros['projeto'] ?? 0) === $pr->id)>{{ $pr->nome }}</option>@endforeach
                </select>
                <select name="responsavel" onchange="this.form.submit()" class="{{ $campo }}" aria-label="Responsável">
                    <option value="">Todos os responsáveis</option>
                    @foreach ($responsaveis as $u)<option value="{{ $u->id }}" @selected((int) ($filtros['responsavel'] ?? 0) === $u->id)>{{ $u->name }}</option>@endforeach
                </select>
                @if (auth()->user()->canPermissao('orcamentos', 'editar'))
                    <a href="{{ route('orcamentos.ajustes') }}" class="inline-flex h-[32px] items-center rounded-control border border-btn-line px-3 text-[12.5px] font-semibold text-ink-dim hover:border-brand hover:text-brand" title="Emissor, clientes que atendemos e capa padrão">Ajustes</a>
                @endif
                @if (auth()->user()->canPermissao('orcamentos', 'incluir'))
                    <a href="{{ route('orcamentos.create') }}" class="inline-flex h-[32px] items-center gap-1.5 rounded-control bg-brand px-3.5 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright"><span class="h-4 w-4"><x-nav-icon name="plus" /></span> Novo orçamento</a>
                @endif
            </form>

            @if ($lista->isEmpty())
                <p class="px-4 py-10 text-center text-[13.5px] text-ink-mute">{{ $busca !== '' || array_filter(collect($filtros)->except('periodo')->all()) ? 'Nenhum orçamento neste filtro.' : 'Nenhum orçamento ainda. Comece pelo Novo orçamento.' }}</p>
            @else
                <x-tabela min="1080px" :fixa="true" class="!rounded-none !border-0 !bg-transparent">
                    <colgroup><col style="width: 104px"><col><col style="width: 120px"><col style="width: 170px"><col style="width: 132px"><col style="width: 104px"><col style="width: 130px"><col style="width: 104px"><col style="width: 112px"></colgroup>
                    <thead>
                        <tr class="border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                            <th class="px-4 py-2 text-left font-normal">Número</th><th class="px-3 py-2 text-left font-normal">Cliente / proposta</th><th class="px-3 py-2 text-left font-normal">Projeto</th>
                            <th class="px-3 py-2 text-right font-normal">Valor</th><th class="px-3 py-2 text-left font-normal">Situação</th><th class="px-3 py-2 text-left font-normal">Validade</th>
                            <th class="px-3 py-2 text-left font-normal">Responsável</th><th class="px-3 py-2 text-left font-normal">Atualizado</th><th class="px-4 py-2 text-right font-normal">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($lista as $o)
                            @php
                                $vence = in_array($o->situacao, ['enviado', 'negociacao'], true) ? $o->validoAte() : null;
                                $anteriores = ($revisoes[$o->ano.'-'.$o->numero] ?? collect())->where('id', '!=', $o->id);
                                $data = Painel::data($o);
                            @endphp
                            <tr class="cursor-pointer align-top hover:bg-head/40" onclick="if (! event.target.closest('a,button,form')) location.href = @js(route('orcamentos.edit', $o))">
                                <td class="px-4 py-2.5">
                                    <span class="font-mono text-[13px] font-semibold text-ink">{{ $o->codigo() }}</span>
                                    <p class="text-[11px] text-ink-faint">{{ $data->format('d/m/Y') }}</p>
                                    @if ($anteriores->isNotEmpty())<p class="text-[10.5px] text-ink-faint">@foreach ($anteriores as $r)<a href="{{ route('orcamentos.edit', $r->id) }}" class="hover:text-brand hover:underline">Rev{{ $r->revisao }}</a>@if (! $loop->last) · @endif @endforeach</p>@endif
                                </td>
                                <td class="min-w-0 px-3 py-2.5">
                                    <a href="{{ route('orcamentos.edit', $o) }}" class="block truncate text-[13.5px] font-semibold text-ink hover:text-brand" title="{{ $o->preCliente?->nome ?? $o->cliente }}">{{ $o->preCliente?->nome ?? $o->cliente }}</a>
                                    <p class="truncate text-[12px] text-ink-mute" title="{{ $o->titulo }}">{{ $o->titulo }}</p>
                                </td>
                                <td class="px-3 py-2.5"><span class="inline-block max-w-full truncate rounded-badge border border-line px-1.5 py-0.5 text-[11px] text-ink-dim">{{ $o->preCliente?->sistema?->nome ?? \App\Services\Orcamentos\Modelos::nome($o->produto) }}</span></td>
                                <td class="px-3 py-2.5 text-right tabular-nums">
                                    <span class="text-[13px] font-semibold text-ink">{{ $o->total_unico > 0 ? $brl($o->total_unico) : '—' }}</span>
                                    @if ($o->total_material > 0 && $o->total_implantacao > 0)<p class="text-[10.5px] text-ink-faint" title="Material + implantação">{{ $brl($o->total_material) }} + {{ $brl($o->total_implantacao) }}</p>@endif
                                    @if ($o->total_mensal > 0)<p class="text-[11px] font-medium text-brand-text">+ {{ $brl($o->total_mensal) }}/mês</p>@endif
                                </td>
                                <td class="px-3 py-2.5"><x-badge :tom="$cor[$o->situacao]" ponto>{{ Orcamento::SITUACOES[$o->situacao] }}</x-badge></td>
                                <td class="px-3 py-2.5 text-[12px]">
                                    @if ($vence)
                                        <span class="text-ink">{{ $vence->format('d/m/Y') }}</span>
                                        @php $dias = (int) now()->startOfDay()->diffInDays($vence->copy()->startOfDay(), false); @endphp
                                        <p class="text-[11px] {{ $dias < 0 ? 'text-crit' : ($dias <= 3 ? 'text-warn' : 'text-good') }}">{{ $dias < 0 ? 'venceu' : ($dias === 0 ? 'vence hoje' : $dias.' '.($dias === 1 ? 'dia' : 'dias')) }}</p>
                                    @else <span class="text-ink-faint">—</span> @endif
                                </td>
                                <td class="px-3 py-2.5">
                                    <span class="flex items-center gap-2 text-[12.5px] text-ink-dim">
                                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-head text-[11px] font-semibold text-ink">{{ mb_strtoupper(mb_substr($o->autor?->name ?? '—', 0, 1)) }}</span>
                                        <span class="truncate">{{ \Illuminate\Support\Str::before($o->autor?->name ?? '—', ' ') }}</span>
                                    </span>
                                </td>
                                <td class="px-3 py-2.5 text-[12px] text-ink-dim">{{ $o->updated_at->format('d/m/Y') }}<p class="text-[11px] text-ink-faint">{{ $o->updated_at->diffForHumans() }}</p></td>
                                <td class="px-4 py-2.5">
                                    <div class="flex items-center justify-end gap-1" x-data="{ menu: false }">
                                        <a href="{{ route('orcamentos.edit', $o) }}" class="flex h-7 w-7 items-center justify-center rounded-control border border-btn-line text-ink-dim hover:border-brand hover:text-brand" title="Abrir" aria-label="Abrir {{ $o->codigo() }}"><span class="h-4 w-4"><x-nav-icon name="eye" /></span></a>
                                        <a href="{{ route('orcamentos.pdf', $o) }}" target="_blank" rel="noopener" class="flex h-7 w-7 items-center justify-center rounded-control border border-btn-line text-ink-dim hover:border-brand hover:text-brand" title="PDF" aria-label="PDF de {{ $o->codigo() }}"><span class="h-4 w-4"><x-nav-icon name="document" /></span></a>
                                        <div class="relative">
                                            <button type="button" @click="menu = ! menu" @click.outside="menu = false" class="flex h-7 w-7 items-center justify-center rounded-control border border-btn-line text-[16px] leading-none text-ink-dim hover:border-brand hover:text-brand" aria-label="Mais ações">⋮</button>
                                            <div x-show="menu" x-cloak class="absolute right-0 z-20 mt-1 w-48 overflow-hidden rounded-panel border border-line bg-surface py-1 text-left text-[12.5px] shadow-lg">
                                                @if ($o->situacao !== 'substituido' && auth()->user()->canPermissao('orcamentos', 'editar'))
                                                    <form method="POST" action="{{ route('orcamentos.revisar', $o) }}">@csrf<button class="block w-full px-3 py-1.5 text-left text-ink hover:bg-head">Revisar ({{ str_pad((string) $o->numero, 3, '0', STR_PAD_LEFT) }}.Rev{{ $o->revisao + 1 }})</button></form>
                                                @endif
                                                @if (auth()->user()->canPermissao('orcamentos', 'incluir'))
                                                    <form method="POST" action="{{ route('orcamentos.duplicar', $o) }}">@csrf<button class="block w-full px-3 py-1.5 text-left text-ink hover:bg-head">Duplicar (número novo)</button></form>
                                                @endif
                                                @if ($o->lead_id)<a href="{{ route('leads.index', ['lead' => $o->lead_id]) }}" class="block px-3 py-1.5 text-ink hover:bg-head">Abrir no Funil de Vendas</a>@endif
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-tabela>
            @endif
        </section>
    </div>
</x-app-layout>
