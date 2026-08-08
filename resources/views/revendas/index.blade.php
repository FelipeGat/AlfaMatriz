<x-app-layout>
    <x-slot name="titulo">Revendas</x-slot>
    <x-slot name="contexto">{{ $linhas->count() }} de {{ $cadastradas }} cadastradas</x-slot>
    <x-slot name="acoes">
        <button type="button" x-data @click="$dispatch('open-modal', 'nova-revenda')"
                class="h-[34px] px-3 inline-flex items-center rounded-control bg-brand text-on-brand font-semibold text-[12.5px] hover:bg-brand-bright transition whitespace-nowrap">
            + Nova revenda
        </button>
    </x-slot>

    <div class="space-y-4">
        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif

        @if (session('erro'))
            <x-aviso tom="critico">{{ session('erro') }}</x-aviso>
        @endif

        {{-- Abas: a gestão de clientes mora aqui, dentro do contexto de revenda. --}}
        <x-abas>
            <x-abas.item href="{{ route('revendas.index', array_merge(request()->query(), ['aba' => 'revendas'])) }}"
                         :ativo="($aba ?? 'revendas') === 'revendas'" icone="building">
                Revendas
            </x-abas.item>
            <x-abas.item href="{{ route('revendas.index', array_merge(request()->query(), ['aba' => 'clientes'])) }}"
                         :ativo="($aba ?? 'revendas') === 'clientes'" icone="users">
                Clientes
            </x-abas.item>
        </x-abas>

        @if (($aba ?? 'revendas') === 'clientes')
            <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr))">
                <x-kpi-card rotulo="Clientes cadastrados" :valor="number_format($clientesView['kpis']['cadastrados']['valor'], 0, ',', '.')"
                            :delta="$clientesView['kpis']['cadastrados']['nota']" acento="accent" icone="users" />
                <x-kpi-card rotulo="Em contrato" :valor="number_format($clientesView['kpis']['contrato']['valor'], 0, ',', '.')"
                            :delta="$clientesView['kpis']['contrato']['nota']" acento="brand" icone="repeat" />
                <x-kpi-card rotulo="Avulsos" :valor="number_format($clientesView['kpis']['avulsos']['valor'], 0, ',', '.')"
                            :delta="$clientesView['kpis']['avulsos']['nota']" acento="amber" icone="clipboard" />
                <x-kpi-card rotulo="Ticket médio" :valor="'R$ '.number_format($clientesView['kpis']['ticket']['valor'], 2, ',', '.')"
                            :delta="$clientesView['kpis']['ticket']['nota']" acento="good" icone="banknotes" />
            </div>

            @include('clientes._tabela', $clientesView)
        @else
        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr))">
            <x-kpi-card rotulo="Revendas ativas" :valor="$kpis['ativas']['valor']" :delta="$kpis['ativas']['nota']"
                        acento="accent" icone="building" />
            <x-kpi-card rotulo="Clientes via revenda" :valor="number_format($kpis['clientes']['valor'], 0, ',', '.')"
                        :delta="$kpis['clientes']['nota']" acento="brand" icone="users" />
            <x-kpi-card rotulo="MRR de revenda" :valor="'R$ '.number_format($kpis['mrr']['valor'], 2, ',', '.')"
                        :delta="$kpis['mrr']['nota']" acento="good" icone="trending-up" />
            <x-kpi-card rotulo="Ticket médio" :valor="'R$ '.number_format($kpis['ticket']['valor'], 2, ',', '.')"
                        :delta="$kpis['ticket']['nota']" acento="amber" icone="repeat" />
        </div>

        {{-- Filtros: sempre na query string, para o recorte ser compartilhável. --}}
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <label class="flex items-center gap-2 h-[34px] flex-1 min-w-[220px] max-w-[380px] px-3
                          rounded-control bg-input border border-line text-ink-faint focus-within:border-brand transition">
                <span class="h-4 w-4 shrink-0"><x-nav-icon name="search" /></span>
                <input type="search" name="q" value="{{ $filtros['q'] }}"
                       placeholder="Buscar revenda ou CNPJ…"
                       class="flex-1 min-w-0 bg-transparent border-0 p-0 text-[13px] text-ink placeholder-ink-faint focus:ring-0">
            </label>

            <select name="status" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="">Todas</option>
                <option value="ativo" @selected($filtros['status'] === 'ativo')>Ativas</option>
                <option value="inativo" @selected($filtros['status'] === 'inativo')>Inativas</option>
            </select>

            <select name="ordem" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="mrr" @selected($filtros['ordem'] === 'mrr')>Maior MRR</option>
                <option value="clientes" @selected($filtros['ordem'] === 'clientes')>Mais clientes</option>
                <option value="nome" @selected($filtros['ordem'] === 'nome')>Nome</option>
            </select>

            <button type="submit"
                    class="h-[34px] px-3 rounded-control border border-btn-line text-ink-dim
                           text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
                Filtrar
            </button>
        </form>

        <x-tabela min="1000px">
            <thead>
                <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                    <th class="px-4 py-2.5 font-semibold">Revenda</th>
                    <th class="px-4 py-2.5 font-semibold">Contato</th>
                    <th class="px-4 py-2.5 font-semibold">Base de clientes</th>
                    <th class="px-4 py-2.5 font-semibold">MRR / mês</th>
                    <th class="px-4 py-2.5 font-semibold">Sistemas revendidos</th>
                    <th class="px-4 py-2.5 font-semibold">Status</th>
                    <th class="px-4 py-2.5 font-semibold text-right">Ações</th>
                </tr>
            </thead>

            <tbody>
                @php $maiorBase = max($linhas->max('clientes') ?: 1, 1); @endphp

                @forelse ($linhas as $linha)
                    @php $revenda = $linha['revenda']; @endphp
                    <tr class="border-b border-rule hover:bg-chip transition {{ $revenda->ativo ? '' : 'opacity-60' }}"
                        @if ($linha['emAtraso'] > 0)
                            style="border-left: 2px solid rgb(var(--warn)); background: rgb(var(--warn) / 0.05)"
                        @endif>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <span class="h-8 w-8 shrink-0 rounded-ctl bg-brand/15 text-brand-text
                                             flex items-center justify-center font-display text-[12.5px] font-semibold">
                                    {{ Str::of($revenda->nome)->substr(0, 2)->upper() }}
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-[13.5px] font-medium text-ink truncate">{{ $revenda->nome }}</span>
                                    <span class="block font-mono text-[11.5px] text-ink-faint truncate">{{ $revenda->cnpj ?: 'sem CNPJ' }}</span>
                                </span>
                            </div>
                        </td>

                        <td class="px-4 py-3">
                            <span class="block text-[13px] text-ink-dim truncate">{{ $revenda->contato_nome ?: '—' }}</span>
                            <span class="block text-[11.5px] text-ink-faint truncate">
                                {{ $revenda->contato_email ?: $revenda->contato_telefone ?: 'sem contato' }}
                            </span>
                        </td>

                        <td class="px-4 py-3">
                            <div class="flex items-baseline gap-2">
                                <span class="font-mono text-[13.5px] text-ink tabular">{{ number_format($linha['clientes'], 0, ',', '.') }}</span>
                                <span class="font-mono text-[11px] text-ink-faint">{{ number_format($linha['share'] * 100, 0) }}% da base</span>
                            </div>
                            <span class="mt-1.5 block h-1.5 w-full max-w-[140px] rounded-badge bg-bar-track overflow-hidden">
                                <span class="block h-full rounded-badge"
                                      data-barra="{{ $revenda->nome }}"
                                      style="width: {{ round(($linha['clientes'] / $maiorBase) * 100, 2) }}%; background: rgb(var(--accent))"></span>
                            </span>
                        </td>

                        <td class="px-4 py-3">
                            <span class="block font-mono text-[13.5px] text-ink whitespace-nowrap">R$ {{ number_format($linha['mrr'], 2, ',', '.') }}</span>
                            @if ($linha['delta'])
                                <span class="block font-mono text-[11px] text-ink-faint">{{ $linha['delta'] }} vs mês anterior</span>
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            @if (empty($linha['sistemas']))
                                <span class="text-[12.5px] text-ink-faint">nenhum</span>
                            @else
                                <div class="flex flex-wrap items-center gap-1">
                                    @foreach (array_slice($linha['sistemas'], 0, 3) as $sistema)
                                        <x-badge>{{ $sistema }}</x-badge>
                                    @endforeach
                                    @if (count($linha['sistemas']) > 3)
                                        <x-badge :title="implode(', ', $linha['sistemas'])">+{{ count($linha['sistemas']) - 3 }}</x-badge>
                                    @endif
                                </div>
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            <x-badge :tom="$revenda->ativo ? 'bom' : 'neutro'" ponto>
                                {{ $revenda->ativo ? 'Ativa' : 'Inativa' }}
                            </x-badge>
                            @if ($linha['emAtraso'] > 0)
                                <span class="mt-1 block font-mono text-[10px] uppercase tracking-caps whitespace-nowrap"
                                      style="color: rgb(var(--warn))">
                                    {{ $linha['emAtraso'] }} em atraso
                                </span>
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <x-acao-tabela icone="eye" titulo="Ver clientes da revenda"
                                               :href="route('clientes.index', ['revenda' => $revenda->id])" />
                                <x-acao-tabela icone="repeat" titulo="Faturamento da revenda"
                                               :href="route('faturamento.index')" />
                                @if ($sistemaAlfaGym && ! $linha['provisionada'])
                                    <button type="button" @click="$dispatch('open-modal', 'provisionar-revenda-{{ $revenda->id }}')"
                                            class="inline-flex h-7 w-7 items-center justify-center rounded-tile text-ink-mute transition hover:text-brand hover:bg-chip"
                                            title="Provisionar no AlfaGym" aria-label="Provisionar no AlfaGym">
                                        <span class="h-[15px] w-[15px]"><x-nav-icon name="upload" /></span>
                                    </button>
                                @elseif ($linha['provisionada'])
                                    <span class="inline-flex h-7 w-7 items-center justify-center rounded-tile text-ink-faint"
                                          title="Provisionada no AlfaGym">
                                        <span class="h-[15px] w-[15px]"><x-nav-icon name="check-circle" /></span>
                                    </span>
                                @endif
                                <x-acao-tabela icone="pencil" titulo="Editar revenda"
                                               :href="route('revendas.edit', $revenda)" />
                                <x-confirmar :action="route('revendas.destroy', $revenda)" method="DELETE"
                                             icone="trash" destrutivo confirmar="Remover"
                                             :titulo="'Remover '.$revenda->nome.'?'"
                                             mensagem="A revenda sai da lista e deixa de aparecer no faturamento. Os clientes dela continuam cadastrados." />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-[13px] text-ink-mute">
                            Nenhuma revenda encontrada com esse recorte.
                        </td>
                    </tr>
                @endforelse
            </tbody>

            @if ($linhas->isNotEmpty())
                <tfoot>
                    <x-linha-total>
                        <td>Total</td>
                        <td></td>
                        <td>{{ number_format($totais['clientes'], 0, ',', '.') }} clientes</td>
                        <td>R$ {{ number_format($totais['mrr'], 2, ',', '.') }}</td>
                        <td>{{ $totais['sistemas'] }} sistemas distintos</td>
                        <td></td>
                        <td></td>
                    </x-linha-total>
                </tfoot>
            @endif

            <x-slot name="rodape">
                <span>{{ $linhas->count() }} de {{ $cadastradas }} revendas</span>
                @if ($kpis['mrr']['valor'] > 0)
                    <span>· {{ $kpis['mrr']['nota'] }} vem de revenda</span>
                @endif
            </x-slot>
        </x-tabela>
        @endif
    </div>

    <x-modal name="nova-revenda" maxWidth="lg">
        <form method="POST" action="{{ route('revendas.store') }}" class="p-5">
            <h2 class="font-display text-[15.5px] font-semibold text-ink mb-4">Nova revenda</h2>
            @include('revendas._form', ['emModal' => true])
        </form>
    </x-modal>

    @foreach ($linhas as $linha)
        @if ($sistemaAlfaGym && ! $linha['provisionada'])
            <x-modal :name="'provisionar-revenda-'.$linha['revenda']->id" maxWidth="md">
                <form method="POST" action="{{ route('revendas.provisionar', $linha['revenda']) }}" class="p-5">
                    @csrf
                    <h2 class="font-display text-[15.5px] font-semibold text-ink mb-1">
                        Provisionar {{ $linha['revenda']->nome }}
                    </h2>
                    <p class="text-[13px] text-ink-dim mb-4">
                        Cria a revenda e o usuário administrador dela no AlfaGym. Os dados abaixo são do
                        usuário ADMIN_REVENDA que acessará o painel do gym.
                    </p>

                    <div class="space-y-4">
                        <div>
                            <x-input-label for="nome-admin-{{ $linha['revenda']->id }}" value="Nome do administrador" />
                            <x-text-input id="nome-admin-{{ $linha['revenda']->id }}" name="nome_admin" type="text"
                                          class="mt-1 block w-full" required autocomplete="off" />
                            <x-input-error :messages="$errors->get('nome_admin')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="email-admin-{{ $linha['revenda']->id }}" value="E-mail do administrador" />
                            <x-text-input id="email-admin-{{ $linha['revenda']->id }}" name="email_admin" type="email"
                                          class="mt-1 block w-full" required autocomplete="off"
                                          value="{{ $linha['revenda']->contato_email }}" />
                            <x-input-error :messages="$errors->get('email_admin')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="senha-admin-{{ $linha['revenda']->id }}" value="Senha do administrador" />
                            <x-text-input id="senha-admin-{{ $linha['revenda']->id }}" name="senha_admin" type="password"
                                          class="mt-1 block w-full" required minlength="8" autocomplete="new-password" />
                            <x-input-error :messages="$errors->get('senha_admin')" class="mt-2" />
                        </div>
                    </div>

                    <div class="mt-5 flex items-center justify-end gap-2">
                        <button type="button" x-on:click="$dispatch('close')"
                                class="h-9 px-3.5 rounded-control border border-btn-line text-[12.5px] font-semibold text-ink-dim hover:text-ink transition">
                            Cancelar
                        </button>
                        <button type="submit"
                                class="h-9 px-3.5 rounded-control bg-brand text-on-brand text-[12.5px] font-semibold hover:bg-brand-bright transition">
                            Provisionar
                        </button>
                    </div>
                </form>
            </x-modal>
        @endif
    @endforeach
</x-app-layout>
