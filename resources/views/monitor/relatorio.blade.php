{{--
    Monitor › Relatórios (#394): o relatório do AlfaMonitor em 24h, 7 ou 30
    dias — resumo, servidores, disponibilidade e SLA, aparelhos, incidentes e
    recomendações. Não tem nada da Hostinger (o monitor conferiu).

    Exportar repassa o CSV que o monitor já monta. PDF não existe lá: o "PDF"
    da tela dele é a impressão do navegador, e aqui também.

    Espera: $resposta (relatorio?janela=), $janela.
--}}
@use('App\Services\Monitor\FormatoDoMonitor', 'F')
@use('App\Http\Controllers\PainelDoMonitorController', 'Painel')
@php
    $r = $resposta->dados ?? [];
    $resumo = $r['resumo'] ?? [];
    $servidores = collect($r['servidores'] ?? []);
    $disponibilidade = collect($r['disponibilidade'] ?? []);
    $dispositivos = collect($r['dispositivos'] ?? []);
    $incidentes = collect($r['incidentes'] ?? [])->sortByDesc('inicio')->values();
    $recomendacoes = collect($r['recomendacoes'] ?? []);

    $tomDaSaude = fn ($s) => ! is_numeric($s) ? 'neutro' : ($s >= 90 ? 'bom' : ($s >= 70 ? 'atencao' : 'critico'));
    $estatistica = fn (?array $m) => $m
        ? F::pct($m['media_pct'] ?? null).' · pico '.F::pct($m['pico_pct'] ?? null).(($m['pico_em'] ?? null) ? ' em '.$m['pico_em'] : '')
        : '—';
    $rotuloDaConfianca = fn ($c) => match ($c) { 'High' => 'confiança alta', 'Medium' => 'confiança média', 'Low' => 'confiança baixa', default => $c };
@endphp

<x-monitor.tela tela="relatorio" :resposta="$resposta" :automatico="false"
                :contexto="$resposta->temDados() ? 'últimos '.Painel::JANELAS[$janela] : null">
    <div class="flex flex-wrap items-center gap-2 print:hidden">
        <x-abas>
            @foreach (Painel::JANELAS as $chave => $rotulo)
                <x-abas.item :href="route('monitor.relatorio', ['janela' => $chave])" :ativo="$janela === $chave">{{ $rotulo }}</x-abas.item>
            @endforeach
        </x-abas>

        <div class="ml-auto flex items-center gap-2">
            @if (auth()->user()->canPermissao('monitor', 'imprimir'))
                <a href="{{ route('monitor.relatorio.exportar', ['janela' => $janela]) }}"
                   class="h-[34px] px-3 inline-flex items-center gap-1.5 rounded-control border border-btn-line text-ink-dim
                          text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
                    <span class="h-[14px] w-[14px]"><x-nav-icon name="download" /></span> Exportar CSV
                </a>
            @endif
            <button type="button" onclick="window.print()"
                    class="h-[34px] px-3 inline-flex items-center gap-1.5 rounded-control border border-btn-line text-ink-dim
                           text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
                <span class="h-[14px] w-[14px]"><x-nav-icon name="printer" /></span> Imprimir
            </button>
        </div>
    </div>

    <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(190px, 1fr))">
        <x-kpi-card rotulo="Saúde geral" :valor="is_numeric($resumo['saude_geral'] ?? null) ? $resumo['saude_geral'].' / 100' : '—'"
                    :acento="match ($tomDaSaude($resumo['saude_geral'] ?? null)) { 'bom' => 'good', 'atencao' => 'warn', 'critico' => 'crit', default => 'accent' }" icone="check-circle" />
        <x-kpi-card rotulo="Disponibilidade média" :valor="F::noAr($resumo['disponibilidade_media_pct'] ?? null)"
                    :delta="'fora do ar '.F::duracao($resumo['fora_do_ar_s'] ?? null)" acento="accent" icone="bolt" />
        <x-kpi-card rotulo="Incidentes" :valor="F::inteiro($resumo['incidentes'] ?? null)"
                    :delta="($resumo['monitores_total'] ?? 0).' sistemas monitorados'" acento="amber" icone="alert-triangle" />
        <x-kpi-card rotulo="Resposta média" :valor="F::ms($resumo['resposta_media_ms'] ?? null)" acento="accent" icone="clock" />
        <x-kpi-card rotulo="Servidores" :valor="($resumo['servidores_saudaveis'] ?? 0).' / '.($resumo['servidores_total'] ?? 0).' saudáveis'"
                    :delta="($resumo['servidores_atencao'] ?? 0).' em atenção · '.($resumo['servidores_criticos'] ?? 0).' críticos'" acento="good" icone="cube" />
    </div>

    <x-tabela min="1000px" class="tabela-zebrada" titulo="Servidores">
        <thead>
            <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                <th class="px-4 py-2.5 font-semibold">Servidor</th>
                <th class="px-4 py-2.5 font-semibold">Saúde</th>
                <th class="px-4 py-2.5 font-semibold">CPU (média · pico)</th>
                <th class="px-4 py-2.5 font-semibold">Memória</th>
                <th class="px-4 py-2.5 font-semibold">Disco</th>
                <th class="px-4 py-2.5 font-semibold">Steal</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($servidores as $s)
                <tr class="border-b border-rule hover:bg-chip transition align-top">
                    <td class="px-4 py-3">
                        @if ($s['slug'] ?? null)
                            <a href="{{ route('monitor.servidor', $s['slug']) }}" class="text-[13px] font-medium text-ink hover:text-brand-text transition">{{ $s['nome'] ?? '—' }}</a>
                        @else
                            <span class="text-[13px] font-medium text-ink">{{ $s['nome'] ?? '—' }}</span>
                        @endif
                        <p class="text-[11px] text-ink-faint">{{ F::inteiro($s['amostras'] ?? null) }} amostras{{ ($s['online'] ?? true) ? '' : ' · offline agora' }}</p>
                    </td>
                    <td class="px-4 py-3"><x-badge :tom="$tomDaSaude($s['saude'] ?? null)">{{ $s['saude'] ?? '—' }}</x-badge></td>
                    <td class="px-4 py-3 font-sans tabular text-[12.5px] text-ink-dim">{{ $estatistica($s['cpu'] ?? null) }}</td>
                    <td class="px-4 py-3 font-sans tabular text-[12.5px] text-ink-dim">{{ $estatistica($s['mem'] ?? null) }}</td>
                    <td class="px-4 py-3 font-sans tabular text-[12.5px] text-ink-dim">{{ $estatistica($s['disco'] ?? null) }}</td>
                    <td class="px-4 py-3 font-sans tabular text-[12.5px] text-ink-dim">{{ $estatistica($s['steal'] ?? null) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-6 text-center text-[13px] text-ink-mute">Nenhum servidor no período.</td></tr>
            @endforelse
        </tbody>
    </x-tabela>

    <x-tabela min="1000px" class="tabela-zebrada" titulo="Disponibilidade e SLA">
        <thead>
            <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                <th class="px-4 py-2.5 font-semibold">Sistema</th>
                <th class="px-4 py-2.5 font-semibold text-right">No ar</th>
                <th class="px-4 py-2.5 font-semibold text-right">Meta (SLO)</th>
                <th class="px-4 py-2.5 font-semibold text-right">Margem de erro usada</th>
                <th class="px-4 py-2.5 font-semibold text-right">Quedas</th>
                <th class="px-4 py-2.5 font-semibold text-right">Fora do ar</th>
                <th class="px-4 py-2.5 font-semibold text-right">Recuperação média</th>
                <th class="px-4 py-2.5 font-semibold text-right">Resposta média</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($disponibilidade as $d)
                @php
                    $usado = $d['orcamento_usado_pct'] ?? null;
                    $tomUsado = ! is_numeric($usado) ? 'neutro' : ($usado >= 100 ? 'critico' : ($usado >= 75 ? 'atencao' : 'bom'));
                @endphp
                <tr class="border-b border-rule hover:bg-chip transition">
                    <td class="px-4 py-3">
                        <p class="text-[13px] font-medium text-ink">{{ $d['nome'] ?? '—' }}</p>
                        <p class="max-w-[240px] truncate font-mono text-[11px] text-ink-faint" title="{{ $d['alvo'] ?? '' }}">{{ $d['alvo'] ?? '' }}</p>
                    </td>
                    <td class="px-4 py-3 text-right font-sans tabular text-[12.5px] text-ink">{{ F::noAr($d['pct_no_ar'] ?? null) }}</td>
                    <td class="px-4 py-3 text-right font-sans tabular text-[12.5px] text-ink-dim">{{ F::pct($d['slo_pct'] ?? null) }}</td>
                    <td class="px-4 py-3 text-right"><x-badge :tom="$tomUsado">{{ F::pct($usado) }}</x-badge></td>
                    <td class="px-4 py-3 text-right font-sans tabular text-[12.5px] text-ink-dim">{{ $d['quedas'] ?? 0 }}</td>
                    <td class="px-4 py-3 text-right font-sans tabular text-[12.5px] text-ink-dim">{{ F::duracao($d['fora_do_ar_s'] ?? null) }}</td>
                    <td class="px-4 py-3 text-right font-sans tabular text-[12.5px] text-ink-dim">{{ ($d['quedas'] ?? 0) ? F::duracao($d['mttr_s'] ?? null) : '—' }}</td>
                    <td class="px-4 py-3 text-right font-sans tabular text-[12.5px] text-ink-dim">{{ F::ms($d['media_ms'] ?? null) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-6 text-center text-[13px] text-ink-mute">Nenhum sistema no período.</td></tr>
            @endforelse
        </tbody>
    </x-tabela>

    <div class="grid gap-3 lg:grid-cols-2">
        <x-painel titulo="Aparelhos de acesso" solto>
            <div class="divide-y divide-rule">
                @forelse ($dispositivos as $fonte)
                    @php $offline = collect($fonte['offline_agora'] ?? []); @endphp
                    <div class="px-4 py-3">
                        <p class="text-[13px] font-semibold text-ink">
                            {{ $fonte['fonte'] ?? '' }}
                            <span class="ml-1 font-sans tabular text-[12.5px] font-normal text-ink-dim">{{ $fonte['online'] ?? 0 }} de {{ $fonte['total'] ?? 0 }} online</span>
                        </p>
                        @if ($offline->isNotEmpty())
                            <p class="mt-1 text-[12px] text-ink-mute">
                                Offline agora: {{ $offline->map(fn ($o) => ($o['aparelho'] ?? '?').' ('.($o['cliente'] ?? '?').')')->implode(', ') }}
                            </p>
                        @endif
                    </div>
                @empty
                    <p class="px-4 py-6 text-center text-[13px] text-ink-mute">Sem aparelhos no período.</p>
                @endforelse
            </div>
        </x-painel>

        <x-tabela min="460px" class="tabela-zebrada" titulo="Incidentes"
                  :sub="$incidentes->count().' no período'">
            <thead>
                <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                    <th class="px-4 py-2.5 font-semibold">Sistema</th>
                    <th class="px-4 py-2.5 font-semibold">Caiu</th>
                    <th class="px-4 py-2.5 font-semibold text-right">Fora por</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($incidentes as $inc)
                    <tr class="border-b border-rule">
                        <td class="px-4 py-2.5 text-[12.5px] text-ink">{{ $inc['monitor'] ?? '—' }}{{ ($inc['http'] ?? null) ? ' · HTTP '.$inc['http'] : '' }}</td>
                        <td class="px-4 py-2.5 font-sans tabular text-[12.5px] text-ink-dim whitespace-nowrap">{{ F::hora($inc['inicio'] ?? null)?->format('d/m H:i') ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-sans tabular text-[12.5px] text-ink-dim whitespace-nowrap">{{ ($inc['fim'] ?? null) ? F::duracao($inc['duracao_s'] ?? null) : 'ainda fora' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-6 text-center text-[13px] text-ink-mute">Nenhum incidente no período.</td></tr>
                @endforelse
            </tbody>
        </x-tabela>
    </div>

    {{-- Por último, como no relatório do monitor: o achado se lê depois dos
         números que o sustentam. --}}
    @if ($recomendacoes->isNotEmpty())
        <x-painel titulo="Recomendações" :sub="$recomendacoes->count().' '.($recomendacoes->count() === 1 ? 'achado' : 'achados')" solto>
            <ul class="divide-y divide-rule">
                @foreach ($recomendacoes as $rec)
                    <li class="px-4 py-3">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <x-badge :tom="F::tom($rec['severidade'] ?? null)">{{ F::rotuloDaGravidade($rec['severidade'] ?? null) }}</x-badge>
                            <x-badge>{{ $rec['categoria'] ?? '' }}</x-badge>
                            <span class="text-[13px] font-semibold text-ink">{{ $rec['titulo'] ?? '' }}</span>
                            <span class="text-[12px] text-ink-faint">· {{ $rec['escopo'] ?? '' }} · {{ $rotuloDaConfianca($rec['confianca'] ?? null) }}</span>
                        </div>
                        @if ($rec['detalhe'] ?? null)
                            <p class="mt-1 text-[12.5px] text-ink-dim">{{ $rec['detalhe'] }}</p>
                        @endif
                        @foreach ($rec['evidencias'] ?? [] as $evidencia)
                            <p class="mt-0.5 text-[12px] text-ink-mute">— {{ $evidencia }}</p>
                        @endforeach
                        @if ($rec['acao'] ?? null)
                            <p class="mt-1 text-[12.5px] text-ink"><span class="font-semibold">O que fazer:</span> {{ $rec['acao'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-painel>
    @endif
</x-monitor.tela>
