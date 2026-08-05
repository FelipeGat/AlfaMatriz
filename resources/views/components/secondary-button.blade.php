<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-panel-raised border border-white/10 rounded-md font-semibold text-xs text-ink-dim uppercase tracking-widest hover:text-ink hover:border-white/20 focus:outline-none focus:ring-2 focus:ring-brand-dim focus:ring-offset-2 focus:ring-offset-canvas disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
