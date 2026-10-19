@php
    use App\Models\SecretarioReuniao;
    use App\Models\SecretarioReuniaoTrecho;
    $tarefas = $r->propostas->whereIn('categoria', ['tarefa', 'pendencia']);
    $decisoes = $r->propostas->where('categoria', 'decisao');
    $rotuloClass = ['decisao' => 'decisão', 'compromisso' => 'compromisso assumido', 'sugestao' => 'sugestão', 'hipotese' => 'hipótese', 'pendencia' => 'pendência', 'informacao' => 'informação'];
    $final = in_array($r->status, [SecretarioReuniao::PRONTA, SecretarioReuniao::REVISADA, SecretarioReuniao::FALHOU], true);
@endphp
<x-app-layout>
    <x-slot name="titulo">{{ $r->titulo }}</x-slot>
    <x-slot name="contexto"><span>{{ $r->created_at->format('d/m/Y H:i') }}{{ $r->duracao_s ? ' · '.SecretarioReuniaoTrecho::horario($r->duracao_s) : '' }}{{ $r->tipo_reuniao ? ' · '.$r->tipo_reuniao : '' }}</span></x-slot>
    <x-slot name="acoes">
        <a href="{{ route('secretario.reunioes.index') }}" class="h-[34px] px-3 inline-flex items-center rounded-control border border-btn-line text-ink-dim text-[12.5px] font-semibold hover:text-brand hover:border-brand whitespace-nowrap">Reuniões</a>
        @if (in_array($r->status, [SecretarioReuniao::PRONTA, SecretarioReuniao::REVISADA], true))
            <a href="{{ route('secretario.reunioes.ata', $r->id) }}" target="_blank" class="h-[34px] px-3 inline-flex items-center rounded-control border border-btn-line text-ink-dim text-[12.5px] font-semibold hover:text-brand hover:border-brand whitespace-nowrap">Ata</a>
        @endif
    </x-slot>

    <div class="mx-auto w-full max-w-3xl space-y-4" x-data="{ status: @js($r->status), a: @js($r->andamento()) }"
         @if (! $final) x-init="setInterval(async () => { const j = await (await fetch(@js(route('secretario.reunioes.mostrar', ['reuniao' => $r->id, 'formato' => 'json'])), { headers: { Accept: 'application/json' } })).json(); if (j.status !== status) location.reload(); a = j.andamento; }, 8000)" @endif>
        <p class="rounded-panel border px-4 py-2.5 text-[13px] {{ $r->status === 'falhou' ? 'border-crit text-crit' : 'border-line text-ink-dim' }}">
            {{ SecretarioReuniao::ROTULOS_STATUS[$r->status] ?? $r->status }}{{ $r->erro ? ': '.$r->erro : '' }}
            @if (! $final) <span class="ml-1 inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-brand"></span> @endif
            @if ($r->parcial) · análise parcial (transcrição longa cortada) @endif
            <template x-if="a">
                <span class="mt-2 flex items-center gap-3">
                    <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-head"><span class="block h-full rounded-full bg-brand transition-all duration-700" :class="a.percentual === null ? 'w-1/4 animate-pulse' : ''" :style="a.percentual === null ? '' : 'width:' + a.percentual + '%'"></span></span>
                    <span class="whitespace-nowrap text-[12px] text-ink-mute" x-text="a.texto"></span>
                </span>
            </template>
        </p>
        @if ($r->status === 'falhou' && $r->transcrita_em && $r->modo === 'reuniao')
            <form method="POST" action="{{ route('secretario.reunioes.analisar', $r->id) }}">@csrf
                <button class="h-[34px] rounded-control bg-brand px-3 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright">Analisar de novo</button>
            </form>
        @endif

        @if ($r->resumo)
            <x-painel titulo="Resumo">
                <p class="whitespace-pre-line text-[14px] text-ink">{{ $r->resumo }}</p>
                @if ($r->participantes)
                    <p class="mt-2 text-[12px] text-ink-mute">Nomes citados na fala (inferência): {{ implode(', ', $r->participantes) }}</p>
                @endif
            </x-painel>
            <x-painel titulo="Contexto">
                <dl class="grid grid-cols-1 gap-x-4 gap-y-1 text-[13px] sm:grid-cols-2">
                    @foreach (['empresa' => 'Empresa', 'cliente' => 'Cliente', 'projeto' => 'Projeto', 'objetivo' => 'Objetivo', 'problema' => 'Problema', 'necessidade' => 'Necessidade'] as $k => $rot)
                        <div><dt class="inline text-ink-mute">{{ $rot }}:</dt> <dd class="inline text-ink">{{ $r->contexto[$k] ?? 'não identificado' }}</dd></div>
                    @endforeach
                </dl>
            </x-painel>
            <x-painel titulo="Decisões" solto>
                @forelse ($decisoes as $d)
                    <div class="border-b border-line px-4 py-2.5 text-[13.5px] text-ink last:border-0">{{ $d->texto }}
                        <span class="ml-1 text-[11.5px] text-ink-mute">evidência: @foreach ($d->evidencias as $n)<a href="#t-{{ $n }}" class="text-brand-text hover:underline">[{{ $n }}]</a> @endforeach</span>
                    </div>
                @empty
                    <p class="px-4 py-3 text-[13px] text-ink-mute">Nenhuma decisão identificada.</p>
                @endforelse
            </x-painel>

            <x-painel titulo="Tarefas e pendências para revisar" solto>
                <x-slot name="acoes">
                    @if ($tarefas->where('status', 'pendente')->isNotEmpty())
                        <button type="button" x-data @click="if (confirm('Aprovar as pendentes (menos sugestões e hipóteses)?')) fetch(@js(route('secretario.reunioes.aprovar-lote', $r->id)), { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } }).then(() => location.reload())"
                                class="h-[28px] rounded-control bg-brand px-2.5 text-[12px] font-semibold text-on-brand hover:bg-brand-bright">Aprovar em lote</button>
                    @endif
                </x-slot>
                @forelse ($tarefas as $t)
                    <div class="border-b border-line px-4 py-3 last:border-0" x-data="{ editando: false, texto: @js($t->texto), responsavel: @js($t->responsavel), prazo: @js($t->prazo), prioridade: @js($t->prioridade),
                            async agir(acao) { const r = await fetch(@js(route('secretario.reunioes.proposta', $t->id)), { method: 'POST',
                                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                body: JSON.stringify(Object.assign({ acao }, this.editando ? { texto: this.texto, responsavel: this.responsavel || null, prazo: this.prazo || null, prioridade: this.prioridade } : {})) });
                                if (r.ok) location.reload(); else alert('Confira os campos (prazo: AAAA-MM-DD ou AAAA-MM-DD HH:MM).'); } }">
                        <div x-show="!editando">
                            <p class="text-[14px] text-ink">{{ $t->texto }}</p>
                            <p class="mt-0.5 text-[12px] text-ink-mute">
                                {{ $t->categoria === 'pendencia' ? 'Pendência' : 'Tarefa' }} · {{ $rotuloClass[$t->classificacao] ?? $t->classificacao }}
                                · responsável: {{ $t->responsavel ?? 'não identificado' }} · prazo: {{ $t->prazo ?? 'não identificado' }} · prioridade {{ $t->prioridade }}
                                · evidência: @foreach ($t->evidencias as $n)<a href="#t-{{ $n }}" class="text-brand-text hover:underline">[{{ $n }}]</a> @endforeach
                            </p>
                        </div>
                        <div x-show="editando" x-cloak class="grid gap-2 sm:grid-cols-2">
                            <input x-model="texto" maxlength="500" class="rounded-control border border-btn-line bg-surface px-2 py-1.5 text-[13px] text-ink sm:col-span-2">
                            <input x-model="responsavel" maxlength="120" placeholder="responsável" class="rounded-control border border-btn-line bg-surface px-2 py-1.5 text-[13px] text-ink">
                            <input x-model="prazo" placeholder="prazo AAAA-MM-DD ou AAAA-MM-DD HH:MM" class="rounded-control border border-btn-line bg-surface px-2 py-1.5 text-[13px] text-ink">
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            @if ($t->status === 'pendente')
                                <button type="button" @click="agir('aprovar')" class="h-[30px] rounded-control border border-good-line px-2.5 text-[12px] font-semibold text-good hover:bg-good-tint">✓ Aprovar</button>
                                <button type="button" @click="editando = !editando" class="h-[30px] rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim hover:border-brand hover:text-brand" x-text="editando ? 'Fechar edição' : 'Editar'"></button>
                                <button type="button" @click="agir('descartar')" class="h-[30px] rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-mute hover:border-crit hover:text-crit">Descartar</button>
                            @else
                                <span class="text-[12px] {{ $t->status === 'aprovada' ? 'text-good' : 'text-ink-mute' }}">{{ $t->status === 'aprovada' ? '✓ aprovada — virou item do Secretário' : 'descartada' }}</span>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="px-4 py-3 text-[13px] text-ink-mute">Nenhuma tarefa identificada.</p>
                @endforelse
            </x-painel>

            @if ($r->proximos_passos)
                <x-painel titulo="Próximos passos"><ul class="list-disc space-y-1 pl-5 text-[13.5px] text-ink">@foreach ($r->proximos_passos as $p)<li>{{ $p }}</li>@endforeach</ul></x-painel>
            @endif
            @if ($r->roteiro)
                <x-painel titulo="Roteiro de execução"><ol class="list-decimal space-y-1 pl-5 text-[13.5px] text-ink">@foreach ($r->roteiro as $p)<li>{{ $p }}</li>@endforeach</ol></x-painel>
            @endif
        @endif

        @if ($r->trechos->isNotEmpty())
            <x-painel titulo="Transcrição" solto>
                <div class="max-h-[480px] overflow-y-auto">
                    @foreach ($r->trechos as $t)
                        <p id="t-{{ $t->ordem }}" class="flex gap-3 border-b border-line px-4 py-1.5 text-[13px] text-ink target:bg-warn-tint">
                            <span class="w-[86px] shrink-0 font-mono text-[11.5px] text-ink-faint">[{{ $t->ordem }}] {{ SecretarioReuniaoTrecho::horario($t->inicio) }}</span>
                            <span class="min-w-0 break-words">{{ $t->texto }}</span>
                        </p>
                    @endforeach
                </div>
            </x-painel>
        @endif
    </div>
</x-app-layout>
