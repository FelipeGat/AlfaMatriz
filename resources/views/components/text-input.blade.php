@props(['disabled' => false])

{{-- A aparência vem da regra base em app.css, comum a todos os campos: aqui
     só o tamanho e o estado desabilitado. --}}
<input @disabled($disabled) {{ $attributes->merge(['class' => 'h-8 rounded-control text-[12.5px] disabled:cursor-default disabled:text-ink-faint']) }}>
