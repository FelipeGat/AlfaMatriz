{{--
    A aba Erros (#224): o que o vigia de logs (#219) agrupou, por sistema.
    Espera: $filtros, $sistemas, $porSistema, $cortou, $semana, $regras,
    $cobertos, $regraDoErro, $kpis.
--}}
@php
    $podeCalibrar = auth()->user()->canPermissao('manutencao', 'editar');
    $ambientes = \App\Services\Vigia\VigiaDeLogs::AMBIENTES;
@endphp

<div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr))">
    <x-kpi-card rotulo="Erros vigiados" :valor="$kpis['vigiados']" acento="crit" icone="alert-triangle" />
    <x-kpi-card rotulo="Ocorrências em 24h" :valor="number_format($kpis['ocorrencias24h'], 0, ',', '.')"
                delta="ignorados entram na conta" acento="amber" icone="clock" />
    <x-kpi-card rotulo="Picos na semana" :valor="$kpis['picos']"
                delta="avisados no Telegram" acento="warn" icone="trending-up" />
    <x-kpi-card rotulo="Ignorados" :valor="$kpis['ignorados']"
                delta="contados, sem tarefa nem aviso" acento="accent" icone="eye-slash" />
</div>

<form method="GET" class="flex flex-wrap items-center gap-2">
    <input type="hidden" name="aba" value="erros">

    <select name="sistema" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
        <option value="">Todos os sistemas</option>
        @foreach ($sistemas as $sistema)
            <option value="{{ $sistema->id }}" @selected($filtros['sistema'] === $sistema->id)>{{ $sistema->nome }}</option>
        @endforeach
    </select>

    <select name="ambiente" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
        <option value="">Produção e staging</option>
        @foreach ($ambientes as $chave => $rotulo)
            <option value="{{ $chave }}" @selected($filtros['ambiente'] === $chave)>{{ ucfirst($rotulo) }}</option>
        @endforeach
    </select>

    <select name="situacao" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
        <option value="vigiados" @selected($filtros['situacao'] === 'vigiados')>Vigiados</option>
        <option value="ignorados" @selected($filtros['situacao'] === 'ignorados')>Ignorados</option>
        <option value="todos" @selected($filtros['situacao'] === 'todos')>Vigiados e ignorados</option>
    </select>

    <button type="submit"
            class="h-[34px] px-3 rounded-control border border-btn-line text-ink-dim
                   text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
        Filtrar
    </button>
</form>

