{{--
    A lista de avisos do sino — extraída para partial porque dois lugares a
    desenham com a MESMA marcação: o painel na primeira carga da página, e o
    endpoint `notificacoes.lista`, que o poll do sino busca para atualizar o
    painel quando algo chega sem recarregar. Um só arquivo garante que a linha
    seja idêntica venha de onde vier.

    Espera: $notificacoes (coleção de App\Models\Notificacao).
--}}
@forelse ($notificacoes ?? [] as $aviso)
    @php
        // O mesmo vocabulário de gravidade da fila de ação: o aviso não pode
        // mudar de cor conforme a tela em que aparece.
        $tom = ['critico' => 'crit', 'atencao' => 'warn', 'marca' => 'brand'][$aviso->nivel] ?? 'brand';
    @endphp

    {{--
        Não lida = barra de 2px na cor do tipo + um degrau de superfície. Só o
        fundo não bastaria: num painel de doze linhas, um cinza levemente mais
        claro sem a barra se lê como zebra de tabela, e não como "isto é novo".
    --}}
    <a href="{{ $aviso->rota ?? url('/') }}"
       @class([
           'flex items-start gap-2.5 px-4 py-2.5 border-b border-rule last:border-b-0 border-l-2 transition hover:bg-chip',
           'bg-chip' => $aviso->naoLida(),
           'border-l-transparent' => ! $aviso->naoLida(),
       ])
       @if ($aviso->naoLida()) style="border-left-color: rgb(var(--{{ $tom }}))" @endif>
        <span class="shrink-0 mt-px h-6 w-6 rounded-tile flex items-center justify-center"
              style="background: rgb(var(--{{ $tom }}) / var(--tint-alpha)); color: rgb(var(--{{ $tom }}))">
            <span class="h-[13px] w-[13px]"><x-nav-icon :name="$aviso->icone" :peso="1.8" /></span>
        </span>

        <span class="min-w-0 flex-1">
            {{-- Uma linha e truncado: o painel é relance. O título inteiro vai
                 no `title`, que entrega o resto sem custar altura. --}}
            <span class="block truncate text-[12.5px] leading-snug text-ink" title="{{ $aviso->titulo }}">
                {{ $aviso->titulo }}
            </span>
            <span class="block truncate font-mono text-[10px] uppercase tracking-caps text-ink-faint">
                {{ $aviso->meta ? $aviso->meta.' · ' : '' }}{{ $aviso->created_at->diffForHumans(short: true) }}
            </span>
        </span>
    </a>
@empty
    <p class="px-4 py-6 text-center text-[12.5px] text-ink-faint">Nada de novo por aqui.</p>
@endforelse
