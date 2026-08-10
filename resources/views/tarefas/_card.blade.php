@php
    /**
     * Card do quadro: sistema, prioridade e tempo na etapa atual (US-038).
     * A etapa atual é o evento de `tarefa_eventos` ainda sem saída; tarefa
     * que nunca se moveu (sem evento nenhum) conta a partir da criação.
     */
    $eventoAberto = $tarefa->eventos->firstWhere('saiu_em', null);
    $entrouNaEtapaEm = $eventoAberto?->entrou_em ?? $tarefa->created_at;
    $segundosNaEtapa = $entrouNaEtapaEm->diffInSeconds(now());

    $tempoNaEtapa = match (true) {
        $segundosNaEtapa < 60 => 'agora',
        $segundosNaEtapa < 3600 => intdiv($segundosNaEtapa, 60).'m',
        $segundosNaEtapa < 86400 => intdiv($segundosNaEtapa, 3600).'h',
        default => intdiv($segundosNaEtapa, 86400).'d',
    };

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

    <p class="mt-1 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint truncate">
        {{ $tarefa->sistema?->nome ?? 'Sem sistema' }}@if ($tarefa->responsavel) · {{ $tarefa->responsavel->name }}@endif
    </p>

    <div class="mt-2 pt-2 border-t border-rule flex items-center gap-2">
        <x-badge :tom="$tomEsquecida ? $nivelEsquecida : 'neutro'"
                 :title="'Na etapa há '.$tempoNaEtapa">
            {{ $tempoNaEtapa }}
        </x-badge>
    </div>
</article>
