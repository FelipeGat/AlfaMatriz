{{-- As telas do Suporte WhatsApp: as conversas, o que o Pedro sabe e a base que os atendimentos alimentam (T-476). --}}
@php $aConfirmar = \App\Models\SuporteNota::propostas()->count(); @endphp
<x-abas>
    <x-abas.item :href="route('suporte.conversas')" :ativo="$aba === 'conversas'" icone="chat">Conversas</x-abas.item>
    <x-abas.item :href="route('suporte.pedro')" :ativo="$aba === 'pedro'" icone="document">Pedro: conhecimento</x-abas.item>
    <x-abas.item :href="route('suporte.base')" :ativo="$aba === 'base'" icone="clipboard">Base do suporte
        @if ($aConfirmar)<span class="rounded-badge bg-warn-tint px-1.5 py-0.5 text-[11px] font-semibold text-warn" title="Registros a confirmar">{{ $aConfirmar }}</span>@endif
    </x-abas.item>
</x-abas>
