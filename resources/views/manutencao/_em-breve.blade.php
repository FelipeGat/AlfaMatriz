{{--
    As abas Atualizações e Programadas ainda são promessa (#225). Elas existem
    antes do conteúdo para a tela já contar o que vai morar nela — o recorte
    (por sistema) e a forma (agenda e histórico), que é o que dá para prometer
    sem inventar dado. Espera: $aba.
--}}
@php
    $vem = $aba === 'programadas'
        ? ['icone' => 'clock', 'titulo' => 'Atualizações programadas', 'texto' => 'Quando cada sistema entra em manutenção, avisado antes de acontecer.']
        : ['icone' => 'document', 'titulo' => 'Histórico de atualizações', 'texto' => 'O que cada versão publicada mudou, com o changelog completo.'];
@endphp

<div class="mx-auto max-w-[560px] pt-6">
    <div class="rounded-panel border border-line bg-surface overflow-hidden">
        <div class="p-6 text-center">
            <span class="mx-auto h-10 w-10 rounded-tile bg-chip text-ink-mute flex items-center justify-center">
                <span class="h-[18px] w-[18px]"><x-nav-icon :name="$vem['icone']" :peso="1.7" /></span>
            </span>

            <span class="mt-4 inline-block rounded-badge bg-brand/20 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-caps text-brand-text">
                Em breve
            </span>

            <p class="mt-2 text-[13px] font-medium text-ink">{{ $vem['titulo'] }}</p>
            <p class="mt-0.5 text-[12.5px] leading-relaxed text-ink-mute">{{ $vem['texto'] }}</p>
        </div>
    </div>
</div>
