{{-- Destrutivo é dos poucos lugares em que a cor carrega significado: ela
     avisa antes do clique. --}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex h-8 items-center rounded-control bg-bad px-3 text-[12.5px] font-medium text-white transition-opacity hover:opacity-90']) }}>
    {{ $slot }}
</button>
