<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'AlfaMatriz') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|space-grotesk:500,600,700" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-ink antialiased">
        <div class="min-h-screen bg-canvas flex items-center justify-center px-4 py-10 relative overflow-hidden">
            <div class="absolute inset-0 pointer-events-none" style="background-image:linear-gradient(rgba(230,238,240,0.025) 1px,transparent 1px),linear-gradient(90deg,rgba(230,238,240,0.025) 1px,transparent 1px);background-size:56px 56px;"></div>
            <div class="absolute -top-[220px] left-1/2 -translate-x-1/2 w-[820px] h-[560px] pointer-events-none" style="background:radial-gradient(ellipse at center, rgba(2,156,175,0.14) 0%, rgba(2,156,175,0.05) 45%, transparent 70%);"></div>
            <div class="absolute top-0 left-1/2 -translate-x-1/2 w-[520px] h-px pointer-events-none" style="background:linear-gradient(90deg,transparent,rgba(91,227,239,0.35),transparent);"></div>

            <div class="w-full max-w-[400px] relative flex flex-col gap-7">
                <div class="bg-panel border border-white/5 shadow-panel rounded-2xl px-7 py-8 flex flex-col gap-6">
                    <a href="/" class="flex items-center gap-3">
                        <span class="h-11 w-11 shrink-0 rounded-[10px] bg-brand/15 border border-brand/35 text-brand-dim flex items-center justify-center font-display font-bold text-xl">A</span>
                        <div class="leading-tight">
                            <p class="font-display font-semibold text-ink tracking-wide">ALFA</p>
                            <p class="text-[11px] uppercase tracking-[0.28em] text-ink-mute">Matriz</p>
                        </div>
                    </a>

                    {{ $slot }}
                </div>

                <div class="flex flex-col items-center gap-2.5" x-data="{ ok: true }" x-init="fetch('{{ route('healthz') }}').then(r => ok = r.ok).catch(() => ok = false)">
                    <div class="flex items-center gap-2 px-3 py-1.5 rounded-full bg-status-good/[0.07] border border-status-good/20" :class="! ok && '!bg-status-critical/[0.07] !border-status-critical/20'">
                        <span class="h-[7px] w-[7px] rounded-full shrink-0" :class="ok ? 'bg-status-good' : 'bg-status-critical'" :style="ok ? 'box-shadow:0 0 6px rgba(12,163,12,.7)' : 'box-shadow:0 0 6px rgba(208,59,59,.7)'"></span>
                        <span class="text-xs text-ink-dim tracking-wide" x-text="ok ? 'Sistemas operacionais' : 'Sistema com instabilidade'"></span>
                    </div>
                    <p class="text-xs text-ink-mute tracking-wide text-center">Painel interno · acesso somente por convite</p>
                    <div class="flex items-center gap-3.5 text-xs text-ink-mute">
                        <a href="#" class="hover:text-ink-dim transition">Termos de Uso</a>
                        <span class="text-white/15">·</span>
                        <a href="#" class="hover:text-ink-dim transition">Política de Privacidade</a>
                    </div>
                </div>
            </div>
        </div>
    </body>
</html>
