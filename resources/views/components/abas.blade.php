{{--
    Seletor de abas em pílula (segmented control). Ativo sempre em marca,
    os demais em tinta neutra; o grupo inteiro vive sobre uma superfície
    recuada com borda, para a pílula não disputar com o conteúdo.

    Uso:
        <x-abas>
            <x-slot name="abas">
                <x-abas.item href="..." ativo icon="building">Revendas</x-abas.item>
                <x-abas.item href="..." icon="users">Clientes</x-abas.item>
            </x-slot>
        </x-abas>
--}}
{{-- No celular o grupo não passa da tela: rola de lado dentro da própria
     moldura, e cada aba mantém o tamanho (sem `shrink-0` elas se espremiam
     até o rótulo quebrar em duas linhas). --}}
@props([])

<div {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-control border border-line bg-surface p-1 max-md:max-w-full max-md:overflow-x-auto max-md:[&>*]:shrink-0']) }}>
    {{ $slot }}
</div>
