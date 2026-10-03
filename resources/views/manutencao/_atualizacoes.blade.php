{{--
    A aba Atualizações (#225): o changelog que foi ao Telegram, por sistema e
    data, com a versão e as tarefas que entraram nela.
    Espera: $filtros, $sistemas, $atualizacoes.
--}}
@if ($sistemas->count() > 1)
    <form method="GET" class="flex flex-wrap items-center gap-2">
        <input type="hidden" name="aba" value="atualizacoes">

        <select name="sistema" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
            <option value="">Todos os sistemas</option>
            @foreach ($sistemas as $sistema)
                <option value="{{ $sistema->id }}" @selected($filtros['sistema'] === $sistema->id)>{{ $sistema->nome }}</option>
            @endforeach
        </select>

        <button type="submit"
                class="h-[34px] px-3 rounded-control border border-btn-line text-ink-dim
                       text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
            Filtrar
        </button>
    </form>
@endif

@forelse ($atualizacoes as $atualizacao)
    @php $tarefas = $atualizacao->tarefasDaVersao(); @endphp

    {{-- O texto inteiro fica fechado: a lista é para achar a versão, e um
         changelog de três partes aberto empurraria o próximo para fora da tela. --}}
    <article x-data="{ aberto: false }" class="rounded-panel border border-line bg-surface overflow-hidden">
        <div class="flex items-start gap-3 px-5 py-4">
            <span class="mt-0.5 h-[26px] w-[26px] shrink-0"><x-marca-sistema :sistema="$atualizacao->sistema" /></span>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <p class="text-[13.5px] font-semibold text-ink">{{ $atualizacao->sistema->nome }}</p>
                    <span class="font-sans tabular text-[12.5px] text-ink-mute">
                        {{ $atualizacao->data->format('d/m/Y').($atualizacao->horaDoEnvio() ? ' às '.$atualizacao->horaDoEnvio()->format('H:i') : '') }}
                    </span>
                    @if ($atualizacao->versao)
                        <x-badge tom="marca">{{ $atualizacao->versao }}</x-badge>
                    @endif
                    @if ($atualizacao->origem === 'importado')
                        <x-badge title="Publicado antes desta tela existir; importado do arquivo {{ $atualizacao->arquivo }}">importado</x-badge>
                    @endif
                </div>

                @if ($atualizacao->titulo)
                    <p class="mt-1 text-[13px] text-ink-dim">{{ $atualizacao->titulo }}</p>
                @endif

                @if ($tarefas->isNotEmpty())
                    <div class="mt-2 flex flex-wrap items-center gap-1.5">
                        @foreach ($tarefas as $tarefa)
                            <a href="{{ route('tarefas.index', ['tarefa' => $tarefa->id]) }}" title="{{ $tarefa->titulo }}"
                               class="rounded-badge bg-chip px-1.5 py-[3px] font-mono text-[10.5px] font-semibold text-brand-text hover:underline">{{ $tarefa->codigo() }}</a>
                        @endforeach
                    </div>
                @endif
            </div>

            <button type="button" @click="aberto = ! aberto"
                    class="h-[30px] shrink-0 inline-flex items-center gap-1 rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim transition hover:text-brand hover:border-brand">
                <span x-text="aberto ? 'Fechar' : 'Ver changelog'"></span>
                <span class="h-3.5 w-3.5 transition" :class="aberto ? 'rotate-180' : ''"><x-nav-icon name="chevron-down" /></span>
            </button>
        </div>

        <div x-show="aberto" x-cloak class="border-t border-rule">
            {{-- De onde veio e quando: o importado diz que não tem hora, em vez
                 de mostrar a da importação como se fosse a do envio. --}}
            <p class="px-5 pt-3 font-mono text-[11px] text-ink-faint">
                @if ($atualizacao->horaDoEnvio())
                    Enviado em {{ $atualizacao->horaDoEnvio()->format('d/m/Y').' às '.$atualizacao->horaDoEnvio()->format('H:i') }}{{ $atualizacao->registradoPor ? ' por '.$atualizacao->registradoPor->name : '' }}
                @elseif ($atualizacao->origem === 'importado')
                    Importado{{ $atualizacao->arquivo ? ' de '.$atualizacao->arquivo : '' }} · publicado em {{ $atualizacao->data->format('d/m/Y') }}, sem horário guardado
                @else
                    Publicado em {{ $atualizacao->data->format('d/m/Y') }} · registrado em {{ $atualizacao->created_at->format('d/m/Y').' às '.$atualizacao->created_at->format('H:i') }}
                @endif
            </p>

            @foreach ($atualizacao->partes() as $parte)
                <div class="px-5 py-4 text-[13px] leading-relaxed text-ink-dim [&_b]:text-ink [&_b]:font-semibold {{ $loop->first ? '' : 'border-t border-rule' }}">
                    {{ \App\Models\Atualizacao::htmlSeguro($parte) }}
                </div>
            @endforeach
        </div>
    </article>
@empty
    <div class="rounded-panel border border-line bg-surface px-5 py-8 text-center">
        <p class="text-[13px] font-medium text-ink">Nenhuma atualização registrada ainda.</p>
        <p class="mt-1 text-[12.5px] text-ink-mute">
            Cada changelog publicado pelo <span class="font-mono">deploy/publicar-changelog.sh</span> passa a aparecer aqui, com a versão e as tarefas.
        </p>
    </div>
@endforelse

{{ $atualizacoes->links() }}
