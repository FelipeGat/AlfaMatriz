<x-app-layout>
    <x-slot name="caminho">
        <x-migalhas :caminho="[['rotulo' => 'Produtos', 'rota' => route('produtos.index')]]"
                    :atual="$sistema->nome" />
    </x-slot>

    <div class=" space-y-6" style="max-width: 1000px">
            @if (session('status'))
                <x-aviso>{{ session('status') }}</x-aviso>
            @endif
            @if ($errors->any())
                <div class="p-4 bg-status-critical/10 text-status-critical rounded-md text-sm">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="bg-panel overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-ink mb-4">Configuração</h3>
                <form method="POST" action="{{ route('sistemas.update', $sistema) }}">
                    @csrf @method('PUT')

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="categoria" value="Categoria" />
                            <select id="categoria" name="categoria" class="mt-1 block w-full border-white/10 rounded-md shadow-sm">
                                <option value="saas" {{ old('categoria', $sistema->categoria) === 'saas' ? 'selected' : '' }}>SaaS</option>
                                <option value="crm" {{ old('categoria', $sistema->categoria) === 'crm' ? 'selected' : '' }}>CRM</option>
                            </select>
                        </div>
                        <div>
                            <x-input-label for="unidade_cobranca" value="Unidade de cobrança" />
                            <x-text-input id="unidade_cobranca" name="unidade_cobranca" type="text" class="mt-1 block w-full" value="{{ old('unidade_cobranca', $sistema->unidade_cobranca) }}" required />
                            <x-input-error :messages="$errors->get('unidade_cobranca')" class="mt-2" />
                        </div>
                        <div>
                            {{-- Em branco, vale o dia em que a linha foi criada —
                                 que na base importada é o dia da migração, igual
                                 para todo o catálogo. --}}
                            <x-input-label for="data_cadastro" value="No catálogo desde" />
                            <x-text-input id="data_cadastro" name="data_cadastro" type="date" class="mt-1 block w-full"
                                          value="{{ old('data_cadastro', $sistema->data_cadastro?->toDateString()) }}" />
                            <x-input-error :messages="$errors->get('data_cadastro')" class="mt-2" />
                        </div>
                        <div class="flex items-center mt-6">
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="ativo" value="1" class="rounded border-white/20 text-brand shadow-sm" {{ old('ativo', $sistema->ativo) ? 'checked' : '' }}>
                                <span class="ms-2 text-sm text-ink-dim">Sistema ativo (participa do motor de faturamento)</span>
                            </label>
                        </div>
                        <div>
                            <x-input-label for="base_url" value="Base URL da API SaaS" />
                            <x-text-input id="base_url" name="base_url" type="text" class="mt-1 block w-full" value="{{ old('base_url', $sistema->base_url) }}" placeholder="https://api.produto.com.br" />
                            <x-input-error :messages="$errors->get('base_url')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="token" value="Token de integração" />
                            <x-text-input id="token" name="token" type="password" class="mt-1 block w-full" placeholder="{{ $sistema->token ? '•••••••• (preencher para trocar)' : 'não configurado' }}" />
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 mt-6">
                        <a href="{{ route('produtos.index') }}" class="text-sm text-ink-dim hover:text-ink">Voltar</a>
                        <x-primary-button>Salvar</x-primary-button>
                    </div>
                </form>
            </div>

            <div class="bg-panel overflow-hidden shadow-sm sm:rounded-lg p-6">
                <div class="flex items-center justify-between mb-1">
                    <h3 class="font-semibold text-ink">Tiers de atacado (Alfa → Revenda)</h3>
                </div>
                <p class="text-xs text-ink-mute mb-4">
                    Tier fechado: preço fixo até o limite de unidades, sem cobrança de excedente. Tier metrado: deixe "unidades inclusas" em 0 e preencha "valor por unidade excedente" — cobra direto por unidade ativa.
                </p>

                <div class="mb-6">
                    <x-tabela min="820px">
                        <thead>
                            <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                                <th class="px-3 py-2 font-semibold">Tier</th>
                                <th class="px-3 py-2 font-semibold">Revenda</th>
                                <th class="px-3 py-2 font-semibold text-right">Preço base</th>
                                <th class="px-3 py-2 font-semibold text-right">Unid. inclusas</th>
                                <th class="px-3 py-2 font-semibold text-right">R$/excedente</th>
                                <th class="px-3 py-2 font-semibold text-right">Limite</th>
                                <th class="px-3 py-2 font-semibold text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sistema->precosAtacado as $tier)
                                <tr class="border-b border-rule last:border-0 hover:bg-chip transition">
                                    <td class="px-3 py-2.5 text-[13.5px] text-ink">{{ $tier->nome }}</td>
                                    <td class="px-3 py-2.5 text-[13px] text-ink-dim">{{ $tier->revenda->nome ?? 'Padrão (todas)' }}</td>
                                    <td class="px-3 py-2.5 text-right font-mono text-[13px] text-ink whitespace-nowrap">R$ {{ number_format($tier->preco_base, 2, ',', '.') }}</td>
                                    <td class="px-3 py-2.5 text-right font-mono text-[13px] text-ink-dim">{{ $tier->unidades_inclusas ?? '—' }}</td>
                                    <td class="px-3 py-2.5 text-right font-mono text-[13px] text-ink-dim whitespace-nowrap">{{ $tier->valor_excedente_unidade ? 'R$ '.number_format($tier->valor_excedente_unidade, 2, ',', '.') : '—' }}</td>
                                    <td class="px-3 py-2.5 text-right font-mono text-[13px] text-ink-dim whitespace-nowrap">{{ $tier->limite_unidades ?? 'ilimitado' }}</td>
                                    <td class="px-3 py-2.5">
                                        <div class="flex items-center justify-end">
                                            <x-confirmar :action="route('precos.destroy', $tier)" method="DELETE"
                                                         icone="trash" destrutivo confirmar="Remover"
                                                         titulo="Remover este tier de atacado?"
                                                         mensagem="Sem tier, as unidades deste sistema ficam de fora do faturamento das revendas até alguém configurar outro." />
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-3 py-6 text-center text-[13px] text-ink-mute">Nenhum tier cadastrado ainda.</td></tr>
                            @endforelse
                        </tbody>
                    </x-tabela>
                </div>

                <form action="{{ route('precos.store', $sistema) }}" method="POST" class="grid grid-cols-2 sm:grid-cols-4 gap-3 items-end">
                    @csrf
                    <div>
                        <x-input-label for="nome" value="Nome do tier" />
                        <x-text-input id="nome" name="nome" type="text" class="mt-1 block w-full text-sm" placeholder="Start" required />
                    </div>
                    <div>
                        <x-input-label for="revenda_id" value="Revenda" />
                        <select id="revenda_id" name="revenda_id" class="mt-1 block w-full border-white/10 rounded-md shadow-sm text-sm">
                            <option value="">Padrão (todas)</option>
                            @foreach ($revendas as $revenda)
                                <option value="{{ $revenda->id }}">{{ $revenda->nome }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="preco_base" value="Preço base (R$)" />
                        <x-text-input id="preco_base" name="preco_base" type="number" step="0.01" min="0" class="mt-1 block w-full text-sm" required />
                    </div>
                    <div>
                        <x-input-label for="unidades_inclusas" value="Unidades inclusas" />
                        <x-text-input id="unidades_inclusas" name="unidades_inclusas" type="number" min="0" class="mt-1 block w-full text-sm" placeholder="0 = metrado" />
                    </div>
                    <div>
                        <x-input-label for="valor_excedente_unidade" value="R$/unidade excedente" />
                        <x-text-input id="valor_excedente_unidade" name="valor_excedente_unidade" type="number" step="0.01" min="0" class="mt-1 block w-full text-sm" />
                    </div>
                    <div>
                        <x-input-label for="limite_unidades" value="Limite (teto do tier)" />
                        <x-text-input id="limite_unidades" name="limite_unidades" type="number" min="1" class="mt-1 block w-full text-sm" placeholder="vazio = ilimitado" />
                    </div>
                    <div>
                        <x-input-label for="vigencia_inicio" value="Vigência início" />
                        <x-text-input id="vigencia_inicio" name="vigencia_inicio" type="date" class="mt-1 block w-full text-sm" value="{{ now()->toDateString() }}" required />
                    </div>
                    <div>
                        <x-primary-button>Adicionar tier</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
</x-app-layout>
