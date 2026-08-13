{{-- Campos de uma conta. Espera: $usuario (null ao criar), $perfis, $revendas.

    Não há campo de senha, e a ausência é deliberada: quem administra escolhe
    quem entra, não com o quê. A senha é gerada pelo sistema e mostrada uma vez
    — ver `UsuarioController::senhaGerada()`. --}}

@php $selecionados = collect(old('perfis', $usuario?->perfis->pluck('id')->all() ?? [])); @endphp

<div class="flex flex-col gap-4">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <x-input-label for="name-{{ $idForm }}" value="Nome" />
            <x-text-input id="name-{{ $idForm }}" name="name" type="text" class="mt-1 block w-full"
                          value="{{ old('name', $usuario?->name) }}" required />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="email-{{ $idForm }}" value="E-mail de acesso" />
            <x-text-input id="email-{{ $idForm }}" name="email" type="email" class="mt-1 block w-full"
                          value="{{ old('email', $usuario?->email) }}" required />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>
    </div>

    <div>
        <x-input-label value="Perfis de acesso" />
        <p class="text-[12px] text-ink-mute mt-0.5">O que a pessoa enxerga no menu sai daqui.</p>

        <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-1.5">
            @foreach ($perfis as $perfil)
                <label class="flex items-center gap-2.5 h-9 px-3 rounded-control border border-line bg-input cursor-pointer hover:border-brand transition">
                    <input type="checkbox" name="perfis[]" value="{{ $perfil->id }}"
                           {{-- Sem `bg-input`: classe de fundo vence o
                                `:checked` do plugin de forms, e a caixa marcada
                                fica branca sobre branca no tema claro. --}}
                           class="h-4 w-4 rounded border-btn-line text-brand focus:ring-brand"
                           @checked($selecionados->contains($perfil->id))>
                    <span class="text-[13px] text-ink-dim">{{ $perfil->nome }}</span>
                </label>
            @endforeach
        </div>
        <x-input-error :messages="$errors->get('perfis')" class="mt-2" />
    </div>

    <div>
        {{-- Alcance, não lotação: com uma revenda escolhida a conta passa a ver
             só o portfólio dela, e as telas de gestão da matriz somem. É o
             mesmo `revenda_id` que o `alfa:criar-usuario` recebe em `--revenda`. --}}
        <x-input-label for="revenda-{{ $idForm }}" value="Alcance" />
        <select id="revenda-{{ $idForm }}" name="revenda_id"
                class="mt-1 block w-full h-10 py-0 text-[13.5px] rounded-control bg-input border-line text-ink">
            <option value="">Matriz — enxerga o negócio inteiro</option>
            @foreach ($revendas as $revenda)
                <option value="{{ $revenda->id }}" @selected((string) old('revenda_id', $usuario?->revenda_id) === (string) $revenda->id)>
                    Revenda {{ $revenda->nome }} — só o portfólio dela
                </option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('revenda_id')" class="mt-2" />
    </div>
</div>

<div class="sticky bottom-0 -mx-5 -mb-5 mt-4 flex items-center justify-end gap-2 border-t border-line bg-panel px-5 py-3">
    <button type="button" x-on:click="$dispatch('close')"
            class="h-9 px-3.5 rounded-control border border-btn-line text-[12.5px] font-semibold text-ink-dim hover:text-ink transition">
        Cancelar
    </button>
    <button type="submit"
            class="h-9 px-3.5 rounded-control bg-brand text-on-brand text-[12.5px] font-semibold hover:bg-brand-bright transition">
        {{ $usuario ? 'Salvar conta' : 'Criar e gerar senha' }}
    </button>
</div>
