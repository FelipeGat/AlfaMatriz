{{-- Um ponto de gestão lançado à mão (Etapa 8): tarefa do Secretário com empresa. --}}
@php $atrasado = \App\Services\Secretario\CentralDeGestao::atrasado($i); @endphp
<li class="flex items-start gap-2 px-4 py-2.5">
    <span class="mt-0.5 text-[12px]">{{ ['alta' => '🔴', 'normal' => '🟡', 'baixa' => '⚪'][$i->prioridade] ?? '•' }}</span>
    <div class="min-w-0 flex-1">
        <p class="text-[13.5px] text-ink break-words">{{ $i->titulo }}</p>
        <p class="text-[11.5px] {{ $atrasado ? 'text-crit' : 'text-ink-mute' }}">
            {{ $i->quando ? ($atrasado ? 'atrasado · ' : 'prazo ').\App\Services\Secretario\Datas::porExtenso($i->quando, $i->so_data) : 'sem prazo' }} · lançado por você
        </p>
    </div>
    <form method="POST" action="{{ route('secretario.acompanhamentos.pontos.concluir', $i->id) }}">@csrf
        <button class="h-[28px] rounded-control border border-good-line px-2 text-[12px] font-semibold text-good hover:bg-good-tint">✓</button>
    </form>
</li>
