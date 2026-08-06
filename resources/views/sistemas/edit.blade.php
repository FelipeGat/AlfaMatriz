<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-ink leading-tight">{{ $sistema->nome }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="p-4 bg-good/12 text-good rounded-control text-sm">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="p-4 bg-bad/10 text-bad rounded-control text-sm">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="bg-panel overflow-hidden sm:rounded-card p-6">
                <h3 class="font-semibold text-ink mb-4">Configuração</h3>
                <form method="POST" action="{{ route('sistemas.update', $sistema) }}">
                    @csrf @method('PUT')

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <x-input-label for="categoria" value="Categoria" />
                            <select id="categoria" name="categoria" class="mt-1 block w-full border-line rounded-control">
                                <option value="saas" {{ old('categoria', $sistema->categoria) === 'saas' ? 'selected' : '' }}>SaaS</option>
                                <option value="crm" {{ old('categoria', $sistema->categoria) === 'crm' ? 'selected' : '' }}>CRM</option>
                            </select>
                        </div>
                        <div>
                            <x-input-label for="unidade_cobranca" value="Unidade de cobrança" />
                            <x-text-input id="unidade_cobranca" name="unidade_cobranca" type="text" class="mt-1 block w-full" value="{{ old('unidade_cobranca', $sistema->unidade_cobranca) }}" required />
                            <x-input-error :messages="$errors->get('unidade_cobranca')" class="mt-2" />
                        </div>
                        <div class="flex items-center mt-6">
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="ativo" value="1" class="rounded border-line text-brand" {{ old('ativo', $sistema->ativo) ? 'checked' : '' }}>
                                <span class="ms-2 text-sm text-dim">Sistema ativo (participa do motor de faturamento)</span>
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
                        <a href="{{ route('sistemas.index') }}" class="text-sm text-dim hover:text-ink">Voltar</a>
                        <x-primary-button>Salvar</x-primary-button>
                    </div>
                </form>
            </div>

            <div class="bg-panel overflow-hidden sm:rounded-card p-6">
                <div class="flex items-center justify-between mb-1">
                    <h3 class="font-semibold text-ink">Tiers de atacado (Alfa → Revenda)</h3>
                </div>
                <p class="text-xs text-mute mb-4">
                    Tier fechado: preço fixo até o limite de unidades, sem cobrança de excedente. Tier metrado: deixe "unidades inclusas" em 0 e preencha "valor por unidade excedente" — cobra direto por unidade ativa.
                </p>

                <div class="overflow-x-auto mb-6">
                    <table class="min-w-full divide-y divide-line">
                        <thead>
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-dim uppercase">Tier</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-dim uppercase">Revenda</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-dim uppercase">Preço base</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-dim uppercase">Unid. inclusas</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-dim uppercase">R$/excedente</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-dim uppercase">Limite</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @forelse ($sistema->precosAtacado as $tier)
                                <tr>
                                    <td class="px-3 py-2 text-sm text-ink">{{ $tier->nome }}</td>
                                    <td class="px-3 py-2 text-sm text-dim">{{ $tier->revenda->nome ?? 'Padrão (todas)' }}</td>
                                    <td class="px-3 py-2 text-sm text-ink text-right">R$ {{ number_format($tier->preco_base, 2, ',', '.') }}</td>
                                    <td class="px-3 py-2 text-sm text-dim text-right">{{ $tier->unidades_inclusas ?? '—' }}</td>
                                    <td class="px-3 py-2 text-sm text-dim text-right">{{ $tier->valor_excedente_unidade ? 'R$ '.number_format($tier->valor_excedente_unidade, 2, ',', '.') : '—' }}</td>
                                    <td class="px-3 py-2 text-sm text-dim text-right">{{ $tier->limite_unidades ?? 'ilimitado' }}</td>
                                    <td class="px-3 py-2 text-right">
                                        <form action="{{ route('precos.destroy', $tier) }}" method="POST" onsubmit="return confirm('Remover este tier?');">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-bad hover:opacity-80 text-sm">Remover</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-3 py-4 text-center text-sm text-mute">Nenhum tier cadastrado ainda.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <form action="{{ route('precos.store', $sistema) }}" method="POST" class="grid grid-cols-2 sm:grid-cols-4 gap-3 items-end">
                    @csrf
                    <div>
                        <x-input-label for="nome" value="Nome do tier" />
                        <x-text-input id="nome" name="nome" type="text" class="mt-1 block w-full text-sm" placeholder="Start" required />
                    </div>
                    <div>
                        <x-input-label for="revenda_id" value="Revenda" />
                        <select id="revenda_id" name="revenda_id" class="mt-1 block w-full border-line rounded-control text-sm">
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
    </div>
</x-app-layout>
