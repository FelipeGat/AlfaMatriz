{{-- Regras dos avisos de acompanhamento (Etapa 7), em Configurações desde a
     Etapa 8. Mesmo PUT de antes; recebe `$config` (Regras::de). --}}
@php
    $campo = 'rounded-control border border-btn-line bg-surface px-2 py-1.5 text-[13px] text-ink focus:border-brand focus:outline-none';
@endphp
        <div id="avisos" class="scroll-mt-20">
<x-painel titulo="Avisos de acompanhamento">
            <form method="POST" action="{{ route('secretario.acompanhamentos.regras') }}" class="space-y-4">
                @csrf @method('PUT')
                <div class="divide-y divide-line rounded-ctl border border-line">
                    @foreach (\App\Services\Secretario\Acompanhamento\Regras::REGRAS as $chave => [$rotulo, $unidade, , $min, $max])
                        @php $r = $config['regras'][$chave]; @endphp
                        <div class="flex flex-col gap-2 px-3 py-2.5 sm:flex-row sm:items-center">
                            <label class="flex min-w-0 flex-1 items-center gap-2 text-[13.5px] text-ink">
                                <input type="hidden" name="regras[{{ $chave }}][ligada]" value="0">
                                <input type="checkbox" name="regras[{{ $chave }}][ligada]" value="1" @checked(old("regras.$chave.ligada", $r['ligada'])) class="rounded border-btn-line text-brand focus:ring-brand">
                                {{ $rotulo }}
                            </label>
                            <div class="flex items-center gap-2 text-[12.5px] text-ink-mute">
                                <input type="number" name="regras[{{ $chave }}][valor]" min="{{ $min }}" max="{{ $max }}" value="{{ old("regras.$chave.valor", $r['valor']) }}" class="{{ $campo }} w-[70px]">
                                <span class="whitespace-nowrap">{{ $unidade }}</span>
                                <select name="regras[{{ $chave }}][prioridade]" class="{{ $campo }}">
                                    @foreach (['alta', 'normal', 'baixa'] as $p)
                                        <option value="{{ $p }}" @selected(old("regras.$chave.prioridade", $r['prioridade']) === $p)>{{ $p }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        @error("regras.$chave.valor")<p class="px-3 pb-2 text-[12px] text-crit">{{ $message }}</p>@enderror
                    @endforeach
                </div>

                <div class="grid grid-cols-1 gap-3 text-[13px] text-ink sm:grid-cols-2">
                    <label class="flex items-center justify-between gap-2">Silêncio das
                        <span class="flex items-center gap-1.5"><input type="time" name="silencio_inicio" value="{{ old('silencio_inicio', $config['silencio_inicio']) }}" class="{{ $campo }}"> às
                        <input type="time" name="silencio_fim" value="{{ old('silencio_fim', $config['silencio_fim']) }}" class="{{ $campo }}"></span></label>
                    <label class="flex items-center gap-2">
                        <input type="hidden" name="fim_de_semana" value="0">
                        <input type="checkbox" name="fim_de_semana" value="1" @checked(old('fim_de_semana', $config['fim_de_semana'])) class="rounded border-btn-line text-brand focus:ring-brand">
                        Fim de semana em silêncio
                    </label>
                    @foreach (['intervalo_h' => [1, 24], 'teto_dia' => [1, 12], 'repetir_h' => [4, 168], 'repeticoes' => [1, 10]] as $k => [$min, $max])
                        <label class="flex items-center justify-between gap-2">{{ \App\Services\Secretario\Acompanhamento\Regras::POLITICA[$k][0] }}
                            <input type="number" name="{{ $k }}" min="{{ $min }}" max="{{ $max }}" value="{{ old($k, $config[$k]) }}" class="{{ $campo }} w-[70px]"></label>
                        @error($k)<p class="text-[12px] text-crit sm:col-span-2">{{ $message }}</p>@enderror
                    @endforeach
                </div>
                <p class="text-[12px] text-ink-mute">Alta sai na hora (fora do silêncio); normal espera o intervalo entre resumos; baixa nunca sai, fica aqui e no briefing.</p>
                <button class="h-[30px] rounded-control bg-brand px-4 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright">Salvar regras</button>
            </form>
        </x-painel>
</div>

{{-- Histórico dos resumos enviados (estava em Acompanhamentos até a Etapa 8). --}}
<x-painel titulo="Avisos enviados (7 dias)" solto>
    @forelse ($envios as $e)
        <p class="border-b border-line px-4 py-2 text-[12.5px] text-ink-dim last:border-0">
            {{ $e->created_at->format('d/m H:i') }} · {{ $e->canal === 'sino' ? 'Sino' : 'Telegram' }} · {{ $e->quantos }} situação(ões) ·
            <span class="{{ $e->status === 'falhou' ? 'text-crit' : ($e->status === 'entregue' ? 'text-good' : 'text-ink-mute') }}">{{ ['pendente' => 'na fila', 'enviando' => 'enviando', 'entregue' => 'entregue', 'falhou' => 'não chegou'][$e->status] ?? $e->status }}</span>
        </p>
    @empty
        <p class="px-4 py-3 text-[13px] text-ink-mute">Nenhum resumo enviado nos últimos 7 dias.</p>
    @endforelse
</x-painel>
