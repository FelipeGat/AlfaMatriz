<x-app-layout>
    <x-slot name="titulo">Acompanhamentos</x-slot>
    <x-slot name="contexto"><span>por regra, sem IA · só o que pede ação</span></x-slot>

    {{-- Etapa 7 (docs/specs/secretario-acompanhamento). A lista é o que o motor
         achou na última conferência (a cada 5 min); o resumo no sino e no
         Telegram segue a política lá embaixo. --}}
    @php
        $rotulosPrioridade = ['alta' => 'Alta prioridade', 'normal' => 'Prioridade normal', 'baixa' => 'Baixa (só aqui e no briefing)'];
        $campo = 'rounded-control border border-btn-line bg-surface px-2 py-1.5 text-[13px] text-ink focus:border-brand focus:outline-none';
        $botao = 'h-[30px] rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim hover:border-brand hover:text-brand';
    @endphp
    <div class="mx-auto w-full max-w-3xl space-y-4">
        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif
        @if ($silencio)
            <p class="rounded-panel border border-line bg-subtle px-4 py-2.5 text-[13px] text-ink-mute">
                Em silêncio agora ({{ $config['fim_de_semana'] && now()->isWeekend() ? 'fim de semana' : 'das '.$config['silencio_inicio'].' às '.$config['silencio_fim'] }}): os resumos esperam. A lista abaixo continua valendo.
            </p>
        @endif

        @forelse ($ativos as $prioridade => $lista)
            <x-painel :titulo="$rotulosPrioridade[$prioridade].' ('.$lista->count().')'" solto>
                <ul class="divide-y divide-line">
                    @foreach ($lista as $a)
                        <li class="px-4 py-3">
                            <p class="text-[13.5px] text-ink break-words">
                                {{ $a->marca() }}
                                @if ($a->link)
                                    <a href="{{ $a->link }}" @if (! str_starts_with($a->link, url('/'))) target="_blank" rel="noopener noreferrer" @endif class="hover:text-brand hover:underline">{{ $a->texto }}</a>
                                @else
                                    {{ $a->texto }}
                                @endif
                            </p>
                            <p class="text-[11.5px] text-ink-mute">
                                {{ \App\Services\Secretario\Acompanhamento\Regras::REGRAS[$a->regra][0] ?? $a->regra }}
                                · desde {{ $a->created_at->format('d/m H:i') }}
                                @if ($a->avisos) · avisado {{ $a->avisos }}× @endif
                                @if ($a->adiado()) · <span class="text-warn">adiado até {{ $a->adiado_ate->format('d/m H:i') }}</span> @endif
                            </p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <form method="POST" action="{{ route('secretario.acompanhamentos.resolver', $a->id) }}">@csrf
                                    <button class="{{ $botao }}">✓ Resolvido</button>
                                </form>
                                @unless ($a->adiado())
                                    <form method="POST" action="{{ route('secretario.acompanhamentos.adiar', $a->id) }}">@csrf
                                        <button class="{{ $botao }}">Adiar 1 dia</button>
                                    </form>
                                @endunless
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-painel>
        @empty
            <p class="rounded-panel border border-line bg-subtle px-4 py-4 text-[13.5px] text-ink-mute">Nada pedindo acompanhamento agora.</p>
        @endforelse
        @if ($dispensados)
            <p class="text-[12px] text-ink-mute">{{ $dispensados }} situação(ões) marcada(s) como resolvida(s) e ainda valendo: não voltam a avisar.</p>
        @endif

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

        <x-painel titulo="Regras">
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
                <button class="h-[36px] rounded-control bg-brand px-4 text-[13px] font-semibold text-on-brand hover:bg-brand-bright">Salvar regras</button>
            </form>
        </x-painel>
    </div>
</x-app-layout>
