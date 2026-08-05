@csrf

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div class="sm:col-span-2">
        <x-input-label for="descricao" value="Descrição" />
        <x-text-input id="descricao" name="descricao" type="text" class="mt-1 block w-full" value="{{ old('descricao', $contaPagar->descricao ?? '') }}" required />
        <x-input-error :messages="$errors->get('descricao')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="tipo" value="Tipo" />
        <select id="tipo" name="tipo" class="mt-1 block w-full border-white/20 rounded-md shadow-sm">
            @foreach (['avulsa' => 'Avulsa', 'fixa' => 'Fixa/recorrente'] as $value => $label)
                <option value="{{ $value }}" {{ old('tipo', $contaPagar->tipo ?? 'avulsa') === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <x-input-label for="valor" value="Valor (R$)" />
        <x-text-input id="valor" name="valor" type="number" step="0.01" min="0" class="mt-1 block w-full" value="{{ old('valor', $contaPagar->valor ?? '') }}" required />
        <x-input-error :messages="$errors->get('valor')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="centro_custo_id" value="Centro de custo" />
        <select id="centro_custo_id" name="centro_custo_id" class="mt-1 block w-full border-white/20 rounded-md shadow-sm">
            <option value="">—</option>
            @foreach ($centrosCusto as $centro)
                <option value="{{ $centro->id }}" {{ (string) old('centro_custo_id', $contaPagar->centro_custo_id ?? '') === (string) $centro->id ? 'selected' : '' }}>{{ $centro->nome }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <x-input-label for="conta_id" value="Categoria (conta)" />
        <select id="conta_id" name="conta_id" class="mt-1 block w-full border-white/20 rounded-md shadow-sm">
            <option value="">—</option>
            @foreach ($contas as $conta)
                <option value="{{ $conta->id }}" {{ (string) old('conta_id', $contaPagar->conta_id ?? '') === (string) $conta->id ? 'selected' : '' }}>
                    {{ $conta->subcategoria->categoria->nome }} › {{ $conta->subcategoria->nome }} › {{ $conta->nome }}
                </option>
            @endforeach
        </select>
    </div>

    <div>
        <x-input-label for="fornecedor_id" value="Fornecedor" />
        <select id="fornecedor_id" name="fornecedor_id" class="mt-1 block w-full border-white/20 rounded-md shadow-sm">
            <option value="">—</option>
            @foreach ($fornecedores as $fornecedor)
                <option value="{{ $fornecedor->id }}" {{ (string) old('fornecedor_id', $contaPagar->fornecedor_id ?? '') === (string) $fornecedor->id ? 'selected' : '' }}>{{ $fornecedor->razao_social }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <x-input-label for="data_vencimento" value="Vencimento" />
        <x-text-input id="data_vencimento" name="data_vencimento" type="date" class="mt-1 block w-full" value="{{ old('data_vencimento', isset($contaPagar) ? $contaPagar->data_vencimento->toDateString() : now()->toDateString()) }}" required />
        <x-input-error :messages="$errors->get('data_vencimento')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="conta_financeira_id" value="Conta de pagamento" />
        <select id="conta_financeira_id" name="conta_financeira_id" class="mt-1 block w-full border-white/20 rounded-md shadow-sm">
            <option value="">—</option>
            @foreach ($contasFinanceiras as $conta)
                <option value="{{ $conta->id }}" {{ (string) old('conta_financeira_id', $contaPagar->conta_financeira_id ?? '') === (string) $conta->id ? 'selected' : '' }}>{{ $conta->nome }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <x-input-label for="forma_pagamento" value="Forma de pagamento" />
        <x-text-input id="forma_pagamento" name="forma_pagamento" type="text" class="mt-1 block w-full" value="{{ old('forma_pagamento', $contaPagar->forma_pagamento ?? '') }}" />
    </div>
</div>

<div class="flex items-center justify-end gap-3 mt-6">
    <a href="{{ route('contas-pagar.index') }}" class="text-sm text-ink-dim hover:text-ink">Cancelar</a>
    <x-primary-button>Salvar</x-primary-button>
</div>