@forelse ($porSistema as $grupo)
    @php
        $doSistema = $grupo['erros'];
        $vezesNaSemana = $doSistema->sum(fn ($e) => $semana[$e->id]['total']);
    @endphp

    <x-tabela min="1000px" class="tabela-zebrada" :titulo="$grupo['sistema']->nome"
              :sub="$doSistema->count().' '.($doSistema->count() === 1 ? 'erro' : 'erros').' · '.number_format($vezesNaSemana, 0, ',', '.').' na semana'">
        <thead>
            <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                <th class="px-4 py-2.5 font-semibold">Erro</th>
                <th class="px-4 py-2.5 font-semibold text-right">Vezes</th>
                <th class="px-4 py-2.5 font-semibold">Primeira vez</th>
                <th class="px-4 py-2.5 font-semibold">Última vez</th>
                <th class="px-4 py-2.5 font-semibold">Semana</th>
                <th class="px-4 py-2.5 font-semibold">Tarefa</th>
                @if ($podeCalibrar)
                    <th class="px-4 py-2.5 font-semibold text-right">Ações</th>
                @endif
            </tr>
        </thead>

        <tbody>
            @forelse ($doSistema as $erro)
                @php
                    $daSemana = $semana[$erro->id];
                    $picoAvisado = $erro->pico_avisado_em && $erro->pico_avisado_em->gte(now()->subDays(\App\Http\Controllers\ManutencaoController::DIAS_DA_SEMANA));
                    $regra = $regraDoErro[$erro->id] ?? null;
                @endphp

                {{-- O ignorado fica apagado como a conta desativada: continua na
                     lista (é contado), mas não disputa o olho com o que pede conserto. --}}
                <tr class="border-b border-rule hover:bg-chip transition align-top {{ $erro->ignorado ? 'opacity-[0.62]' : '' }}">
                    <td class="px-4 py-3 max-w-[420px]">
                        <p class="text-[13px] font-medium text-ink truncate" title="{{ $erro->mensagem }}">
                            {{ Str::of($erro->mensagem)->before("\n")->limit(160) }}
                        </p>
                        <div class="mt-1 flex flex-wrap items-center gap-1.5">
                            <x-badge :tom="$erro->ambiente === 'staging' ? 'ambar' : 'neutro'">{{ $ambientes[$erro->ambiente] ?? $erro->ambiente }}</x-badge>
                            @if ($erro->ignorado)
                                <x-badge>ignorado</x-badge>
                            @endif
                            @if ($erro->excecao)
                                <span class="font-mono text-[11px] text-ink-faint truncate" title="{{ $erro->excecao }}">
                                    {{ class_basename(str_replace('.', '\\', $erro->excecao)) }}
                                </span>
                            @endif
                        </div>
                    </td>

                    <td class="px-4 py-3 text-right">
                        <p class="font-sans tabular text-[13.5px] font-semibold text-ink">{{ number_format($erro->total, 0, ',', '.') }}</p>
                        <p class="font-sans tabular text-[11px] text-ink-faint whitespace-nowrap">{{ number_format($daSemana['total'], 0, ',', '.') }} na semana</p>
                    </td>

                    <td class="px-4 py-3 font-sans tabular text-[12.5px] text-ink-dim whitespace-nowrap">
                        {{ $erro->primeira_vez->format('d/m/Y H:i') }}
                    </td>

                    <td class="px-4 py-3 whitespace-nowrap">
                        <p class="font-sans tabular text-[12.5px] text-ink-dim">{{ $erro->ultima_vez->format('d/m/Y H:i') }}</p>
                        <p class="text-[11px] text-ink-faint">{{ $erro->ultima_vez->diffForHumans() }}</p>
                    </td>

                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <x-sparkline :pontos="$daSemana['dias']" :cor="$picoAvisado ? 'crit' : 'accent'"
                                         titulo="Ocorrências por dia nos últimos 7 dias" />
                            @if ($picoAvisado)
                                <x-badge tom="critico" title="Aviso de pico em {{ $erro->pico_avisado_em->format('d/m H:i') }}">pico</x-badge>
                            @endif
                        </div>
                        @if ($daSemana['maior'])
                            <p class="mt-1 font-sans tabular text-[11px] text-ink-faint whitespace-nowrap">
                                hora mais cheia: {{ $daSemana['maior']['total'] }} às {{ $daSemana['maior']['hora']->format('d/m H\h') }}
                            </p>
                        @endif
                    </td>

                    <td class="px-4 py-3">
                        @if ($erro->tarefa)
                            @php $tarefa = $erro->tarefa; @endphp
                            <a href="{{ route('tarefas.index', ['tarefa' => $tarefa->id]) }}"
                               class="font-mono text-[12px] font-semibold text-brand-text hover:underline">{{ $tarefa->codigo() }}</a>
                            <p class="mt-0.5">
                                @if ($tarefa->trashed())
                                    <x-badge tom="critico">excluída</x-badge>
                                @else
                                    <x-badge :tom="match ($tarefa->status) { 'concluida' => 'bom', 'cancelada' => 'neutro', default => 'marca' }">
                                        {{ \App\Models\Tarefa::rotuloDaEtapa($tarefa->status) }}
                                    </x-badge>
                                @endif
                            </p>
                        @else
                            <span class="text-[12.5px] text-ink-faint">—</span>
                        @endif
                    </td>

                    @if ($podeCalibrar)
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                @if (! $erro->ignorado)
                                    <x-confirmar :action="route('manutencao.erros.ignorar', $erro)"
                                                 icone="eye-slash"
                                                 titulo="Ignorar este erro no {{ $grupo['sistema']->nome }}?"
                                                 mensagem="Ele continua contado, mas deixa de abrir tarefa e de avisar no Telegram. Dá para desfazer pela lista do que o vigia ignora, logo abaixo."
                                                 confirmar="Ignorar" />
                                @elseif ($regra)
                                    {{-- A regra pode calar mais de um erro (uma
                                         regex, um padrão global): a pergunta diz
                                         quantos, porque é isso que se desfaz. --}}
                                    <x-confirmar :action="route('manutencao.ignorados.destroy', $regra)"
                                                 method="DELETE"
                                                 icone="arrow-uturn-left"
                                                 titulo="Voltar a vigiar este erro?"
                                                 mensagem="Sai da lista a regra «{{ $regra->padrao }}» ({{ $regra->sistema?->nome ?? 'todos os sistemas' }}), que cala {{ $cobertos[$regra->id] ?? 1 }} {{ ($cobertos[$regra->id] ?? 1) === 1 ? 'erro' : 'erros' }}. Se o erro aparecer de novo, ele abre tarefa."
                                                 confirmar="Voltar a vigiar" />
                                @endif
                            </div>
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $podeCalibrar ? 7 : 6 }}" class="px-4 py-6 text-center text-[13px] text-ink-mute">
                        @if ($filtros['situacao'] === 'ignorados')
                            Nada ignorado neste sistema.
                        @else
                            Nenhum erro {{ $filtros['situacao'] === 'vigiados' ? 'vigiado' : '' }} neste recorte — o vigia está ligado e não trouxe nada.
                        @endif
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-tabela>
@empty
    <div class="rounded-panel border border-line bg-surface px-5 py-8 text-center">
        <p class="text-[13px] font-medium text-ink">Nenhum sistema manda erros ao vigia ainda.</p>
        <p class="mt-1 text-[12.5px] text-ink-mute">
            O vigia é ligado em cada servidor com o token do sistema (<span class="font-mono">alfa:vigia-token</span>) e o script de <span class="font-mono">deploy/vigia-logs/</span>.
        </p>
    </div>
