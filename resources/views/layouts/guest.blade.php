<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'AlfaMatriz') }}</title>

        <link rel="icon" href="/icon-matriz.svg" type="image/svg+xml">
        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

        {{-- Mesmo tema da porta de dentro: quem usa o claro não pode levar um
             flash escuro na cara ao abrir a tela de entrada. --}}
        <script>
            (function () {
                try {
                    if (localStorage.getItem('alfamatriz:tema') === 'claro') {
                        document.documentElement.classList.add('theme-light');
                    }
                } catch (erro) {
                    // sem preferência guardada: vale o tema escuro, que é o padrão
                }
            })();
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>

    <body class="font-sans text-ink antialiased">
        <div class="min-h-screen bg-canvas flex items-center justify-center px-4 py-10 relative overflow-hidden">
            {{-- Grade de 56px: dá medida ao vazio sem virar textura. --}}
            <div class="absolute inset-0 pointer-events-none"
                 style="background-image:
                            linear-gradient(var(--grid-line) 1px, transparent 1px),
                            linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
                        background-size: 56px 56px;"></div>

            {{-- Halo da marca no topo. --}}
            <div class="absolute -top-[220px] left-1/2 -translate-x-1/2 pointer-events-none"
                 style="width: 820px; height: 560px;
                        background: radial-gradient(ellipse at center,
                            rgb(var(--brand) / 0.16) 0%,
                            rgb(var(--brand) / 0.05) 45%,
                            transparent 70%);"></div>
            <div class="absolute top-0 left-1/2 -translate-x-1/2 h-px w-[520px] pointer-events-none"
                 style="background: linear-gradient(90deg, transparent, rgb(var(--brand-text) / 0.35), transparent);"></div>

            <div class="w-full relative flex flex-col gap-6" style="max-width: 396px">
                <div class="rounded-panel border border-line bg-panel flex flex-col gap-6"
                     style="padding: 30px 28px">
                    <a href="/" class="flex items-center gap-2.5">
                        <img src="/icon-matriz.svg" alt="" class="h-9 w-9 shrink-0">
                        <img src="/alfamatriz.png" alt="AlfaMatriz" class="h-[17px] w-auto shrink-0">
                    </a>

                    {{ $slot }}
                </div>

                {{-- Selo de estado: quem não consegue entrar precisa saber se o
                     problema é a senha ou o servidor. Vem da checagem de saúde
                     que o deploy já usa. --}}
                <div class="flex flex-col items-center gap-2.5"
                     x-data="{ ok: true }"
                     x-init="fetch('{{ route('healthz') }}').then(r => ok = r.ok).catch(() => ok = false)">
                    <div class="flex items-center gap-2 px-3 py-1.5 rounded-full border"
                         :style="ok
                             ? 'background: var(--good-tint); border-color: var(--good-line)'
                             : 'background: var(--crit-tint); border-color: rgb(var(--crit) / 0.25)'">
                        <span class="h-[7px] w-[7px] rounded-full shrink-0"
                              :style="ok ? 'background: rgb(var(--good))' : 'background: rgb(var(--crit))'"></span>
                        <span class="font-mono text-[10.5px] uppercase tracking-caps"
                              :style="ok ? 'color: rgb(var(--good))' : 'color: rgb(var(--crit))'"
                              x-text="ok ? 'Sistemas operacionais' : 'Sistema com instabilidade'"></span>
                    </div>

                    <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint text-center">
                        Painel interno · acesso somente por convite
                    </p>
                </div>
            </div>
        </div>
    </body>
</html>
