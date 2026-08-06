@csrf

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <x-input-label for="nome" value="Nome" />
        <x-text-input id="nome" name="nome" type="text" class="mt-1 block w-full" value="{{ old('nome', $revenda->nome ?? '') }}" required />
        <x-input-error :messages="$errors->get('nome')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="cnpj" value="CNPJ" />
        <x-text-input id="cnpj" name="cnpj" type="text" class="mt-1 block w-full" value="{{ old('cnpj', $revenda->cnpj ?? '') }}" />
        <x-input-error :messages="$errors->get('cnpj')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="contato_nome" value="Contato" />
        <x-text-input id="contato_nome" name="contato_nome" type="text" class="mt-1 block w-full" value="{{ old('contato_nome', $revenda->contato_nome ?? '') }}" />
        <x-input-error :messages="$errors->get('contato_nome')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="contato_email" value="E-mail" />
        <x-text-input id="contato_email" name="contato_email" type="email" class="mt-1 block w-full" value="{{ old('contato_email', $revenda->contato_email ?? '') }}" />
        <x-input-error :messages="$errors->get('contato_email')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="contato_telefone" value="Telefone" />
        <x-text-input id="contato_telefone" name="contato_telefone" type="text" class="mt-1 block w-full" value="{{ old('contato_telefone', $revenda->contato_telefone ?? '') }}" />
        <x-input-error :messages="$errors->get('contato_telefone')" class="mt-2" />
    </div>

    <div class="flex items-center mt-6">
        <label class="inline-flex items-center">
            <input type="checkbox" name="ativo" value="1" class="rounded border-line text-brand" {{ old('ativo', $revenda->ativo ?? true) ? 'checked' : '' }}>
            <span class="ms-2 text-sm text-ink-dim">Revenda ativa</span>
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
        <a href="{{ route('revendas.index') }}"
           class="h-9 px-3.5 inline-flex items-center rounded-control border border-btn-line text-[12.5px] font-semibold text-ink-dim hover:text-ink transition">
            Cancelar
        </a>
    @endif
    <button type="submit"
            class="h-9 px-3.5 rounded-control bg-brand text-on-brand text-[12.5px] font-semibold hover:bg-brand-bright transition">
        Salvar revenda
    </button>
</div>
