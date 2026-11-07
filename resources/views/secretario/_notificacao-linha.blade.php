{{--
    Uma notificação em Acompanhamentos, com as ações dela. A mesma linha serve
    o destaque (o aviso que trouxe a pessoa do sino), a lista a tratar e as
    tratadas: o estado vem do Alpine da seção (`feito[id]`), e não do servidor,
    para Tratado e Desfazer valerem sem recarregar uma tela de cem linhas e
    sem perder a rolagem de quem está no meio dela.

    De quem é (Felipe, 07/11/2026) vai na linha de baixo, antes da origem: é
    o que decide se o aviso é para tratar agora ou para cobrar de alguém.

    Espera: $n (App\Models\Notificacao); $destaque (bool, opcional); da tela,
    $deAviso, $chaveDe e $rotuloDe.
--}}
@php
    $tom = $n->tom();
    $de = $deAviso[$n->id] ?? null;
@endphp
<li id="notificacao-{{ $n->id }}" @unless ($destaque ?? false) x-show="visivel({{ $n->id }})" @endunless
    class="flex flex-wrap items-start gap-3 px-4 py-2.5 border-l-2 border-l-transparent"
    :style="feito[{{ $n->id }}] ? '' : 'border-left-color: rgb(var(--{{ $tom }}))'">
    <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-ctl"
          style="background: rgb(var(--{{ $tom }}) / var(--tint-alpha)); color: rgb(var(--{{ $tom }}))">
        <span class="h-[18px] w-[18px]"><x-nav-icon :name="$n->icone ?: 'bell'" peso="1.7" /></span>
    </span>

    {{-- Título inteiro, sem corte: no sino ele é relance numa linha; aqui é
         onde se decide o que fazer, e decidir pela metade do texto foi a
         queixa que trouxe a lista para cá. --}}
    <div class="min-w-0 flex-1">
        <p class="break-words text-[13.5px] font-semibold" :class="feito[{{ $n->id }}] ? 'text-ink-faint line-through' : 'text-ink'">{{ $n->titulo }}</p>
        <p class="text-[12px] text-ink-mute">
            @if ($de)
                <span class="mr-1 inline-block rounded-badge px-1.5 py-0.5 text-[11px] {{ $chaveDe($de) === 'eu' ? 'bg-brand/10 font-semibold text-brand-text' : 'bg-head text-ink-dim' }}">{{ $rotuloDe($de) }}</span>
            @endif
            {{ $n->meta ? $n->meta.' · ' : '' }}{{ $n->created_at->format('d/m H:i') }} · {{ $n->created_at->diffForHumans(short: true) }}
        </p>
    </div>

    <div class="flex shrink-0 items-center gap-1.5">
        @if ($n->destino())
            <a href="{{ route('notificacoes.abrir', $n->id) }}"
               class="inline-flex h-[28px] items-center rounded-control bg-brand px-3 text-[12px] font-semibold text-on-brand hover:bg-brand-bright">Abrir</a>
        @endif
        <button type="button" x-show="! feito[{{ $n->id }}]" @click="alternar({{ $n->id }}, true)"
                class="inline-flex h-[28px] items-center rounded-control border border-btn-line px-3 text-[12px] font-semibold text-ink-dim hover:text-ink">Tratado</button>
        <span x-show="feito[{{ $n->id }}]" x-cloak class="text-[12px] text-ink-faint">tratado</span>
        <button type="button" x-show="feito[{{ $n->id }}]" x-cloak @click="alternar({{ $n->id }}, false)"
                class="inline-flex h-[28px] items-center rounded-control border border-btn-line px-3 text-[12px] font-semibold text-ink-dim hover:text-ink">
            {{ $n->naoLida() ? 'Desfazer' : 'Voltar a tratar' }}
        </button>
    </div>
</li>
