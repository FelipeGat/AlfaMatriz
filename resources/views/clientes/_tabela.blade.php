{{-- Lista de clientes reutilizável: usada em clientes/index e na aba de
    clientes da tela de revendas. Espera: $clientes, $pagamentos, $revendas,
    $sistemas, $filtros, $totais. --}}

<form method="GET" class="flex flex-wrap items-center gap-2">
    {{-- Sem isto, filtrar dentro da aba de clientes submete para /revendas sem
         `aba` e devolve o usuário para a aba de revendas — a busca o expulsava
         da tela onde ele estava. Só a aba injeta `$aba`; a tela de Clientes
         não, então o campo não aparece lá. --}}
    @isset($aba)
        <input type="hidden" name="aba" value="{{ $aba }}">
    @endisset
    <label class="flex items-center gap-2 h-[34px] flex-1 min-w-[220px] max-w-[360px] px-3
                  rounded-control bg-input border border-line text-ink-faint focus-within:border-brand transition">
        <span class="h-4 w-4 shrink-0"><x-nav-icon name="search" /></span>
        <input type="search" name="busca" value="{{ $filtros['busca'] }}"
               placeholder="Buscar nome, CNPJ ou cidade…"
               class="flex-1 min-w-0 bg-transparent border-0 p-0 text-[13px] text-ink placeholder-ink-faint focus:ring-0">
    </label>

    <select name="revenda" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
        <option value="">Todas as revendas</option>
        @foreach ($revendas as $revenda)
            <option value="{{ $revenda->id }}" @selected((string) $filtros['revenda'] === (string) $revenda->id)>{{ $revenda->nome }}</option>
        @endforeach
    </select>

    <select name="sistema" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
        <option value="">Todos os sistemas</option>
        @foreach ($sistemas as $sistema)
            <option value="{{ $sistema->id }}" @selected((string) $filtros['sistema'] === (string) $sistema->id)>{{ $sistema->nome }}</option>
        @endforeach
    </select>

    <select name="status" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
        <option value="">Todos</option>
        <option value="ativo" @selected($filtros['status'] === 'ativo')>Ativos</option>
        <option value="inativo" @selected($filtros['status'] === 'inativo')>Inativos</option>
    </select>

    <button type="submit"
            class="h-[34px] px-3 rounded-control border border-btn-line text-ink-dim
                   text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
        Filtrar
    </button>

    <span class="ml-auto flex items-center gap-1.5 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
        <span class="inline-block h-3 w-[2px]" style="background: rgb(var(--warn))"></span>
        cobrança em atraso
    </span>
</form>

