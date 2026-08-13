<x-guest-layout>
    {{-- Layout de fora, e não o do painel, embora a pessoa já esteja
         autenticada: a sidebar ao lado ofereceria portas que este middleware
         vai fechar em seguida, e a tela passaria a mensagem errada — a de que
         a troca pode ficar para depois. --}}
    <h1 class="font-display text-[21px] font-semibold text-ink text-center">Escolha sua senha</h1>

    <p class="text-[13.5px] text-ink-dim text-center -mt-2">
        A senha que você usou para entrar foi criada por outra pessoa.
        Defina a sua para continuar.
    </p>

    <form method="POST" action="{{ route('senha.primeiro-acesso.update') }}"
          x-data="{ loading: false }" @submit="loading = true" class="flex flex-col gap-4">
        @csrf
        @method('PUT')

        {{-- O navegador precisa do e-mail para oferecer o salvamento da senha
             nova no lugar certo; escondido e sem foco, ele não entra no
             caminho de quem só quer digitar duas vezes e sair. --}}
        <input type="hidden" name="email" value="{{ auth()->user()->email }}" autocomplete="username">

        <div class="flex flex-col gap-1.5">
            <label for="password" class="text-[13px] font-medium text-ink-dim">Nova senha</label>
            <input id="password" type="password" name="password" required autofocus autocomplete="new-password"
                   placeholder="••••••••"
                   class="w-full h-10 px-3.5 rounded-control bg-input border border-line text-ink text-[14.5px] placeholder:text-ink-faint outline-none focus:border-brand focus:ring-4 focus:ring-brand/25 transition">
        </div>

        <div class="flex flex-col gap-1.5">
            <label for="password_confirmation" class="text-[13px] font-medium text-ink-dim">Repita a senha</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                   placeholder="••••••••"
                   class="w-full h-10 px-3.5 rounded-control bg-input border border-line text-ink text-[14.5px] placeholder:text-ink-faint outline-none focus:border-brand focus:ring-4 focus:ring-brand/25 transition">
        </div>

        <button type="submit" :disabled="loading"
                class="h-[42px] rounded-control bg-brand text-on-brand font-semibold text-[15px] flex items-center justify-center gap-2.5 hover:bg-brand-bright transition focus:outline-none focus:ring-2 focus:ring-brand-dim focus:ring-offset-2 focus:ring-offset-panel disabled:opacity-70">
            <svg x-show="loading" x-cloak class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-30" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
                <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
            </svg>
            <span x-text="loading ? 'Salvando...' : 'Salvar e continuar'"></span>
        </button>

        @if ($errors->any())
            <div role="alert" class="flex items-center gap-2.5 px-3.5 py-2.5 rounded-control border text-[13.5px]"
                 style="background: var(--crit-tint); border-color: rgb(var(--crit) / 0.3); color: rgb(var(--crit))">
                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <circle cx="12" cy="12" r="10" /><line x1="12" y1="8" x2="12" y2="12" /><line x1="12" y1="16" x2="12.01" y2="16" />
                </svg>
                {{ $errors->first() }}
            </div>
        @endif
    </form>

    {{-- A única saída desta tela além de trocar a senha. Sem ela, quem abriu
         por engano na máquina de outra pessoa não tem como sair. --}}
    <form method="POST" action="{{ route('logout') }}" class="-mt-2">
        @csrf
        <button type="submit" class="w-full text-center text-[13px] text-ink-mute hover:text-ink transition">
            Sair
        </button>
    </form>
</x-guest-layout>
