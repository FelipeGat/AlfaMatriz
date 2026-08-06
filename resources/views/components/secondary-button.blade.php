{{-- Secundário: transparente com fio de borda; cede o peso ao primário. --}}
<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex h-8 items-center rounded-control border border-btn-line px-3 text-[12.5px] text-ink-dim transition-colors hover:text-brand hover:border-brand disabled:cursor-default disabled:text-ink-faint']) }}>
    {{ $slot }}
</button>
