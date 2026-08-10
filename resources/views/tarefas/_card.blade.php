@php
    /**
     * Card do quadro: sistema, prioridade e tempo na etapa atual (US-038).
     * A etapa atual é o evento de `tarefa_eventos` ainda sem saída; tarefa
     * que nunca se moveu (sem evento nenhum) conta a partir da criação.
     */
    $eventoAberto = $tarefa->eventos->firstWhere('saiu_em', null);
    $entrouNaEtapaEm = $eventoAberto?->entrou_em ?? $tarefa->created_at;
    $segundosNaEtapa = $entrouNaEtapaEm->diffInSeconds(now());

    // Mesma régua do histórico (`Tarefa::duracaoCurta`): "3h" precisa querer
    // dizer a mesma coisa nas duas telas.
    $tempoNaEtapa = \App\Models\Tarefa::duracaoCurta((int) $segundosNaEtapa);

    // AC-093: só Aberta e Em testes ganham destaque de tarefa esquecida.
    $etapasComDestaqueDeEsquecida = ['aberta', 'em_testes'];
    $nivelEsquecida = null;
    if (in_array($tarefa->status, $etapasComDestaqueDeEsquecida, true)) {
        $horasNaEtapa = $segundosNaEtapa / 3600;
        $nivelEsquecida = match (true) {
            $horasNaEtapa >= 48 => 'critico',
            $horasNaEtapa >= 24 => 'atencao',
            default => null,
        };
    }
    $tomEsquecida = ['atencao' => 'warn', 'critico' => 'crit'][$nivelEsquecida] ?? null;

    // Um tom por nível, sem repetir: com `baixa` e `media` no mesmo neutro,
    // dois dos quatro níveis ficavam indistinguíveis e a escala perdia o
    // meio. A ordem sobe do mais discreto ao mais grave (AC-113).
    $tomPrioridade = [
        'baixa' => 'neutro',
        'media' => 'marca',
        'alta' => 'atencao',
        'critica' => 'critico',
    ][$tarefa->prioridade] ?? 'neutro';
@endphp

<article data-tarefa="{{ $tarefa->id }}"
         @if ($nivelEsquecida) data-esquecida="{{ $nivelEsquecida }}" @endif
         class="rounded-ctl bg-card-grad border p-2.5"
         style="border-color: {{ $tomEsquecida ? 'rgb(var(--'.$tomEsquecida.') / 0.4)' : 'var(--line)' }}">
    <div class="flex items-start gap-2">
        <p class="min-w-0 flex-1 truncate text-[13.5px] font-medium text-ink">{{ $tarefa->titulo }}</p>
        <x-badge :tom="$tomPrioridade">{{ \App\Models\Tarefa::PRIORIDADES[$tarefa->prioridade] ?? $tarefa->prioridade }}</x-badge>
    </div>

    {{--
        O resumo só ocupa espaço quando existe: `@if` e não uma linha vazia
        reservada, senão todo card sem resumo ganharia um buraco. Uma linha
        truncada — o card é relance, o texto inteiro é assunto do detalhe
        (AC-116). O `title` entrega o resto sem custar altura.
    --}}
    @if (filled($tarefa->resumo))
        <p class="mt-1 text-[12px] leading-snug text-ink-mute truncate"
           title="{{ $tarefa->resumo }}">{{ $tarefa->resumo }}</p>
    @endif

    {{--
        A linha de metadados tem SEMPRE os dois segmentos. Antes, tarefa sem
        responsável simplesmente omitia o nome, e a falta era lida por
        comparação com os cards vizinhos — logo na coluna Aberta, que é a fila
        que pede triagem (AC-117). Dito em texto e sem cor de alarme: a borda
        do card já é o canal do aviso de esquecida.
    --}}
    <p class="mt-1 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint truncate">
        {{ $tarefa->sistema?->nome ?? 'Sem sistema' }} · {{ $tarefa->responsavel?->name ?? 'Sem responsável' }}
    </p>

    <div class="mt-2 pt-2 border-t border-rule flex items-center gap-2">
        <x-badge :tom="$tomEsquecida ? $nivelEsquecida : 'neutro'"
                 :title="'Na etapa há '.$tempoNaEtapa">
            {{ $tempoNaEtapa }}
        </x-badge>
    </div>
</article>
