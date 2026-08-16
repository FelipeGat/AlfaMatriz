@csrf

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div class="sm:col-span-2">
        <x-input-label for="descricao" value="Descrição" />
        <x-text-input id="descricao" name="descricao" type="text" class="mt-1 block w-full" value="{{ old('descricao', $cobranca->descricao ?? '') }}" required />
        <x-input-error :messages="$errors->get('descricao')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="tipo" value="Tipo" />
        <select id="tipo" name="tipo" class="mt-1 block w-full rounded-control border-line bg-input text-ink">
            @foreach (['locacao_sistema' => 'Locação de sistema (revenda)', 'locacao_cliente' => 'Locação de sistema (cliente)', 'direta' => 'Venda direta', 'avulsa' => 'Avulsa'] as $value => $label)
                <option value="{{ $value }}" {{ old('tipo', $cobranca->tipo ?? 'avulsa') === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <x-input-label for="valor" value="Valor (R$)" />
        <x-text-input id="valor" name="valor" type="number" step="0.01" min="0" class="mt-1 block w-full" value="{{ old('valor', $cobranca->valor ?? '') }}" required />
        <x-input-error :messages="$errors->get('valor')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="revenda_id" value="Revenda" />
        <select id="revenda_id" name="revenda_id" class="mt-1 block w-full rounded-control border-line bg-input text-ink">
            <option value="">—</option>
            @foreach ($revendas as $revenda)
                <option value="{{ $revenda->id }}" {{ (string) old('revenda_id', $cobranca->revenda_id ?? '') === (string) $revenda->id ? 'selected' : '' }}>{{ $revenda->nome }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <x-input-label for="cliente_id" value="Cliente" />
        <select id="cliente_id" name="cliente_id" class="mt-1 block w-full rounded-control border-line bg-input text-ink">
            <option value="">—</option>
            @foreach ($clientes as $cliente)
                <option value="{{ $cliente->id }}" {{ (string) old('cliente_id', $cobranca->cliente_id ?? '') === (string) $cliente->id ? 'selected' : '' }}>{{ $cliente->nome }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <x-input-label for="sistema_id" value="Sistema" />
        <select id="sistema_id" name="sistema_id" class="mt-1 block w-full rounded-control border-line bg-input text-ink">
            <option value="">—</option>
            @foreach ($sistemas as $sistema)
                <option value="{{ $sistema->id }}" {{ (string) old('sistema_id', $cobranca->sistema_id ?? '') === (string) $sistema->id ? 'selected' : '' }}>{{ $sistema->nome }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <x-input-label for="data_vencimento" value="Vencimento" />
        <x-text-input id="data_vencimento" name="data_vencimento" type="date" class="mt-1 block w-full" value="{{ old('data_vencimento', isset($cobranca) ? $cobranca->data_vencimento->toDateString() : now()->toDateString()) }}" required />
        <x-input-error :messages="$errors->get('data_vencimento')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="competencia" value="Competência (AAAA-MM)" />
        <x-text-input id="competencia" name="competencia" type="text" placeholder="2026-08" class="mt-1 block w-full" value="{{ old('competencia', $cobranca->competencia ?? now()->format('Y-m')) }}" />
    </div>

    <div>
        <x-input-label for="conta_financeira_id" value="Conta de recebimento" />
        <select id="conta_financeira_id" name="conta_financeira_id" class="mt-1 block w-full rounded-control border-line bg-input text-ink">
            <option value="">—</option>
            @foreach ($contasFinanceiras as $conta)
                <option value="{{ $conta->id }}" {{ (string) old('conta_financeira_id', $cobranca->conta_financeira_id ?? '') === (string) $conta->id ? 'selected' : '' }}>{{ $conta->nome }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <x-input-label for="forma_pagamento" value="Forma de pagamento" />
        <x-text-input id="forma_pagamento" name="forma_pagamento" type="text" class="mt-1 block w-full" value="{{ old('forma_pagamento', $cobranca->forma_pagamento ?? '') }}" />
    </div>
</div>

<div class="sticky bottom-0 -mx-5 -mb-5 mt-4 flex items-center justify-end gap-2 border-t border-line bg-panel px-5 py-3">
    @if ($emModal ?? false)
        <button type="button" x-on:click="$dispatch('close')"
                class="h-9 px-3.5 rounded-control border border-btn-line text-[12.5px] font-semibold text-ink-dim hover:text-ink transition">
            Cancelar
        </button>
    @else
        <a href="{{ route('cobrancas.index') }}"
           class="h-9 px-3.5 inline-flex items-center rounded-control border border-btn-line text-[12.5px] font-semibold text-ink-dim hover:text-ink transition">
            Cancelar
        </a>
    @endif
    <button type="submit"
            class="h-9 px-3.5 rounded-control bg-brand text-on-brand text-[12.5px] font-semibold hover:bg-brand-bright transition">
        Salvar receita
    </button>
</div>
