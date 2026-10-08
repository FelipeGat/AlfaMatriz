{{--
    Uma linha de "Minhas tarefas" (#300): onde está e há quanto tempo parada.

    A linha do "Próximos 7 dias" do Centro de Controle — lista de relance, não
    de ação: o que pede ação já está na lista de cima. O tempo parado pega a
    cor da régua de envelhecimento do card, para "3d" significar o mesmo aqui
    e no quadro.

    Espera: $linha (ver `App\Services\OQueEsperaVoce::minhas`).
--}}
@php
    $tomDoTempo = match ($linha['nivel']) {
        'critico' => 'crit',
        'atencao' => 'warn',
        default => 'ink-faint',
    };
@endphp

<a href="{{ $linha['rota'] }}" data-minha="{{ $linha['tarefa']->id }}"
   class="flex items-center gap-3 px-4 py-3 border-b border-rule last:border-0 hover:bg-chip transition">
    <span class="w-9 shrink-0 font-mono text-[11px] text-ink-faint">{{ $linha['tarefa']->codigo() }}</span>
    <span class="min-w-0 flex-1">
        <span class="block truncate text-[13px] text-ink-dim" title="{{ $linha['tarefa']->titulo }}">{{ $linha['tarefa']->titulo }}</span>
        <span class="block truncate font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
            <span style="color: rgb(var(--{{ $linha['cor'] === 'brand' ? 'brand-text' : $linha['cor'] }}))">{{ $linha['etapa'] }}</span>
            @if ($linha['papel']) · {{ $linha['papel'] }}@endif
        </span>
    </span>
    @if ($linha['parada'])
        <span class="shrink-0 font-sans tabular text-[12.5px] whitespace-nowrap"
              style="color: rgb(var(--{{ $tomDoTempo }}))"
              title="Parada nesta etapa há {{ $linha['parada'] }}">{{ $linha['parada'] }}</span>
    @endif
</a>
