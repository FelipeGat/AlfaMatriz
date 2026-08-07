<x-app-layout>
    <x-slot name="titulo">Contratos e uso</x-slot>
    <x-slot name="contexto">{{ $totais['clientes'] }} clientes ativos nos sistemas</x-slot>

    {{--
        Duas fontes, e só uma delas é externa.

        O que o cliente paga vem do AlfaMatriz (o contrato dele mora aqui). O
        quanto ele usa vem do sistema. Pedir dinheiro aos cinco sistemas seria
        manter cinco verdades sobre a mesma coisa, e na primeira divergência
        ninguém saberia qual acreditar.

        A coluna que importa é a diferença entre as duas: cliente ativo lá
        dentro sem valor contratado aqui é uso que ninguém está cobrando.
    --}}
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <select name="sistema" class="h-8 rounded-control border-line bg-input text-[12px] text-ink">
                    <option value="">Todos os sistemas</option>
                    @foreach ($sistemas as $sistema)
                        <option value="{{ $sistema->id }}" @selected(($filtros['sistema'] ?? null) == $sistema->id)>{{ $sistema->nome }}</option>
                    @endforeach
                </select>

                <select name="revenda" class="h-8 rounded-control border-line bg-input text-[12px] text-ink">
                    <option value="">Todas as revendas</option>
                    @foreach (\App\Models\Revenda::orderBy('nome')->get() as $revenda)
                        <option value="{{ $revenda->id }}" @selected(($filtros['revenda'] ?? null) == $revenda->id)>{{ $revenda->nome }}</option>
                    @endforeach
                </select>

                <button type="submit"
                        class="h-8 px-3 rounded-control border border-line bg-input text-[12px] text-ink-dim hover:text-ink transition">
                    Filtrar
                </button>
            </form>

            <a href="{{ route('integracao.contratos.exportar', $filtros) }}"
               class="h-8 px-3 inline-flex items-center gap-1.5 rounded-control border border-line bg-input text-[12px] text-ink-dim hover:text-ink transition">
                <span class="h-3.5 w-3.5"><x-nav-icon name="download" /></span>
                Exportar
            </a>

            <div class="ml-auto">
                <x-atualizado-em :em="$atualizadoEm" vazio="nunca sincronizado" />
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-kpi-card rotulo="Contratado por mês"
                        :valor="'R$ '.number_format($totais['contratado'], 2, ',', '.')"
                        delta="soma do cadastro da matriz" icone="peso" />
            <x-kpi-card rotulo="Unidades em uso"
                        :valor="number_format($totais['unidades'], 0, ',', '.')"
                        delta="o que os sistemas informam" icone="cube" />
            <x-kpi-card rotulo="Ativo sem contrato"
                        :valor="number_format($totais['sem_contrato'], 0, ',', '.')"
                        :delta="$totais['sem_contrato'] > 0 ? 'em uso e sem valor cadastrado' : 'todos com valor'"
                        :sinal="$totais['sem_contrato'] > 0 ? 'ruim' : 'bom'"
                        acento="warn" icone="alert-triangle" />
            <x-kpi-card rotulo="Sem vínculo na matriz"
                        :valor="number_format($totais['sem_vinculo'], 0, ',', '.')"
                        :delta="$totais['sem_vinculo'] > 0 ? 'não correspondem a cliente nenhum' : 'todos vinculados'"
                        :sinal="$totais['sem_vinculo'] > 0 ? 'ruim' : 'bom'"
                        acento="crit" icone="users" />
        </div>

        @forelse ($porRevenda as $nomeRevenda => $bloco)
            <x-painel :titulo="$nomeRevenda"
                      :sub="'R$ '.number_format($bloco['contratado'], 2, ',', '.').' · '.$bloco['clientes'].' clientes · '.$bloco['unidades'].' unidades'"
                      solto>
                <x-tabela min="1000px">
                    <thead>
                        <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                            <th class="px-4 py-2.5 font-semibold">Cliente</th>
                            <th class="px-4 py-2.5 font-semibold">Documento</th>
                            <th class="px-4 py-2.5 font-semibold">Contrato</th>
                            <th class="px-4 py-2.5 font-semibold">Situação no sistema</th>
                            <th class="px-4 py-2.5 font-semibold text-right">Unidades em uso</th>
                            <th class="px-4 py-2.5 font-semibold text-right">Valor mensal</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($bloco['porSistema'] as $nomeSistema => $doSistema)
                            <tr class="bg-chip border-b border-line">
                                <td colspan="4" class="px-4 py-2 font-mono text-[10.5px] uppercase tracking-caps text-ink-dim">
                                    {{ $nomeSistema }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono text-[11px] text-ink-dim tabular">
                                    {{ number_format($doSistema->sum('unidades'), 0, ',', '.') }}
                                </td>
                                <td class="px-4 py-2 text-right font-mono text-[11px] text-ink-dim tabular whitespace-nowrap">
                                    R$ {{ number_format($doSistema->sum('valor_mensal'), 2, ',', '.') }}
                                </td>
                            </tr>

                            @foreach ($doSistema as $linha)
                                <tr class="border-b border-line last:border-0 hover:bg-chip transition">
                                    <td class="px-4 py-2.5 pl-8">
                                        <p class="text-[13px] text-ink">{{ $linha['nome_no_sistema'] }}</p>
                                        @if ($linha['cliente'])
                                            <a href="{{ route('clientes.edit', $linha['cliente']) }}"
                                               class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint hover:text-brand-text transition">
                                                {{ $linha['nome_na_matriz'] }}
                                            </a>
                                        @else
                                            <span class="font-mono text-[10px] uppercase tracking-caps text-crit">sem vínculo na matriz</span>
                                        @endif
                                    </td>

                                    <td class="px-4 py-2.5 font-mono text-[12px] text-ink-dim tabular whitespace-nowrap">
                                        {{ \App\Services\Integracao\Documento::formatar($linha['documento']) ?? '—' }}
                                    </td>

                                    <td class="px-4 py-2.5">
                                        @if ($linha['tipo_contrato'])
                                            <x-badge tom="neutro">{{ strtolower($linha['tipo_contrato']) }}</x-badge>
                                        @else
                                            <span class="text-[12px] text-ink-faint">—</span>
                                        @endif
                                    </td>

                                    <td class="px-4 py-2.5">
                                        <x-badge :tom="$linha['status'] === 'ativo' ? 'bom' : 'atencao'">{{ $linha['status'] }}</x-badge>
                                    </td>

                                    <td class="px-4 py-2.5 text-right font-mono text-[12px] text-ink tabular">
                                        {{ number_format($linha['unidades'], 0, ',', '.') }}
                                    </td>

                                    {{--
                                        Ativo lá dentro e sem valor aqui: está
                                        sendo usado e não está sendo cobrado de
                                        ninguém. É o caso que a tela existe para
                                        fazer aparecer.
                                    --}}
                                    <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                        @if ($linha['sem_vinculo'])
                                            <x-badge tom="critico">sem vínculo</x-badge>
                                        @elseif ($linha['sem_contrato'])
                                            <x-badge tom="atencao">sem valor contratado</x-badge>
                                        @else
                                            <span class="font-mono text-[12px] text-ink tabular">
                                                R$ {{ number_format($linha['valor_mensal'], 2, ',', '.') }}
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach

                        <x-linha-total>
                            <td class="px-4 py-2.5" colspan="4">Total {{ $nomeRevenda }}</td>
                            <td class="px-4 py-2.5 text-right tabular">{{ number_format($bloco['unidades'], 0, ',', '.') }}</td>
                            <td class="px-4 py-2.5 text-right tabular">R$ {{ number_format($bloco['contratado'], 2, ',', '.') }}</td>
                        </x-linha-total>
                    </tbody>
                </x-tabela>
            </x-painel>
        @empty
            <x-painel>
                <p class="text-[13px] text-ink-dim">
                    Nenhum cliente ativo no retrato local. A sincronização com os sistemas ainda não
                    trouxe dado — ou nenhum cliente está ativo lá dentro.
                </p>
            </x-painel>
        @endforelse
    </div>
</x-app-layout>
