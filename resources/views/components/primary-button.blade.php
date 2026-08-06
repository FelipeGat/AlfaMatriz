{{-- Primário monocromático invertido: fundo ink sobre texto bg. É o "Add New"
     da referência — sem cor, o destaque vem do contraste. --}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex h-8 items-center rounded-control bg-ink px-3 text-[12.5px] font-medium text-bg transition-opacity hover:opacity-90 disabled:cursor-default disabled:bg-raised disabled:text-mute disabled:opacity-100']) }}>
    {{ $slot }}
</button>
