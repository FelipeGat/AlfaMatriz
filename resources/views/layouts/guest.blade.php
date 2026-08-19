<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'AlfaMatriz') }}</title>

        <link rel="icon" href="/icon-matriz-solid.svg" type="image/svg+xml">
        <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">


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
                    {{-- A marca centraliza no card: sem o texto de apoio ao
                         lado, encostada à esquerda ela ficava desamarrada do
                         resto do conteúdo. --}}
                    <a href="/" class="flex items-center justify-center gap-2.5">
                        <img src="/icon-matriz-solid.svg" alt="" class="h-9 w-9 shrink-0">
                        <img src="/alfamatriz.png" alt="AlfaMatriz" class="h-[17px] w-auto shrink-0">
                    </a>

                    {{ $slot }}
                </div>

                {{--
                    O selo "Sistemas operacionais" do handoff (§15) NÃO entra
                    aqui, e não é esquecimento: ele saiu a pedido do dono do
                    produto, junto com o "Acesso restrito à equipe AlfaMatriz"
                    — a tela diz o que é e para de falar. O `LoginTest` guarda
                    as duas ausências para que ninguém as reponha achando que
                    está cumprindo o handoff.

                    A checagem de saúde continua de pé: ela serve ao deploy, que
                    é quem sempre dependeu dela.
                --}}
                <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint text-center">
                    Painel interno · acesso somente por convite
                </p>
            </div>
        </div>

        {{-- Mesma pilha de avisos da porta de dentro (ver layouts/app): sem
             ela aqui, um aviso nesta tela simplesmente não teria onde
             pousar e não apareceria. Aqui não há topbar, então a faixa começa
             no alto da janela. --}}
        <div id="pilha-avisos"
             class="fixed top-0 left-1/2 -translate-x-1/2 z-[60] pt-4 px-4 w-full
                    flex flex-col items-center gap-2 pointer-events-none"></div>

        {{-- A tela de entrada costuma ficar aberta a manhã inteira numa aba de
             fundo. Sem isto, o token que veio no HTML vence junto com a sessão
             e o primeiro clique em "Entrar" volta para o login com o aviso de
             sessão expirada — só na segunda tentativa é que dá certo. Aqui o
             token é trocado antes disso: ao voltar para a aba, e de tempos em
             tempos enquanto ela estiver à vista. --}}
        <script>
            (function () {
                const INTERVALO = 15 * 60 * 1000;

                async function renovarToken() {
                    try {
                        const resposta = await fetch('{{ route('csrf-token') }}', {
                            headers: { 'Accept': 'application/json' },
                            credentials: 'same-origin',
                            cache: 'no-store',
                        });

                        if (! resposta.ok) {
                            return;
                        }

                        const { token } = await resposta.json();

                        if (! token) {
                            return;
                        }

                        document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', token);
                        document.querySelectorAll('input[name="_token"]').forEach((campo) => {
                            campo.value = token;
                        });
                    } catch (erro) {
                        // Sem rede, ou já logado em outra aba: o envio ainda
                        // acontece com o token antigo, e quem cuida do resto é
                        // o tratamento de 419 em bootstrap/app.php.
                    }
                }

                setInterval(() => {
                    if (document.visibilityState === 'visible') {
                        renovarToken();
                    }
                }, INTERVALO);

                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible') {
                        renovarToken();
                    }
                });
            })();
        </script>
    </body>
</html>
