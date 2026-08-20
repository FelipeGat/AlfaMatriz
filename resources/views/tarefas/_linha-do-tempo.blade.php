@php
    /**
     * Linha do tempo da tarefa: cada passagem por etapa, na ordem em que
     * aconteceu (US-082).
     *
     * Somente leitura, como tudo no modal de histórico. Espera `$tarefa` com
     * `eventos.autor` carregado — a página do histórico traz tudo num
     * `with()` só, e a partial não dispara consulta nenhuma.
     *
     * A primeira linha é a criação, que não é evento: evento só nasce no
     * primeiro movimento, e sem ela a tarefa pareceria ter começado onde
     * primeiro CHEGOU. A etapa de partida sai do `de_status` do primeiro
     * evento; tarefa que nunca se moveu continua na etapa em que está.
     *
     * O rótulo sai de `rotuloDaEtapa`, que conhece as etapas aposentadas
     * (AC-296): o histórico antigo passou por `em_testes` e por `bloqueada`,
     * e mostrar a chave crua no lugar do nome é o erro que
     * `ETAPAS_APOSENTADAS` existe para impedir.
     */
    $eventos = $tarefa->eventos->sortBy([['entrou_em', 'asc'], ['id', 'asc']])->values();
    $etapaInicial = $eventos->first()->de_status ?? $tarefa->status;
@endphp

<div>
    <div class="flex items-center gap-2 mb-2.5">
        <h4 class="flex-1 font-mono text-[11.5px] font-semibold uppercase tracking-caps-wide text-ink">Linha do tempo</h4>
    </div>

    <ol class="flex flex-col gap-2.5">
        <li class="flex gap-2.5">
            <span class="mt-[5px] h-[7px] w-[7px] rounded-full shrink-0"
                  style="background: rgb(var(--{{ \App\Models\Tarefa::corDaEtapa($etapaInicial) }}))"></span>
            <div class="min-w-0 flex-1">
                <p class="text-[12.5px] text-ink">
                    <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-dim">Criada</span>
                    <span class="text-ink-mute">em {{ \App\Models\Tarefa::rotuloDaEtapa($etapaInicial) }}</span>
                </p>
                <p class="font-sans tabular text-[11.5px] text-ink-faint">
                    {{ $tarefa->created_at->format('d/m/Y H:i') }}
                    @if ($tarefa->criadoPor)
                        · por {{ $tarefa->criadoPor->name }}
                    @endif
                </p>
            </div>
        </li>

        @foreach ($eventos as $evento)
            <li class="flex gap-2.5">
                <span class="mt-[5px] h-[7px] w-[7px] rounded-full shrink-0"
                      style="background: rgb(var(--{{ \App\Models\Tarefa::corDaEtapa($evento->para_status) }}))"></span>
                <div class="min-w-0 flex-1">
                    <p class="text-[12.5px] text-ink">
                        <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-dim">{{ \App\Models\Tarefa::rotuloDaEtapa($evento->para_status) }}</span>
                    </p>
                    <p class="font-sans tabular text-[11.5px] text-ink-faint">
                        {{ $evento->entrou_em->format('d/m/Y H:i') }}
                        {{-- O autor só existe daqui pra frente (AC-302):
                             evento anterior à coluna aparece sem ele, e a
                             linha não pode quebrar nem insinuar anonimato
                             suspeito — não haver autor era a regra. --}}
                        @if ($evento->autor)
                            · por {{ $evento->autor->name }}
                        @endif
                        {{-- Sem `saiu_em` a tarefa AINDA está na etapa: no
                             histórico, é o desfecho — a data de entrada já
                             diz tudo. --}}
                        @if ($evento->saiu_em && $evento->duracao_segundos !== null)
                            · ficou {{ \App\Models\Tarefa::duracaoCurta($evento->duracao_segundos) }}
                        @endif
                    </p>
                    @if (filled($evento->motivo))
                        <p class="mt-0.5 text-[12px] leading-snug text-ink-mute">{{ $evento->motivo }}</p>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
</div>
