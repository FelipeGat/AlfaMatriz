{{-- As duas telas do Suporte WhatsApp: as conversas e o que o Pedro sabe. --}}
<x-abas>
    <x-abas.item :href="route('suporte.conversas')" :ativo="$aba === 'conversas'" icone="chat">Conversas</x-abas.item>
    <x-abas.item :href="route('suporte.pedro')" :ativo="$aba === 'pedro'" icone="document">Pedro: conhecimento</x-abas.item>
</x-abas>
