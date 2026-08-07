<x-app-layout>
    <x-slot name="titulo">Divergências</x-slot>
    <x-slot name="contexto">{{ $competencia }} · {{ $total }} {{ $total === 1 ? 'caso' : 'casos' }}</x-slot>

    {{--
        Esta é a tela que justifica a integração existir. Sem ela, o painel só
        mostraria dois números lado a lado e deixaria a conferência para a
        cabeça de quem olha.

        Regra que atravessa todos os blocos: APONTAR O CASO, não só o total. Um
        total divergente sem o caso não ajuda ninguém a agir — e uma tela que
        não ajuda a agir passa a ser ignorada em duas semanas.
    --}}
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <form method="GET" class="flex items-center gap-2">
                <select name="competencia" onchange="this.form.submit()"
                        class="h-8 rounded-control border-line bg-input text-[12px] text-ink">
                    @foreach ($competenciasRecentes as $opcao)
                        <option value="{{ $opcao }}" @selected($competencia === $opcao)>{{ $opcao }}</option>
                    @endforeach
                </select>
            </form>

            @if ($total === 0)
                <x-badge tom="bom" ponto>nada divergindo nesta competência</x-badge>
            @else
                <x-badge tom="critico" ponto>{{ $total }} {{ $total === 1 ? 'caso a tratar' : 'casos a tratar' }}</x-badge>
            @endif
        </div>

        {{-- 1. O caso mais caro: existe lá, não é cobrado por ninguém aqui. --}}
        <x-painel titulo="Cliente ativo no sistema sem vínculo na matriz"
                  :sub="$blocos['sem_vinculo']->count().' '.($blocos['sem_vinculo']->count() === 1 ? 'caso' : 'casos')">
            <p class="text-[12px] text-ink-dim">
                Está ativo dentro do sistema e não corresponde a nenhum cliente daqui — então não
                entra em nenhuma cobrança. É o caso mais caro de todos, porque some sem deixar
                rastro no financeiro.
            </p>

            @if ($blocos['sem_vinculo']->isEmpty())
                <p class="mt-3 font-mono text-[10.5px] uppercase tracking-caps text-good">nenhum</p>
            @else
                <div class="mt-3 space-y-1.5">
                    @foreach ($blocos['sem_vinculo'] as $registro)
                        <div class="flex flex-wrap items-center gap-3 rounded-tile border border-line bg-input px-3 py-2">
                            <span class="h-5 w-5 shrink-0"><x-marca-sistema :sistema="$registro->sistema" /></span>
                            <span class="text-[13px] text-ink flex-1 min-w-[180px]">{{ $registro->nome }}</span>
                            <span class="font-mono text-[11px] text-ink-faint tabular">
                                {{ \App\Services\Integracao\Documento::formatar($registro->cpf_cnpj) ?? 'sem documento' }}
                            </span>
                            <a href="{{ route('integracao.conferencia', ['sistema' => $registro->sistema_id]) }}"
                               class="h-7 px-2.5 inline-flex items-center rounded-control border border-line text-[12px] text-ink-dim hover:text-ink transition">
                                Conferir
                            </a>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-painel>

        {{-- 2. O inverso: a Alfa cobra por algo que o sistema não reconhece. --}}
        <x-painel titulo="Ativo na matriz, mas não no sistema"
                  :sub="$blocos['ativos_so_na_matriz']->count().' '.($blocos['ativos_so_na_matriz']->count() === 1 ? 'caso' : 'casos')">
            <p class="text-[12px] text-ink-dim">
                A matriz considera o cliente ativo neste sistema, mas lá dentro ele está inativo,
                bloqueado ou não existe. Aqui a Alfa está cobrando por algo que o sistema não
                reconhece mais.
            </p>

            @if ($blocos['ativos_so_na_matriz']->isEmpty())
                <p class="mt-3 font-mono text-[10.5px] uppercase tracking-caps text-good">nenhum</p>
            @else
                <div class="mt-3 space-y-1.5">
                    @foreach ($blocos['ativos_so_na_matriz'] as $linha)
                        <div class="flex flex-wrap items-center gap-3 rounded-tile border border-line bg-input px-3 py-2">
                            <span class="h-5 w-5 shrink-0"><x-marca-sistema :sistema="$linha['sistema']" /></span>
                            <a href="{{ route('clientes.edit', $linha['cliente']->id) }}"
                               class="text-[13px] text-ink hover:text-brand-text transition flex-1 min-w-[180px]">
                                {{ $linha['cliente']->nome }}
                            </a>
                            <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                                {{ $linha['sistema']->nome }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-painel>

        {{-- 3. A comparação de contagem, contra a apuração do faturamento. --}}
        <x-painel titulo="Unidades contadas x unidades faturadas"
                  :sub="$blocos['unidades']->count().' '.($blocos['unidades']->count() === 1 ? 'revenda' : 'revendas')"
                  solto>
            @if ($blocos['unidades']->isEmpty())
                <div class="px-4 py-4">
                    <p class="text-[12px] text-ink-dim">
                        O que os sistemas contam bate com o que a Alfa faturou nesta competência.
                    </p>
                </div>
            @else
                <x-tabela min="820px">
                    <thead>
                        <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                            <th class="px-4 py-2.5 font-semibold">Sistema</th>
                            <th class="px-4 py-2.5 font-semibold">Revenda</th>
                            <th class="px-4 py-2.5 font-semibold text-right">O sistema diz</th>
                            <th class="px-4 py-2.5 font-semibold text-right">A Alfa faturou</th>
                            <th class="px-4 py-2.5 font-semibold text-right">Diferença</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($blocos['unidades'] as $linha)
                            <tr class="border-b border-line last:border-0">
                                <td class="px-4 py-2.5 text-[12px] text-ink-dim">{{ $linha['sistema']?->nome }}</td>
                                <td class="px-4 py-2.5 text-[13px] text-ink">{{ $linha['revenda']?->nome }}</td>
                                <td class="px-4 py-2.5 text-right font-mono text-[12px] text-ink tabular">{{ $linha['no_sistema'] }}</td>
                                <td class="px-4 py-2.5 text-right font-mono text-[12px] text-ink tabular">{{ $linha['faturado'] }}</td>
                                <td class="px-4 py-2.5 text-right">
                                    <x-badge :tom="$linha['diferenca'] > 0 ? 'critico' : 'atencao'">
                                        {{ $linha['diferenca'] > 0 ? '+' : '' }}{{ $linha['diferenca'] }}
                                        {{ $linha['diferenca'] > 0 ? 'não cobrado' : 'cobrado a mais' }}
                                    </x-badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-tabela>
            @endif
        </x-painel>

        {{-- 4. Uso sem contrato: o valor vive na matriz, e ele está em branco. --}}
        <x-painel titulo="Ativo no sistema, sem valor contratado"
                  :sub="$blocos['sem_contrato']->count().' '.($blocos['sem_contrato']->count() === 1 ? 'cliente' : 'clientes')">
            <p class="text-[12px] text-ink-dim">
                O cliente está ativo dentro do sistema, corresponde a um cliente da matriz, e o
                valor mensal dele está em branco aqui. Está sendo usado e não está sendo cobrado de
                ninguém — e como nenhum sistema informa dinheiro, a ausência aqui é a ausência de
                verdade, não uma falha de sincronização.
            </p>

            @if ($blocos['sem_contrato']->isEmpty())
                <p class="mt-3 font-mono text-[10.5px] uppercase tracking-caps text-good">nenhum</p>
            @else
                <div class="mt-3 space-y-1.5">
                    @foreach ($blocos['sem_contrato'] as $linha)
                        <div class="flex flex-wrap items-center gap-3 rounded-tile border border-line bg-input px-3 py-2">
                            <span class="h-5 w-5 shrink-0"><x-marca-sistema :sistema="$linha['sistema']" /></span>
                            <a href="{{ route('clientes.edit', $linha['cliente']) }}"
                               class="text-[13px] text-ink hover:text-brand-text transition flex-1 min-w-[180px]">
                                {{ $linha['cliente']->nome }}
                            </a>
                            <span class="font-mono text-[11px] text-ink-faint tabular">
                                {{ $linha['unidades'] }} {{ $linha['unidades'] === 1 ? 'unidade' : 'unidades' }} em uso
                            </span>
                            <x-badge tom="atencao">sem valor mensal</x-badge>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-painel>
    </div>
</x-app-layout>
