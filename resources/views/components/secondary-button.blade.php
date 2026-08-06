{{-- Secundário: transparente com fio de borda. --}}
<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex h-8 items-center rounded-control border border-line px-3 text-[12.5px] text-dim transition-colors hover:text-ink disabled:cursor-default disabled:text-mute']) }}>
    {{ $slot }}
</button>
