@csrf

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <x-input-label for="nome" value="Nome" />
        <x-text-input id="nome" name="nome" type="text" class="mt-1 block w-full" value="{{ old('nome', $contaFinanceira->nome ?? '') }}" required />
        <x-input-error :messages="$errors->get('nome')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="tipo" value="Tipo" />
        <select id="tipo" name="tipo" class="mt-1 block w-full border-white/20 rounded-md shadow-sm">
            @foreach (['corrente' => 'Corrente', 'poupanca' => 'Poupança', 'cartao' => 'Cartão', 'caixa' => 'Caixa'] as $value => $label)
                <option value="{{ $value }}" {{ old('tipo', $contaFinanceira->tipo ?? 'corrente') === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <x-input-label for="banco_codigo" value="Código do banco" />
        <x-text-input id="banco_codigo" name="banco_codigo" type="text" class="mt-1 block w-full" value="{{ old('banco_codigo', $contaFinanceira->banco_codigo ?? '') }}" />
    </div>

    <div>
        <x-input-label for="agencia" value="Agência" />
        <x-text-input id="agencia" name="agencia" type="text" class="mt-1 block w-full" value="{{ old('agencia', $contaFinanceira->agencia ?? '') }}" />
    </div>

    <div>
        <x-input-label for="numero_conta" value="Número da conta" />
        <x-text-input id="numero_conta" name="numero_conta" type="text" class="mt-1 block w-full" value="{{ old('numero_conta', $contaFinanceira->numero_conta ?? '') }}" />
    </div>

    @unless (isset($contaFinanceira))
        <div>
            <x-input-label for="saldo" value="Saldo inicial" />
            <x-text-input id="saldo" name="saldo" type="number" step="0.01" class="mt-1 block w-full" value="{{ old('saldo', 0) }}" />
        </div>
    @endunless

    <div class="flex items-center mt-6">
        <label class="inline-flex items-center">
            <input type="checkbox" name="ativo" value="1" class="rounded border-white/20 text-brand shadow-sm" {{ old('ativo', $contaFinanceira->ativo ?? true) ? 'checked' : '' }}>
            <span class="ms-2 text-sm text-ink-dim">Conta ativa</span>
        </label>
    </div>
</div>

<div class="flex items-center justify-end gap-3 mt-6">
    <a href="{{ route('contas-financeiras.index') }}" class="text-sm text-ink-dim hover:text-ink">Cancelar</a>
    <x-primary-button>Salvar</x-primary-button>
</div>
