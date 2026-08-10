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
                    @php $ativos = $cliente->sistemas->where('pivot.ativo', true); @endphp
                    @if ($ativos->isEmpty())
                        <span class="text-[12.5px] text-ink-faint">nenhum</span>
                    @else
                        <div class="flex flex-wrap items-center gap-1">
                            @foreach ($ativos->take(3) as $sistema)
                                <x-badge>{{ $sistema->nome }}</x-badge>
                            @endforeach
                            @if ($ativos->count() > 3)
                                <x-badge :title="$ativos->pluck('nome')->implode(', ')">+{{ $ativos->count() - 3 }}</x-badge>
                            @endif
                        </div>
                        @foreach ($ativos->take(1) as $sistema)
                            @if ($sistema->pivot->licenca_status)
                                @php
                                    $fim = $sistema->pivot->licenca_fim_em ? \Carbon\Carbon::parse($sistema->pivot->licenca_fim_em)->endOfDay() : null;
                                    $hoje = \Carbon\Carbon::now()->endOfDay();
                                    // O estado real vem do gym: `status_saas` (ativo/bloqueado/pendente).
                                    // `bloqueia_acesso` é a POLÍTICA da licença (bloquear ao vencer), sempre
                                    // verdadeira — usá-la aqui marcaria todo mundo como bloqueado.
                                    $bloqueada = ($sistema->pivot->status_saas ?? '') === 'bloqueado';
                                    $vencida = $fim && $fim->lt($hoje);
                                    $vencendo = $fim && ! $vencida && $fim->lte($hoje->copy()->addDays(15));
                                    $tom = $bloqueada ? 'critico' : ($vencida ? 'atencao' : ($vencendo ? 'atencao' : 'bom'));
                                @endphp
                                <div class="mt-1 flex flex-wrap items-center gap-1">
                                    <x-badge :tom="$tom" ponto>
                                        {{ $bloqueada ? 'suspensa' : ($vencida ? 'vencida' : ($vencendo ? 'vencendo' : 'ativa')) }}
                                    </x-badge>
                                    <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint truncate">
                                        {{ $sistema->nome }} · {{ $sistema->pivot->plano ?? '—' }}
                                        @if ($fim)
                                            · até {{ $fim->format('d/m/Y') }}
                                        @endif
                                    </span>
                                </div>
                            @endif
                        @endforeach
                    @endif
                </td>

                <td class="px-4 py-3">
                    <span class="block font-mono text-[13px] text-ink whitespace-nowrap">
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
                        // O sistema licenciável deste cliente: quem a Matriz
                        // gerencia e de quem já se tem retrato de licença.
                        $sistemaLicenca = collect($cliente->sistemas)
                            ->first(fn ($s) => ($s->pivot->status_saas ?? '') !== '' && $s->suporta('gerencia_licenca'));
                        $temLicenca = ! is_null($sistemaLicenca) && filled($sistemaLicenca->pivot->licenca_id_externo ?? null);
                        $bloqueada = ($sistemaLicenca->pivot->status_saas ?? '') === 'bloqueado';
                    @endphp

                    <div class="flex justify-end">
                        <x-dropdown width="48" contentClasses="py-1 bg-panel ring-1 ring-line">
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

                                    @if ($decideLicenca && $pendente)
                                        <button type="button" x-data
                                                @click="$dispatch('open-modal', 'liberar-licenca-{{ $cliente->id }}')"
                                                class="flex w-full items-center gap-2 px-3 py-2 text-left text-[12.5px] font-semibold text-brand hover:bg-chip transition">
                                            <span class="h-3.5 w-3.5 shrink-0"><x-nav-icon name="check-circle" /></span>
                                            Liberar licença
                                        </button>
                                    @endif

                                    @if ($decideLicenca && $temLicenca)
                                        <button type="button" x-data
                                                @click="$dispatch('open-modal', 'renovar-licenca-{{ $cliente->id }}')"
                                                class="flex w-full items-center gap-2 px-3 py-2 text-left text-[12.5px] text-ink-dim hover:text-brand hover:bg-chip transition">
                                            <span class="h-3.5 w-3.5 shrink-0"><x-nav-icon name="repeat" /></span>
                                            Renovar licença
                                        </button>

                                        @if ($bloqueada)
                                            <x-confirmar :action="route('clientes.desbloquearLicenca', [$cliente, $sistemaLicenca])"
                                                         method="POST" confirmar="Reativar"
                                                         :titulo="'Reativar '.$cliente->nome_exibicao.'?'"
                                                         mensagem="O acesso do cliente no AlfaGym volta a funcionar.">
                                                <span class="flex w-full items-center gap-2 px-3 py-2 text-left text-[12.5px] text-ink-dim hover:text-brand hover:bg-chip transition">
                                                    <span class="h-3.5 w-3.5 shrink-0"><x-nav-icon name="play" /></span>
                                                    Reativar licença
                                                </span>
                                            </x-confirmar>
                                        @else
                                            <x-confirmar :action="route('clientes.bloquearLicenca', [$cliente, $sistemaLicenca])"
                                                         method="POST" confirmar="Suspender"
                                                         :titulo="'Suspender '.$cliente->nome_exibicao.'?'"
                                                         mensagem="O acesso do cliente no AlfaGym é interrompido até o reativar.">
                                                <span class="flex w-full items-center gap-2 px-3 py-2 text-left text-[12.5px] text-ink-dim hover:text-brand hover:bg-chip transition">
                                                    <span class="h-3.5 w-3.5 shrink-0"><x-nav-icon name="pause" /></span>
                                                    Suspender licença
                                                </span>
                                            </x-confirmar>
                                        @endif
                                    @endif

                                    @if ($decideLicenca && ($pendente || $temLicenca))
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
                <td colspan="7" class="px-4 py-8 text-center text-[13px] text-ink-mute">
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
    </x-slot>
