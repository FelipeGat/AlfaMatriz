{{--
    Uma linha do "O que espera você" (#300) — a mesma linha da Fila de ação do
    Centro de Controle (barra de severidade, tile tingido, botão por linha). É
    o mesmo tipo de coisa, "o que precisa de mim", e duas linhas diferentes
    para a mesma pergunta fariam a pessoa reaprender a ler a cada tela.

    Espera: $item (ver `App\Services\OQueEsperaVoce::pendencias`).
--}}
@php
    $token = match ($item['nivel']) {
        'critico' => 'crit',
        'atencao' => 'warn',
        default => 'brand-text',
    };
@endphp

<div class="relative flex items-center gap-3 px-4 py-3.5 border-b border-rule last:border-0" data-espera="{{ $item['tipo'] }}">
    <span class="absolute left-0 inset-y-0 w-[2px]" style="background: rgb(var(--{{ $token }}))"></span>

    <span class="h-7 w-7 shrink-0 rounded-tile flex items-center justify-center"
          style="background: rgb(var(--{{ $token }}) / var(--tint-alpha)); color: rgb(var(--{{ $token }}))">
        <span class="h-[15px] w-[15px]"><x-nav-icon :name="$item['icone']" /></span>
    </span>

    <span class="min-w-0 flex-1">
        <span class="block text-[14px] font-medium text-ink truncate" title="{{ $item['tarefa']->titulo }}">
            <span class="font-mono text-[11px] text-ink-faint">{{ $item['tarefa']->codigo() }}</span>
            {{ $item['tarefa']->titulo }}
        </span>
        <span class="block text-[11.5px] text-ink-mute truncate">
            {{ $item['motivo'] }}@if ($item['ha']) · há {{ $item['ha'] }}@endif
        </span>
    </span>

    <a href="{{ $item['rota'] }}"
       class="shrink-0 h-7 px-2.5 rounded-tile border border-btn-line
              font-mono text-[10.5px] font-semibold uppercase tracking-[0.08em]
              text-ink-mute hover:text-brand hover:border-brand transition
              inline-flex items-center whitespace-nowrap">{{ $item['acao'] }}</a>
</div>
