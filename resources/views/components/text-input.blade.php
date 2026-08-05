@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'bg-panel-raised border-white/10 text-ink placeholder-ink-mute focus:border-brand focus:ring-brand-dim rounded-md shadow-sm']) }}>
