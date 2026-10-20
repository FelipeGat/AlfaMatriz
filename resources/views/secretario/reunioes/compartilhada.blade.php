@php use App\Models\SecretarioReuniaoTrecho; @endphp
<x-app-layout>
    <x-slot name="titulo">{{ $r->titulo }}</x-slot>
    <x-slot name="contexto"><span>{{ $r->created_at->format('d/m/Y H:i') }}{{ $r->duracao_s ? ' · '.SecretarioReuniaoTrecho::horario($r->duracao_s) : '' }}{{ $r->sistema ? ' · '.$r->sistema->nome : '' }}</span></x-slot>

    {{-- docs/specs/secretario-reuniao-produto: o que o participante vê — o retrato
         da reunião. A transcrição e as propostas não revisadas ficam com o dono (FR-004). --}}
    @php $secao = 'rounded-panel border border-line bg-surface p-4'; $rotulo = 'mb-2 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint'; @endphp
    <div class="mx-auto w-full max-w-3xl space-y-4">
        <p class="text-[12.5px] text-ink-mute">
            Compartilhada por {{ $r->dono?->name }} com {{ $r->compartilhadaCom->pluck('name')->implode(', ') }}.
            <a href="{{ route('reunioes.compartilhadas') }}" class="text-brand-text hover:underline">Outras reuniões compartilhadas</a>
        </p>
        @if (! $r->resumo)
            <div class="{{ $secao }} text-[13.5px] text-ink-mute">A reunião ainda está sendo transcrita e analisada. Volte em alguns minutos.</div>
        @else
            <section class="{{ $secao }}">
                <p class="{{ $rotulo }}">Resumo</p>
                <p class="whitespace-pre-line text-[13.5px] leading-relaxed text-ink">{{ $r->resumo }}</p>
                @if (array_filter((array) $r->contexto, fn ($v) => $v && $v !== 'não identificado'))
                    <dl class="mt-3 grid grid-cols-1 gap-x-4 gap-y-1 border-t border-line pt-3 text-[12.5px] sm:grid-cols-2">
                        @foreach (['cliente' => 'Cliente', 'objetivo' => 'Objetivo', 'problema' => 'Problema', 'necessidade' => 'Necessidade'] as $k => $nome)
                            @if (($r->contexto[$k] ?? '') && $r->contexto[$k] !== 'não identificado')
                                <div><dt class="inline text-ink-faint">{{ $nome }}:</dt> <dd class="inline text-ink-dim">{{ $r->contexto[$k] }}</dd></div>
                            @endif
                        @endforeach
                    </dl>
                @endif
            </section>
            <section class="{{ $secao }}">
                <p class="{{ $rotulo }}">Decisões</p>
                @forelse ($decisoes as $d)<p class="text-[13.5px] text-ink">• {{ $d->texto }}</p>@empty<p class="text-[13px] text-ink-mute">Nenhuma decisão registrada.</p>@endforelse
            </section>
            <section class="{{ $secao }}">
                <p class="{{ $rotulo }}">Tarefas e pendências aprovadas</p>
                @forelse ($tarefas as $t)
                    <p class="text-[13.5px] text-ink">• {{ $t->texto }}<span class="text-[12px] text-ink-mute">{{ $t->responsavel ? ' — '.$t->responsavel : '' }}{{ $t->prazo ? ' · até '.\Illuminate\Support\Carbon::parse($t->prazo)->format('d/m') : '' }}</span></p>
                @empty
                    <p class="text-[13px] text-ink-mute">Nenhuma ainda — {{ $r->dono?->name }} aprova as tarefas depois da revisão.</p>
                @endforelse
            </section>
            @if ($r->proximos_passos)
                <section class="{{ $secao }}">
                    <p class="{{ $rotulo }}">Próximos passos</p>
                    @foreach ($r->proximos_passos as $p)<p class="text-[13.5px] text-ink">• {{ $p }}</p>@endforeach
                </section>
            @endif
        @endif
    </div>
</x-app-layout>
