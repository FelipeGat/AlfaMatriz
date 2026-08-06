@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-mono text-[10px] font-medium uppercase tracking-[.08em] text-ink-faint']) }}>
    {{ $value ?? $slot }}
</label>
