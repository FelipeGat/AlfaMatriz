<x-guest-layout>
    <h1 class="font-display text-[21px] font-semibold text-ink">Entrar</h1>

    <x-auth-session-status :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" x-data="{ loading: false, showPw: false }" @submit="loading = true" class="flex flex-col gap-4">
        @csrf

        <div class="flex flex-col gap-1.5">
            <label for="email" class="text-[13px] font-medium text-ink-dim">E-mail</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   placeholder="voce@alfatecnologia.com.br"
                   class="w-full h-10 px-3.5 rounded-control bg-input border border-line text-ink text-[14.5px] placeholder:text-ink-faint outline-none focus:border-brand focus:ring-4 focus:ring-brand/25 transition">
        </div>

        <div class="flex flex-col gap-1.5">
            <label for="password" class="text-[13px] font-medium text-ink-dim">Senha</label>
            <div class="relative">
                <input :type="showPw ? 'text' : 'password'" id="password" name="password" required autocomplete="current-password"
                       placeholder="••••••••"
                       class="w-full h-10 pl-3.5 pr-11 rounded-control bg-input border border-line text-ink text-[14.5px] placeholder:text-ink-faint outline-none focus:border-brand focus:ring-4 focus:ring-brand/25 transition">
                <button type="button" @click="showPw = !showPw" :aria-label="showPw ? 'Ocultar senha' : 'Mostrar senha'"
                        class="absolute right-1.5 top-1/2 -translate-y-1/2 h-8 w-8 rounded-ctl text-ink-mute hover:text-brand hover:bg-chip flex items-center justify-center transition focus:outline-none focus:ring-2 focus:ring-brand">
                    <svg x-show="! showPw" class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z" /><circle cx="12" cy="12" r="3" />
                    </svg>
                    <svg x-show="showPw" x-cloak class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94" />
                        <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19" />
                        <path d="M14.12 14.12a3 3 0 1 1-4.24-4.24" />
                        <line x1="1" y1="1" x2="23" y2="23" />
                    </svg>
                </button>
            </div>
        </div>

        <div class="flex items-center justify-between gap-3 flex-wrap">
            <label class="flex items-center gap-2 cursor-pointer select-none text-[13.5px] text-ink-dim">
                <input id="remember_me" type="checkbox" name="remember" class="h-4 w-4 rounded border-btn-line bg-input text-brand focus:ring-brand">
                Lembrar-me
            </label>
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="text-[13.5px] text-brand-dim hover:text-brand-bright transition">Esqueci minha senha</a>
            @endif
        </div>

        <button type="submit" :disabled="loading"
                class="h-[42px] rounded-control bg-brand text-on-brand font-semibold text-[15px] flex items-center justify-center gap-2.5 hover:bg-brand-bright transition focus:outline-none focus:ring-2 focus:ring-brand-dim focus:ring-offset-2 focus:ring-offset-panel disabled:opacity-70">
            <svg x-show="loading" x-cloak class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-30" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
                <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
            </svg>
            <span x-text="loading ? 'Entrando...' : 'Entrar'"></span>
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
</x-guest-layout>
