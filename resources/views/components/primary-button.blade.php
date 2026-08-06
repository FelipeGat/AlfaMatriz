{{-- Botão primário: fundo de marca, texto na cor que contrasta com ela.
     Veio da direção anterior apontando para tokens que não existem mais
     (`bg-ink` é a nossa tinta, quase branca — daí ele aparecer branco). --}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex h-8 items-center rounded-control bg-brand px-3 text-[12.5px] font-medium text-on-brand transition-opacity hover:opacity-90 disabled:cursor-default disabled:bg-chip disabled:text-ink-faint disabled:opacity-100']) }}>
    {{ $slot }}
</button>