<x-tabela min="1060px" class="tabela-zebrada">
    <thead>
        <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
            <th class="px-4 py-2.5 font-semibold">Cliente</th>
            <th class="px-4 py-2.5 font-semibold">Revenda / praça</th>
            <th class="px-4 py-2.5 font-semibold">Sistemas</th>
            <th class="px-4 py-2.5 font-semibold">Licença</th>
            <th class="px-4 py-2.5 font-semibold">Cobrança</th>
            <th class="px-4 py-2.5 font-semibold">Pagamento</th>
            <th class="px-4 py-2.5 font-semibold">Status</th>
            <th class="px-4 py-2.5 font-semibold text-right">Ações</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($clientes as $cliente)
            @php
                $pagamento = $pagamentos[$cliente->id] ?? ['estado' => 'sem_cobranca', 'dias' => 0];
                $emContrato = $cliente->tipo_cliente === 'CONTRATO';
                $pendente = collect($cliente->sistemas)
                    ->first(fn ($s) => ($s->pivot->status_saas ?? '') === 'pendente');
            @endphp

            <tr class="border-b border-rule hover:bg-chip transition {{ $cliente->ativo ? '' : 'opacity-[0.62]' }}"
                @if ($pagamento['estado'] === 'atrasado')
                    style="border-left: 2px solid rgb(var(--warn)); background: rgb(var(--warn) / 0.05)"
                @endif>
                <td class="px-4 py-3">
                    <span class="block min-w-0">
                        <span class="block text-[13.5px] font-medium text-ink truncate">{{ $cliente->nome_exibicao }}</span>
                        <span class="block font-mono text-[11.5px] text-ink-faint truncate">{{ $cliente->cpf_cnpj ?: 'sem documento' }}</span>
                    </span>
                </td>

                <td class="px-4 py-3">
                    @if ($cliente->revenda)
                        <span class="block text-[13px] text-ink-dim truncate">{{ $cliente->revenda->nome }}</span>
                    @else
                        <span class="block text-[13px] text-warn truncate">Sem revenda</span>
                    @endif
                    <span class="block font-mono text-[11px] uppercase tracking-caps text-ink-faint truncate">
                        {{ $cliente->cidade ? $cliente->cidade.'/'.$cliente->uf : 'sem praça' }}
                    </span>
                </td>

                <td class="px-4 py-3">
                    @php
                        // Um cliente pode ter licença em mais de um sistema, com
                        // estados diferentes. Mostrar só o primeiro escondia
                        // justamente o que precisa de ação.
                        $ativos = $cliente->sistemas->where('pivot.ativo', true);
                        $licenciados = $ativos->filter(fn ($s) => $s->pivot->estado() !== 'sem_licenca')
                            ->sortBy(fn ($s) => $s->pivot->gravidade())
                            ->values();
                        $semLicenca = $ativos->filter(fn ($s) => $s->pivot->estado() === 'sem_licenca')->values();
                        // Corte em duas linhas: a terceira já disputa altura com
                        // as outras colunas da tabela.
                        $mostrados = $licenciados->take(2);
                        $ocultos = $licenciados->count() - $mostrados->count();
                    @endphp

                    @if ($ativos->isEmpty())
                        <span class="text-[12.5px] text-ink-faint">nenhum</span>
                    @else
                        @foreach ($mostrados as $sistema)
                            @php $vinculo = $sistema->pivot; @endphp
                            <div class="flex items-center gap-1.5 {{ $loop->first ? '' : 'mt-1' }}">
                                <span class="h-3.5 w-3.5 shrink-0"><x-marca-sistema :sistema="$sistema" /></span>
                                <x-badge :tom="$vinculo->tom()" ponto>{{ $vinculo->rotulo() }}</x-badge>
                                <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint truncate">
                                    {{ $sistema->nome }}
                                    @if ($vinculo->plano)
                                        · {{ $vinculo->plano }}
                                    @endif
                                    @if ($vinculo->fimEm())
                                        · até {{ $vinculo->fimEm()->format('d/m/Y') }}
                                    @endif
                                    @php
                                        $modulosDoSistema = $cliente->modulosContratados
                                            ->filter(fn ($c) => $c->status === 'ativo'
                                                && $c->modulo?->sistema_id === $sistema->id);
                                    @endphp
                                    @if ($modulosDoSistema->isNotEmpty())
                                        · <span title="{{ $modulosDoSistema->map(fn ($c) => $c->modulo->codigo)->implode(', ') }}">{{ $modulosDoSistema->count() }} módulos</span>
                                    @endif
                                </span>
                            </div>
                        @endforeach

                        @if ($ocultos > 0 || $semLicenca->isNotEmpty())
                            <div class="mt-1 flex flex-wrap items-center gap-1">
                                @if ($ocultos > 0)
                                    <x-badge :title="$licenciados->skip(2)->pluck('nome')->implode(', ')">+{{ $ocultos }}</x-badge>
                                @endif
                                @foreach ($semLicenca->take(2) as $sistema)
                                    <x-badge>{{ $sistema->nome }}</x-badge>
                                @endforeach
                                @if ($semLicenca->count() > 2)
                                    <x-badge :title="$semLicenca->pluck('nome')->implode(', ')">+{{ $semLicenca->count() - 2 }}</x-badge>
                                @endif
                            </div>
                        @endif
                    @endif
                </td>

                {{-- Valor da licença no sistema de origem. Coluna própria, e não
                     misturada com "Cobrança": aquela é o acordo comercial da Alfa
                     com o cliente, esta é o preço registrado lá. --}}
                <td class="px-4 py-3">
                    @php
                        $comValor = $ativos->filter(fn ($s) => $s->pivot->valorDaLicenca() !== null);
                        $totalLicenca = $comValor->sum(fn ($s) => $s->pivot->valorDaLicenca());
                    @endphp
                    @if ($comValor->isEmpty())
                        <span class="font-sans tabular text-[12.5px] text-ink-faint">—</span>
                    @else
                        <span class="block font-sans tabular text-[13px] text-ink whitespace-nowrap"
                              title="{{ $comValor->map(fn ($s) => $s->nome.': R$ '.number_format($s->pivot->valorDaLicenca(), 2, ',', '.'))->implode(' · ') }}">
                            R$ {{ number_format($totalLicenca, 2, ',', '.') }}
                        </span>
                        @if ($comValor->count() > 1)
                            <span class="block font-mono text-[11px] uppercase tracking-caps text-ink-faint whitespace-nowrap">
                                {{ $comValor->count() }} sistemas
                            </span>
                        @endif
                    @endif
                </td>

                <td class="px-4 py-3">
                    <span class="block font-sans tabular text-[13px] text-ink whitespace-nowrap">
                        R$ {{ number_format($cliente->valor_mensal ?? 0, 2, ',', '.') }}
                    </span>
                    <span class="block font-mono text-[11px] uppercase tracking-caps text-ink-faint whitespace-nowrap">
                        {{ $emContrato ? 'contrato · dia '.($cliente->dia_vencimento ?: '—') : 'avulso' }}
                    </span>
                </td>

                <td class="px-4 py-3">
                    @switch ($pagamento['estado'])
                        @case('atrasado')
                            <x-badge tom="critico" ponto>Atrasado {{ $pagamento['dias'] }}d</x-badge>
                            @break
                        @case('em_dia')
                            <x-badge tom="bom" ponto>Em dia</x-badge>
                            @break
                        @default
                            <x-badge>Sem cobrança</x-badge>
                    @endswitch
                </td>

                <td class="px-4 py-3">
                    <x-badge :tom="$cliente->ativo ? 'bom' : 'neutro'" ponto>
                        {{ $cliente->ativo ? 'Ativo' : 'Inativo' }}
                    </x-badge>
                </td>

                <td class="px-4 py-3">
                    @php
                        // Os sistemas cuja licença a Matriz gerencia. Durante a
                        // implantação de um sistema novo ele não entra aqui: a
                        // operação continua no painel dele.
                        $gerenciaveis = collect($cliente->sistemas)
                            ->filter(fn ($s) => $s->suporta('gerencia_licenca')
                                && ($s->pivot->status_saas ?? '') !== '')
                            ->sortBy(fn ($s) => $s->pivot->gravidade())
                            ->values();
                        // Com um só, o menu fica idêntico ao de sempre; o
                        // cabeçalho por sistema só aparece quando há ambiguidade.
                        $agrupar = $gerenciaveis->count() > 1;
                    @endphp

                    <div class="flex justify-end">
                        <x-dropdown width="48" contentClasses="py-1 bg-panel">
                            <x-slot name="trigger">
                                <button type="button"
                                        class="inline-flex h-7 w-7 items-center justify-center rounded-tile
                                               text-ink-mute transition hover:text-brand hover:bg-chip"
                                        title="Ações de {{ $cliente->nome_exibicao }}"
                                        aria-label="Ações de {{ $cliente->nome_exibicao }}">
                                    <span class="h-[15px] w-[15px]"><x-nav-icon name="dots-vertical" /></span>
                                </button>
                            </x-slot>

                            <x-slot name="content">
                                <div class="py-1">
                                    {{-- Licença é decisão da Alfa. A revenda
                                         acompanha o estado do cliente, mas não
                                         libera, renova nem suspende. --}}
                                    @php $decideLicenca = ! auth()->user()->temEscopoDeRevenda(); @endphp

                                    @if ($decideLicenca)
                                        @foreach ($gerenciaveis as $sistemaLicenca)
                                            @php $vinculo = $sistemaLicenca->pivot; @endphp

                                            {{-- O rótulo do item não muda ("Renovar
                                                 licença", não "Renovar licença do
                                                 AlfaGym"): quem nomeia o sistema é
                                                 o cabeçalho do grupo. --}}
                                            @if ($agrupar)
                                                <div class="px-3 pt-2 pb-1 font-mono text-[10px] uppercase tracking-caps text-ink-faint">
                                                    {{ $sistemaLicenca->nome }}
                                                </div>
                                            @endif

                                            @if ($vinculo->pendente())
                                                <button type="button" x-data
                                                        @click="$dispatch('open-modal', 'liberar-licenca-{{ $cliente->id }}-{{ $sistemaLicenca->id }}')"
                                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-[12.5px] font-semibold text-brand hover:bg-chip transition">
                                                    <span class="h-3.5 w-3.5 shrink-0"><x-nav-icon name="check-circle" /></span>
                                                    Liberar licença
                                                </button>
                                            @endif

                                            @if ($vinculo->temLicenca())
                                                <button type="button" x-data
                                                        @click="$dispatch('open-modal', 'renovar-licenca-{{ $cliente->id }}-{{ $sistemaLicenca->id }}')"
                                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-[12.5px] text-ink-dim hover:text-brand hover:bg-chip transition">
                                                    <span class="h-3.5 w-3.5 shrink-0"><x-nav-icon name="repeat" /></span>
                                                    Renovar licença
                                                </button>

                                                @if ($vinculo->suspensa())
                                                    <x-confirmar :action="route('clientes.desbloquearLicenca', [$cliente, $sistemaLicenca])"
                                                                 method="POST" confirmar="Reativar"
                                                                 :titulo="'Reativar '.$cliente->nome_exibicao.'?'"
                                                                 :mensagem="'O acesso do cliente no '.$sistemaLicenca->nome.' volta a funcionar.'">
                                                        <span class="flex w-full items-center gap-2 px-3 py-2 text-left text-[12.5px] text-ink-dim hover:text-brand hover:bg-chip transition">
                                                            <span class="h-3.5 w-3.5 shrink-0"><x-nav-icon name="play" /></span>
                                                            Reativar licença
                                                        </span>
                                                    </x-confirmar>
                                                @else
                                                    <x-confirmar :action="route('clientes.bloquearLicenca', [$cliente, $sistemaLicenca])"
                                                                 method="POST" confirmar="Suspender"
                                                                 :titulo="'Suspender '.$cliente->nome_exibicao.'?'"
                                                                 :mensagem="'O acesso do cliente no '.$sistemaLicenca->nome.' é interrompido até o reativar.'">
                                                        <span class="flex w-full items-center gap-2 px-3 py-2 text-left text-[12.5px] text-ink-dim hover:text-brand hover:bg-chip transition">
                                                            <span class="h-3.5 w-3.5 shrink-0"><x-nav-icon name="pause" /></span>
                                                            Suspender licença
                                                        </span>
                                                    </x-confirmar>
                                                @endif
                                            @endif
                                        @endforeach
                                    @endif

                                    @if ($decideLicenca && $gerenciaveis->isNotEmpty())
                                        <div class="my-1 border-t border-line"></div>
                                    @endif

                                    <a href="{{ route('cobrancas.index', ['cliente' => $cliente->id]) }}"
                                       class="flex w-full items-center gap-2 px-3 py-2 text-left text-[12.5px] text-ink-dim hover:text-brand hover:bg-chip transition">
                                        <span class="h-3.5 w-3.5 shrink-0"><x-nav-icon name="paperclip" /></span>
                                        Cobranças do cliente
                                    </a>
                                    <a href="{{ route('clientes.edit', $cliente) }}"
                                       class="flex w-full items-center gap-2 px-3 py-2 text-left text-[12.5px] text-ink-dim hover:text-brand hover:bg-chip transition">
                                        <span class="h-3.5 w-3.5 shrink-0"><x-nav-icon name="pencil" /></span>
                                        Editar cliente
                                    </a>

                                    <div class="my-1 border-t border-line"></div>

                                    <x-confirmar :action="route('clientes.destroy', $cliente)" method="DELETE"
                                                 confirmar="Remover" destrutivo
                                                 :titulo="'Remover '.$cliente->nome_exibicao.'?'"
                                                 mensagem="O cliente sai da lista. As cobranças já emitidas para ele continuam no financeiro.">
                                        <span class="flex w-full items-center gap-2 px-3 py-2 text-left text-[12.5px] text-crit hover:bg-crit-tint transition">
                                            <span class="h-3.5 w-3.5 shrink-0"><x-nav-icon name="trash" /></span>
                                            Remover cliente
                                        </span>
                                    </x-confirmar>
                                </div>
                            </x-slot>
                        </x-dropdown>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="8" class="px-4 py-8 text-center text-[13px] text-ink-mute">
                    Nenhum cliente encontrado com esse recorte.
                </td>
            </tr>
        @endforelse
    </tbody>

    @if ($clientes->isNotEmpty())
        <tfoot>
            <x-linha-total>
                <td>Nesta página</td>
                <td>{{ $totais['contratos'] }} contratos · {{ $totais['avulsos'] }} avulsos</td>
                <td></td>
                <td>R$ {{ number_format($totais['licencas'], 2, ',', '.') }}</td>
                <td>R$ {{ number_format($totais['mensal'], 2, ',', '.') }}</td>
                <td>{{ $totais['atrasados'] }} em atraso</td>
                <td></td>
                <td></td>
            </x-linha-total>
        </tfoot>
    @endif

    <x-slot name="rodape">
        <span>{{ $clientes->count() }} de {{ $clientes->total() }} clientes</span>
        <span>· página {{ $clientes->currentPage() }} de {{ $clientes->lastPage() }}</span>
        {{ $clientes->links() }}
    </x-slot>
</x-tabela>

{{-- Os modais de licença só existem para quem decide sobre licença: renderizar
     para a revenda deixaria os formulários de liberar/renovar na página dela. --}}

@include('clientes._licenca-modais')
