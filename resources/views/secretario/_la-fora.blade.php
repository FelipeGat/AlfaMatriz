{{-- "Lá fora" (docs/specs/secretario-central-do-dia): do cache, nunca de uma
     busca na hora. Cada bloco diz a hora do dado; sem dado de até 6 h, diz
     "indisponível" em vez de mostrar número velho como atual. Dólar e clima
     abrem o detalhe; os cartões de notícia já trazem as manchetes clicáveis
     (Felipe, 18/10/2026: futebol brasileiro e tecnologia daqui e de fora). --}}
@php
    $mais = collect($laFora)->filter()->map(fn ($b) => $b['em'])->max();
    $cartao = 'flex min-w-0 flex-col rounded-ctl border px-3.5 py-3 text-left transition';
    $noticias = ['futebol' => ['⚽', 'Futebol brasileiro', 'ge'], 'tec_brasil' => ['💻', 'Tecnologia · Brasil', 'g1'], 'tec_mundo' => ['🌐', 'Tecnologia · Mundo', 'TechCrunch']];
@endphp
<section x-data="{ aba: null }" class="rounded-panel border border-line bg-surface">
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-line px-4 py-3">
        <span class="h-6 w-6 text-brand-text"><x-nav-icon name="globe" peso="1.7" /></span>
        <h2 class="font-display text-[15px] font-semibold text-ink">Lá fora</h2>
        <nav class="flex flex-wrap gap-1">
            @foreach (['dolar' => 'Dólar', 'clima' => 'Vitória'] as $k => $rotulo)
                <button type="button" @click="aba = aba === '{{ $k }}' ? null : '{{ $k }}'" class="border-b-2 px-2 pb-1 font-mono text-[12px]"
                        :class="aba === '{{ $k }}' ? 'border-brand text-brand-text' : 'border-transparent text-ink-mute hover:text-ink'">{{ $rotulo }}</button>
            @endforeach
        </nav>
        <span class="ml-auto text-[12px] text-ink-faint">{{ $mais ? 'Atualizado às '.$mais->format('H:i') : 'Sem atualização recente' }}</span>
    </div>
    <div class="grid grid-cols-2 gap-2.5 p-3 lg:grid-cols-[150px_150px_repeat(3,minmax(0,1fr))]">
        <button type="button" @click="aba = 'dolar'" class="{{ $cartao }}" :class="aba === 'dolar' ? 'border-brand' : 'border-line hover:border-brand'">
            <span class="text-[20px] leading-none">🇺🇸</span>
            <span class="mt-2 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Dólar (USD)</span>
            @if ($d = $laFora['dolar'])
                <span class="mt-1 font-display text-[22px] font-semibold text-ink">{{ number_format($d['compra'], 2, ',', '.') }}</span>
                <span class="text-right text-[12px] font-semibold {{ $d['variacao'] >= 0 ? 'text-crit' : 'text-good' }}">{{ $d['variacao'] >= 0 ? '+' : '−' }}{{ number_format(abs($d['variacao']), 2, ',', '.') }}% {{ $d['variacao'] >= 0 ? '↗' : '↘' }}</span>
            @else
                <span class="mt-1 text-[13px] text-ink-faint">indisponível agora</span>
            @endif
        </button>
        <button type="button" @click="aba = 'clima'" class="{{ $cartao }}" :class="aba === 'clima' ? 'border-brand' : 'border-line hover:border-brand'">
            <span class="text-[20px] leading-none">🇧🇷</span>
            <span class="mt-2 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Vitória-ES</span>
            @if ($c = $laFora['clima'])
                <span class="mt-1 flex items-center justify-between font-display text-[22px] font-semibold text-ink">{{ $c['agora'] }}°C
                    <span class="text-[22px]">{{ str_contains($c['tempo'], 'chuva') || str_contains($c['tempo'], 'garoa') || str_contains($c['tempo'], 'pancada') ? '🌧' : (str_contains($c['tempo'], 'nublado') ? '⛅' : (str_contains($c['tempo'], 'trovoada') ? '⛈' : '☀️')) }}</span></span>
                <span class="text-[12px] text-ink-mute">{{ ucfirst($c['tempo']) }}</span>
            @else
                <span class="mt-1 text-[13px] text-ink-faint">indisponível agora</span>
            @endif
        </button>
        @foreach ($noticias as $k => [$emoji, $rotulo, $fonte])
            <div x-data="{ mais: false }" class="col-span-2 {{ $cartao }} border-line lg:col-span-1">
                <div class="flex items-center gap-2">
                    <span class="text-[16px] leading-none">{{ $emoji }}</span>
                    <span class="min-w-0 truncate font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">{{ $rotulo }}</span>
                    @if ($laFora[$k])<span class="ml-auto shrink-0 whitespace-nowrap text-[11px] text-ink-faint" title="{{ $fonte }}, às {{ $laFora[$k]['em']->format('H:i') }}">{{ $fonte }}</span>@endif
                </div>
                @if ($laFora[$k])
                    <ul class="mt-2 space-y-1.5">
                        @foreach ($laFora[$k]['manchetes'] as $i => $m)
                            <li @if ($i >= 3) x-show="mais" x-cloak @endif class="text-[13px] leading-snug text-ink">
                                @if ($m['link'])
                                    <a href="{{ $m['link'] }}" target="_blank" rel="noopener noreferrer" class="line-clamp-2 hover:text-brand hover:underline" title="{{ $m['titulo'] }}">{{ $m['titulo'] }}</a>
                                @else
                                    <span class="line-clamp-2">{{ $m['titulo'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if (count($laFora[$k]['manchetes']) > 3)
                        <button type="button" @click="mais = ! mais" class="mt-auto self-start pt-1.5 text-[11.5px] text-ink-mute hover:text-brand" x-text="mais ? 'menos' : 'mais {{ count($laFora[$k]['manchetes']) - 3 }}'"></button>
                    @endif
                @else
                    <span class="mt-2 text-[13px] text-ink-faint">indisponível agora</span>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Detalhe da aba --}}
    <div x-show="aba" x-cloak class="border-t border-line px-4 py-3">
        <div x-show="aba === 'dolar'">
            @if ($d = $laFora['dolar'])
                <p class="text-[13.5px] text-ink">Compra R$ {{ number_format($d['compra'], 4, ',', '.') }} · mín. {{ number_format($d['minima'], 4, ',', '.') }} · máx. {{ number_format($d['maxima'], 4, ',', '.') }} <span class="text-ink-faint">· AwesomeAPI, às {{ $d['em']->format('H:i') }}</span></p>
            @endif
        </div>
        <div x-show="aba === 'clima'">
            @if ($c = $laFora['clima'])
                <p class="text-[13.5px] text-ink">Agora {{ $c['agora'] }}°, {{ $c['tempo'] }} · mín. {{ $c['minima'] }}° · máx. {{ $c['maxima'] }}° · chuva {{ $c['chuva'] }}% <span class="text-ink-faint">· Open-Meteo, às {{ $c['em']->format('H:i') }}</span></p>
            @endif
        </div>
    </div>
</section>
