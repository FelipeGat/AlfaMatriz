{{--
    Monitor › Clientes › <cliente> (#394): acessos, disponibilidade dos
    aparelhos, as quedas da semana e o que está ligado ao cliente.
    Espera: $resposta (clientes/{id}).
--}}
@use('App\Services\Monitor\FormatoDoMonitor', 'F')
@php
    $c = $resposta->dados ?? [];
    $acessos = $c['acessos'] ?? [];
    $aparelhos = collect($c['aparelhos'] ?? []);
    $quedas = collect($c['quedas_lista_7d'] ?? [])->sortByDesc('inicio')->values();
    $servidores = collect($c['servidores'] ?? []);
    $monitores = collect($c['monitores'] ?? []);

@endphp

<x-monitor.tela tela="cliente" :resposta="$resposta"
                :migalhas="[['rotulo' => 'Monitor', 'rota' => route('monitor.geral')], ['rotulo' => 'Clientes', 'rota' => route('monitor.clientes')]]"
                :atual="$c['nome'] ?? 'Cliente'"
                :contexto="$resposta->temDados() ? ($c['dispositivos_online'] ?? 0).' de '.($c['dispositivos_total'] ?? 0).' aparelhos online' : null">
    <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(min(190px, 100%), 1fr))">
        @foreach (['hoje' => 'Hoje', 'd7' => '7 dias', 'd30' => '30 dias'] as $chave => $rotulo)
            <x-kpi-card :rotulo="'Liberados · '.$rotulo" :valor="F::inteiro($acessos[$chave]['liberados'] ?? null)"
                        :delta="F::inteiro($acessos[$chave]['negados'] ?? null).' negados'" acento="good" icone="check-circle" />
        @endforeach
        <x-kpi-card rotulo="No ar · 24h" :valor="F::noAr($c['pct_no_ar_24h'] ?? null)"
                    :delta="($c['quedas_24h'] ?? 0).' '.(($c['quedas_24h'] ?? 0) === 1 ? 'queda' : 'quedas')" acento="accent" icone="bolt" />
        <x-kpi-card rotulo="No ar · 7 dias" :valor="F::noAr($c['pct_no_ar_7d'] ?? null)"
                    :delta="($c['quedas_7d'] ?? 0).' quedas'.(($c['quedas_descontadas_7d'] ?? 0) ? ' · '.$c['quedas_descontadas_7d'].' descontadas' : '')"
                    acento="accent" icone="trending-up" />
    </div>

    <x-painel titulo="Acessos por dia" sub="liberados e negados, últimos 30 dias">
        <x-monitor.barras-dia :dias="$c['serie_30d'] ?? []" :ate="$resposta->geradoEm" />
    </x-painel>

    <x-tabela min="760px" class="tabela-zebrada" titulo="Aparelhos"
              :sub="$aparelhos->count().' '.($aparelhos->count() === 1 ? 'aparelho' : 'aparelhos')">
        <thead>
            <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                <th class="px-4 py-2.5 font-semibold">Aparelho</th>
                <th class="px-4 py-2.5 font-semibold">Agora</th>
                <th class="px-4 py-2.5 font-semibold text-right">No ar 24h</th>
                <th class="px-4 py-2.5 font-semibold text-right">No ar 7 dias</th>
                <th class="px-4 py-2.5 font-semibold text-right">Quedas 7 dias</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($aparelhos as $aparelho)
                <tr class="border-b border-rule hover:bg-chip transition">
                    <td class="px-4 py-3">
                        <p class="text-[13px] font-medium text-ink">{{ $aparelho['nome'] ?? '—' }}</p>
                        <p class="text-[11px] text-ink-faint">{{ $aparelho['fonte'] ?? '' }}</p>
                    </td>
                    <td class="px-4 py-3">
                        <x-badge :tom="F::tom($aparelho['estado'] ?? null)" ponto>{{ ($aparelho['estado'] ?? '') === 'online' ? 'Online' : 'Offline' }}</x-badge>
                    </td>
                    <td class="px-4 py-3 text-right font-sans tabular text-[12.5px] text-ink-dim">{{ F::noAr($aparelho['pct_no_ar_24h'] ?? null) }}</td>
                    <td class="px-4 py-3 text-right font-sans tabular text-[12.5px] text-ink-dim">{{ F::noAr($aparelho['pct_no_ar_7d'] ?? null) }}</td>
                    <td class="px-4 py-3 text-right font-sans tabular text-[12.5px] text-ink-dim">{{ $aparelho['quedas_7d'] ?? 0 }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-6 text-center text-[13px] text-ink-mute">Nenhum aparelho de acesso.</td>
                </tr>
            @endforelse
        </tbody>
    </x-tabela>

    <div class="grid gap-3 lg:grid-cols-2">
        <x-tabela min="480px" class="tabela-zebrada" titulo="Quedas da semana"
                  :sub="$quedas->count().' '.($quedas->count() === 1 ? 'queda' : 'quedas')">
            <thead>
                <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                    <th class="px-4 py-2.5 font-semibold">Aparelho</th>
                    <th class="px-4 py-2.5 font-semibold">Caiu</th>
                    <th class="px-4 py-2.5 font-semibold text-right">Fora por</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($quedas as $queda)
                    @php $inicio = F::hora($queda['inicio'] ?? null); @endphp
                    <tr class="border-b border-rule">
                        <td class="px-4 py-2.5 text-[12.5px] text-ink">{{ $queda['aparelho'] ?? '—' }}</td>
                        <td class="px-4 py-2.5 font-sans tabular text-[12.5px] text-ink-dim whitespace-nowrap">{{ $inicio?->format('d/m H:i') ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-sans tabular text-[12.5px] text-ink-dim whitespace-nowrap">
                            {{ ($queda['fim'] ?? null) ? F::duracao($queda['duracao_s'] ?? null) : 'ainda fora' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-6 text-center text-[13px] text-ink-mute">Nenhuma queda nos últimos 7 dias.</td>
                    </tr>
                @endforelse
            </tbody>
        </x-tabela>

        <x-painel titulo="Infraestrutura e horário">
            <dl class="space-y-3 text-[13px]">
                <div>
                    <dt class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Horário de funcionamento</dt>
                    <dd class="mt-0.5 text-ink-dim">{{ $c['horario'] ?? 'Não cadastrado no monitor' }}</dd>
                </div>
                <div>
                    <dt class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Servidores ligados</dt>
                    <dd class="mt-1 flex flex-wrap items-center gap-2 text-ink-dim">
                        {{-- {nome, slug, online, sev}: o slug abre a tela do servidor. --}}
                        @forelse ($servidores as $servidor)
                            <a href="{{ route('monitor.servidor', $servidor['slug'] ?? '-') }}" class="inline-flex items-center gap-1.5 hover:text-brand-text transition">
                                <x-badge :tom="($servidor['online'] ?? false) ? F::tom($servidor['sev'] ?? null) : 'critico'" ponto>{{ $servidor['nome'] ?? '—' }}</x-badge>
                            </a>
                        @empty
                            Nenhum
                        @endforelse
                    </dd>
                </div>
                <div>
                    <dt class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Monitores ligados</dt>
                    <dd class="mt-1 flex flex-wrap items-center gap-2 text-ink-dim">
                        {{-- Mesmo formato dos monitores da Geral (nome, estado, barra…). --}}
                        @forelse ($monitores as $monitor)
                            <x-badge :tom="F::tom($monitor['estado'] ?? null)" ponto>{{ $monitor['nome'] ?? '—' }}</x-badge>
                        @empty
                            Nenhum
                        @endforelse
                    </dd>
                </div>
            </dl>
        </x-painel>
    </div>
</x-monitor.tela>
