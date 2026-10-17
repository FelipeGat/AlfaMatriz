{{--
    A célula "O quê" da aba Monitor (#394), igual em Fora agora e no
    Histórico: o nome legível, o tipo e a chave do monitor, que é o que se
    procura na web dele. Espera: $alerta.
--}}
<td class="px-4 py-3 max-w-[300px]">
    @if ($url = $alerta->url())
        <a href="{{ $url }}" target="_blank" rel="noopener"
           class="block text-[13px] font-medium text-ink hover:text-brand truncate" title="Abrir no AlfaMonitor">{{ $alerta->nome() }}</a>
    @else
        <p class="text-[13px] font-medium text-ink truncate" title="{{ $alerta->nome() }}">{{ $alerta->nome() }}</p>
    @endif
    <div class="mt-1 flex flex-wrap items-center gap-1.5 min-w-0">
        <x-badge>{{ \App\Models\MonitorAlerta::rotuloDoKind($alerta->kind) }}</x-badge>
        <span class="font-mono text-[11px] text-ink-faint truncate" title="{{ $alerta->entity }}">{{ $alerta->entity }}</span>
    </div>
</td>