@endforelse

@if ($cortou)
    <p class="text-[12px] text-ink-mute">
        A lista mostra os {{ \App\Http\Controllers\ManutencaoController::LIMITE_DE_ERROS }} erros vistos por último. Use os filtros para chegar aos outros.
    </p>
@endif

{{-- A lista do que o vigia ignora: a mesma do `alfa:vigia-ignorar`. Aparece
     mesmo vazia para quem pode calibrar, porque é onde se acrescenta. --}}
@if ($podeCalibrar || $regras->isNotEmpty())
    <x-tabela min="760px" titulo="O que o vigia ignora" sub="continua contado · não abre tarefa nem avisa">
        <thead>
            <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                <th class="px-4 py-2.5 font-semibold">Padrão</th>
                <th class="px-4 py-2.5 font-semibold">Tipo</th>
                <th class="px-4 py-2.5 font-semibold">Sistema</th>
                <th class="px-4 py-2.5 font-semibold text-right">Erros calados</th>
                @if ($podeCalibrar)
                    <th class="px-4 py-2.5 font-semibold text-right">Ações</th>
                @endif
            </tr>
        </thead>

        <tbody>
            @forelse ($regras as $regra)
                <tr class="border-b border-rule hover:bg-chip transition">
                    <td class="px-4 py-3 max-w-[420px]">
                        <p class="font-mono text-[12px] text-ink truncate" title="{{ $regra->padrao }}">{{ $regra->padrao }}</p>
                    </td>
                    <td class="px-4 py-3"><x-badge>{{ $regra->ehRegex() ? 'regex' : 'texto' }}</x-badge></td>
                    <td class="px-4 py-3 text-[13px] text-ink-dim">{{ $regra->sistema?->nome ?? 'Todos' }}</td>
                    <td class="px-4 py-3 text-right font-sans tabular text-[13px] text-ink-dim">{{ $cobertos[$regra->id] ?? 0 }}</td>
                    @if ($podeCalibrar)
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <x-confirmar :action="route('manutencao.ignorados.destroy', $regra)"
                                             method="DELETE"
                                             icone="arrow-uturn-left"
                                             titulo="Voltar a vigiar «{{ Str::limit($regra->padrao, 60) }}»?"
                                             mensagem="A regra sai da lista e o vigia volta a acompanhar os {{ $cobertos[$regra->id] ?? 0 }} erros que ela calava. Erro que reaparecer sem tarefa em curso abre uma."
                                             confirmar="Voltar a vigiar" />
                            </div>
                        </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $podeCalibrar ? 5 : 4 }}" class="px-4 py-6 text-center text-[13px] text-ink-mute">
                        O vigia não ignora nada.
                    </td>
                </tr>
            @endforelse
        </tbody>

        @if ($podeCalibrar)
            <x-slot name="rodape">
                <form method="POST" action="{{ route('manutencao.ignorados.store') }}"
                      class="flex flex-1 flex-wrap items-center gap-2 normal-case tracking-normal font-sans">
                    @csrf
                    <input type="text" name="padrao" value="{{ old('padrao') }}" maxlength="500" required
                           placeholder="Texto ou /regex/ a ignorar"
                           class="h-[34px] flex-1 min-w-[220px] max-w-[420px] px-3 rounded-control bg-input border border-line
                                  font-mono text-[12.5px] text-ink placeholder-ink-faint focus:border-brand focus:ring-0">
                    <select name="sistema_id" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                        <option value="">Todos os sistemas</option>
                        @foreach ($sistemas as $sistema)
                            <option value="{{ $sistema->id }}" @selected((string) old('sistema_id') === (string) $sistema->id)>{{ $sistema->nome }}</option>
                        @endforeach
                    </select>
                    <button type="submit"
                            class="h-[34px] px-3 rounded-control border border-btn-line text-ink-dim
                                   text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
                        Ignorar
                    </button>
                </form>
            </x-slot>
        @endif
    </x-tabela>
@endif
