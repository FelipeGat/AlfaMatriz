{{-- "Lá fora" (docs/specs/secretario-central-do-dia): do cache, nunca de uma
     busca na hora. Cada bloco diz a hora do dado; sem dado de até 6 h, diz
     "indisponível" em vez de mostrar número velho como atual. --}}
@php
    $hora = fn ($b) => $b ? 'às '.$b['em']->format('H:i') : '';
    $sem = '<span class="text-ink-faint">indisponível agora</span>';
@endphp
<section class="rounded-panel border border-line bg-subtle">
    <div class="flex h-[38px] items-center gap-2 border-b border-line bg-head px-4">
        <h2 class="font-display text-[15px] font-semibold text-ink">Lá fora</h2>
        <span class="font-mono text-[11px] text-ink-faint">dólar · Vitória · Flamengo · Brasil · Mundo</span>
    </div>
    <div class="grid grid-cols-1 gap-px border-b border-line bg-line sm:grid-cols-2">
        <div class="bg-subtle px-4 py-3">
            <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Dólar {{ $hora($laFora['dolar']) }}</p>
            @if ($d = $laFora['dolar'])
                <p class="mt-1 text-[18px] font-semibold text-ink">R$ {{ number_format($d['compra'], 2, ',', '.') }}
                    <span class="text-[12.5px] font-semibold {{ $d['variacao'] >= 0 ? 'text-crit' : 'text-good' }}">{{ $d['variacao'] >= 0 ? '▲' : '▼' }} {{ number_format(abs($d['variacao']), 2, ',', '.') }}%</span></p>
                <p class="text-[12px] text-ink-mute">mín. {{ number_format($d['minima'], 2, ',', '.') }} · máx. {{ number_format($d['maxima'], 2, ',', '.') }}</p>
            @else
                <p class="mt-1 text-[13px]">{!! $sem !!}</p>
            @endif
        </div>
        <div class="bg-subtle px-4 py-3">
            <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Vitória-ES {{ $hora($laFora['clima']) }}</p>
            @if ($c = $laFora['clima'])
                <p class="mt-1 text-[18px] font-semibold text-ink">{{ $c['agora'] }}° <span class="text-[13px] font-normal text-ink-mute">{{ $c['tempo'] }}</span></p>
                <p class="text-[12px] text-ink-mute">mín. {{ $c['minima'] }}° · máx. {{ $c['maxima'] }}° · chuva {{ $c['chuva'] }}%</p>
            @else
                <p class="mt-1 text-[13px]">{!! $sem !!}</p>
            @endif
        </div>
    </div>
    <div class="grid grid-cols-1 gap-px bg-line md:grid-cols-3">
        @foreach (['flamengo' => 'Flamengo · ge', 'brasil' => 'Brasil · g1', 'mundo' => 'Mundo · g1'] as $chave => $rotulo)
            <div class="bg-subtle px-4 py-3">
                <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">{{ $rotulo }} {{ $hora($laFora[$chave]) }}</p>
                @if ($laFora[$chave])
                    <ul class="mt-1.5 space-y-1.5">
                        @foreach ($laFora[$chave]['manchetes'] as $m)
                            <li class="text-[13px] leading-snug text-ink">
                                @if ($m['link'])
                                    <a href="{{ $m['link'] }}" target="_blank" rel="noopener noreferrer" class="hover:text-brand hover:underline">{{ $m['titulo'] }}</a>
                                @else
                                    {{ $m['titulo'] }}
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-1 text-[13px]">{!! $sem !!}</p>
                @endif
            </div>
        @endforeach
    </div>
</section>
