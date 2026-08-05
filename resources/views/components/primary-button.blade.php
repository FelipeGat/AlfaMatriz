<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-brand border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-bright focus:bg-brand-bright active:bg-brand-mute focus:outline-none focus:ring-2 focus:ring-brand-dim focus:ring-offset-2 focus:ring-offset-canvas transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
