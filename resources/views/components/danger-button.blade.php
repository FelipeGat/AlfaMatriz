<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-status-critical border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:opacity-90 active:opacity-100 focus:outline-none focus:ring-2 focus:ring-status-critical focus:ring-offset-2 focus:ring-offset-canvas transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