</x-tabela>

@if ($clientes->hasPages())
    <div>{{ $clientes->links() }}</div>
@endif

{{-- Os modais de licença só existem para quem decide sobre licença: renderizar
     para a revenda deixaria os formulários de liberar/renovar na página dela. --}}
@unless (auth()->user()->temEscopoDeRevenda())
@foreach ($clientes as $cliente)
    @php
        // Só os sistemas que a Matriz gerencia entram aqui. Um cliente que só
        // usa sistema de leitura (o AlfaControl durante a implantação) não tem
        // modal de licença — e, sem este filtro, a rota era montada sem sistema
        // e derrubava a tela inteira.
        $sistemaLicencaModal = collect($cliente->sistemas)
            ->first(fn ($s) => $s->suporta('gerencia_licenca'));
        $pendenteModal = $sistemaLicencaModal
            && ($sistemaLicencaModal->pivot->status_saas ?? '') === 'pendente';
        $temLicencaModal = $sistemaLicencaModal
            && filled($sistemaLicencaModal->pivot->licenca_id_externo ?? null);
    @endphp

    @if ($pendenteModal)
        <x-modal name="liberar-licenca-{{ $cliente->id }}" maxWidth="sm">
            <form method="POST" action="{{ route('clientes.liberarLicenca', [$cliente, $sistemaLicencaModal]) }}" class="p-5">
                @csrf
                <h2 class="font-display text-[15.5px] font-semibold text-ink mb-1">Liberar licença</h2>
                <p class="text-[12.5px] text-ink-faint mb-4">
                    {{ $cliente->nome_exibicao }} — a revenda solicitou; a liberação é feita no AlfaGym.
                </p>

                @if ($errors->has('licenca'))
                    <div class="mb-3 rounded-md border border-crit/30 bg-crit-tint px-3 py-2 text-[12.5px] text-crit">
                        {{ $errors->first('licenca') }}
                    </div>
                @endif

                <div class="space-y-4">
                    <div>
                        <x-input-label for="tipo-{{ $cliente->id }}" value="Tipo de licença" />
                        <select id="tipo-{{ $cliente->id }}" name="tipo" class="mt-1 block w-full border-white/10 rounded-md shadow-sm" required>
                            <option value="mensal" @selected(old('tipo') === 'mensal')>Mensal</option>
                            <option value="anual" @selected(old('tipo') === 'anual')>Anual</option>
                        </select>
                        <x-input-error :messages="$errors->get('tipo')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="valor-{{ $cliente->id }}" value="Valor (R$)" />
                        <x-text-input id="valor-{{ $cliente->id }}" name="valor" type="number" step="0.01" min="0"
                                      :value="old('valor', '')" class="mt-1 block w-full" placeholder="0,00" />
                        <x-input-error :messages="$errors->get('valor')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="obs-{{ $cliente->id }}" value="Observação" />
                        <textarea id="obs-{{ $cliente->id }}" name="obs" rows="2"
                                  class="mt-1 block w-full rounded-md border-white/10 bg-white/5 text-[13px] text-ink"
                                  placeholder="Contrato, proposta…">{{ old('obs') }}</textarea>
                        <x-input-error :messages="$errors->get('obs')" class="mt-2" />
                    </div>
                </div>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" x-on:click="show = false"
                            class="h-[34px] px-3 rounded-control border border-btn-line text-ink-dim text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="h-[34px] px-3 inline-flex items-center rounded-control bg-brand text-on-brand font-semibold text-[12.5px] hover:bg-brand-bright transition">
                        Liberar licença
                    </button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($temLicencaModal)
        <x-modal name="renovar-licenca-{{ $cliente->id }}" maxWidth="sm">
            <form method="POST" action="{{ route('clientes.renovarLicenca', [$cliente, $sistemaLicencaModal]) }}" class="p-5">
                @csrf
                <h2 class="font-display text-[15.5px] font-semibold text-ink mb-1">Renovar licença</h2>
                <p class="text-[12.5px] text-ink-faint mb-4">
                    {{ $cliente->nome_exibicao }} — um novo período (mensal/anual) é emitido no AlfaGym.
                </p>

                @if ($errors->has('licenca'))
                    <div class="mb-3 rounded-md border border-crit/30 bg-crit-tint px-3 py-2 text-[12.5px] text-crit">
                        {{ $errors->first('licenca') }}
                    </div>
                @endif

                <div class="space-y-4">
                    <div>
                        <x-input-label for="tipo-ren-{{ $cliente->id }}" value="Tipo de renovação" />
                        <select id="tipo-ren-{{ $cliente->id }}" name="tipo" class="mt-1 block w-full border-white/10 rounded-md shadow-sm" required>
                            <option value="mensal" @selected(old('tipo') === 'mensal')>Mensal</option>
                            <option value="anual" @selected(old('tipo') === 'anual')>Anual</option>
                        </select>
                        <x-input-error :messages="$errors->get('tipo')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="valor-ren-{{ $cliente->id }}" value="Valor (R$)" />
                        <x-text-input id="valor-ren-{{ $cliente->id }}" name="valor" type="number" step="0.01" min="0"
                                      :value="old('valor', '')" class="mt-1 block w-full" placeholder="0,00" />
                        <x-input-error :messages="$errors->get('valor')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="obs-ren-{{ $cliente->id }}" value="Observação" />
                        <textarea id="obs-ren-{{ $cliente->id }}" name="obs" rows="2"
                                  class="mt-1 block w-full rounded-md border-white/10 bg-white/5 text-[13px] text-ink"
                                  placeholder="Renovação de contrato…">{{ old('obs') }}</textarea>
                        <x-input-error :messages="$errors->get('obs')" class="mt-2" />
                    </div>
                </div>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" x-on:click="show = false"
                            class="h-[34px] px-3 rounded-control border border-btn-line text-ink-dim text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="h-[34px] px-3 inline-flex items-center rounded-control bg-brand text-on-brand font-semibold text-[12.5px] hover:bg-brand-bright transition">
                        Renovar licença
                    </button>
                </div>
            </form>
        </x-modal>
    @endif
@endforeach
@endunless
