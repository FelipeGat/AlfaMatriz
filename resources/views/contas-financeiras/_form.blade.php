@csrf

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <x-input-label for="nome" value="Nome" />
        <x-text-input id="nome" name="nome" type="text" class="mt-1 block w-full" value="{{ old('nome', $contaFinanceira->nome ?? '') }}" required />
        <x-input-error :messages="$errors->get('nome')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="tipo" value="Tipo" />
        <select id="tipo" name="tipo" class="mt-1 block w-full border-line rounded-control">
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
            <input type="checkbox" name="ativo" value="1" class="rounded border-line text-brand" {{ old('ativo', $contaFinanceira->ativo ?? true) ? 'checked' : '' }}>
            <span class="ms-2 text-sm text-ink-dim">Conta ativa</span>
        </label>
    </div>
</div>

<div class="sticky bottom-0 -mx-5 -mb-5 mt-4 flex items-center justify-end gap-2 border-t border-line bg-panel px-5 py-3">
    @if ($emModal ?? false)
        <button type="button" x-on:click="$dispatch('close')"
                class="h-9 px-3.5 rounded-control border border-btn-line text-[12.5px] font-semibold text-ink-dim hover:text-ink transition">
            Cancelar
        </button>
    @else
        <a href="{{ route('contas-financeiras.index') }}"
           class="h-9 px-3.5 inline-flex items-center rounded-control border border-btn-line text-[12.5px] font-semibold text-ink-dim hover:text-ink transition">
            Cancelar
        </a>
    @endif
    <button type="submit"
            class="h-9 px-3.5 rounded-control bg-brand text-on-brand text-[12.5px] font-semibold hover:bg-brand-bright transition">
        Salvar conta
    </button>
</div>
