{{-- "Lá fora" (docs/specs/secretario-central-do-dia): do cache, nunca de uma
     busca na hora. Cada bloco diz a hora do dado; sem dado de até 6 h, diz
     "indisponível" em vez de mostrar número velho como atual. Os cartões
     resumem; a aba abre as manchetes daquele bloco. --}}
@php
    $mais = collect($laFora)->filter()->map(fn ($b) => $b['em'])->max();
    $cartao = 'flex min-w-0 flex-col rounded-ctl border px-3.5 py-3 text-left transition';
@endphp
<section x-data="{ aba: null }" class="rounded-panel border border-line bg-surface">
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-line px-4 py-3">
        <span class="h-6 w-6 text-brand-text"><x-nav-icon name="globe" peso="1.7" /></span>
        <h2 class="font-display text-[19px] font-semibold text-ink">Lá fora</h2>
        <nav class="flex flex-wrap gap-1">
            @foreach (['dolar' => 'Dólar', 'clima' => 'Vitória', 'flamengo' => 'Flamengo', 'brasil' => 'Brasil', 'mundo' => 'Mundo'] as $k => $rotulo)
                <button type="button" @click="aba = aba === '{{ $k }}' ? null : '{{ $k }}'" class="border-b-2 px-2 pb-1 font-mono text-[12px]"
                        :class="aba === '{{ $k }}' ? 'border-brand text-brand-text' : 'border-transparent text-ink-mute hover:text-ink'">{{ $rotulo }}</button>
            @endforeach
        </nav>
        <span class="ml-auto text-[12px] text-ink-faint">{{ $mais ? 'Atualizado às '.$mais->format('H:i') : 'Sem atualização recente' }}</span>
    </div>
    <div class="grid grid-cols-2 gap-2.5 p-3 md:grid-cols-3 xl:grid-cols-5">
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
        @foreach (['flamengo' => ['🔴⚫', 'Flamengo · ge'], 'brasil' => ['🇧🇷', 'Brasil · g1'], 'mundo' => ['🌐', 'Mundo · g1']] as $k => [$emoji, $rotulo])
            <button type="button" @click="aba = '{{ $k }}'" class="{{ $cartao }}" :class="aba === '{{ $k }}' ? 'border-brand' : 'border-line hover:border-brand'">
                <span class="text-[20px] leading-none">{{ $emoji }}</span>
                <span class="mt-2 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">{{ $rotulo }}</span>
                @if ($laFora[$k])
                    <span class="mt-1 line-clamp-2 text-[13.5px] font-semibold leading-snug text-ink">{{ $laFora[$k]['manchetes'][0]['titulo'] }}</span>
                    <span class="mt-auto pt-1 text-[11.5px] text-ink-mute">{{ count($laFora[$k]['manchetes']) }} manchete(s) · ver</span>
                @else
                    <span class="mt-1 text-[13px] text-ink-faint">indisponível agora</span>
                @endif
            </button>
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
        @foreach (['flamengo', 'brasil', 'mundo'] as $k)
            <div x-show="aba === '{{ $k }}'">
                @if ($laFora[$k])
                    <ul class="space-y-1.5">
                        @foreach ($laFora[$k]['manchetes'] as $m)
                            <li class="text-[13.5px] leading-snug text-ink">•
                                @if ($m['link'])
                                    <a href="{{ $m['link'] }}" target="_blank" rel="noopener noreferrer" class="hover:text-brand hover:underline">{{ $m['titulo'] }}</a>
                                @else
                                    {{ $m['titulo'] }}
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-1.5 text-[11.5px] text-ink-faint">{{ $k === 'flamengo' ? 'ge' : 'g1' }}, às {{ $laFora[$k]['em']->format('H:i') }}</p>
                @else
                    <p class="text-[13px] text-ink-faint">indisponível agora</p>
                @endif
            </div>
        @endforeach
    </div>
</section>
