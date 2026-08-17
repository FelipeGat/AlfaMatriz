{{--
    Acesso negado (403) — a recusa de permissão deixava a pessoa na página
    padrão do framework, em inglês e fora do sistema visual, sem caminho de
    volta além do botão do navegador.

    Quem bate aqui está LOGADO: toda rota com `permissao:` vive dentro do
    grupo `auth` (routes/web.php). Por isso a moldura do painel fica de pé —
    a pessoa não foi expulsa, só tentou uma porta que a conta dela não abre,
    e tirar a sidebar daria a impressão de sessão derrubada. O @guest é rede
    de segurança para um abort(403) que um dia nasça fora do grupo: sem
    sessão, a moldura quebraria no Auth::user() da navegação.
--}}

@php
    // As recusas do sistema falam português e dizem o motivo exato ("Você só
    // pode acessar os leads da sua revenda."). O fallback cobre o abort(403)
    // seco e as exceções do próprio framework, que falam inglês — repassar
    // "This action is unauthorized." seria pior que a frase genérica.
    $motivo = ($exception ?? null)?->getMessage() ?: '';

    if ($motivo === '' || ! str_contains($motivo, ' ') || str_starts_with($motivo, 'This action')) {
        $motivo = 'Você não tem permissão para acessar esta tela.';
    }
@endphp

@auth
    <x-app-layout>
        <x-slot name="titulo">Acesso restrito</x-slot>
        <x-slot name="contexto">Erro 403</x-slot>

        {{-- Mesma moldura de 396px da tela de entrada: é a largura que o
             sistema já usa quando o conteúdo é um recado único. --}}
        <div class="py-12 flex justify-center">
            <div class="w-full flex flex-col gap-6" style="max-width: 396px">
                <div class="rounded-panel border border-line bg-panel flex flex-col items-center gap-5 text-center"
                     style="padding: 30px 28px">
                    {{-- O selo usa a tinta de recusa do login: mesma notícia,
                         mesma cor. --}}
                    <div class="h-11 w-11 rounded-control border flex items-center justify-center"
                         style="background: var(--crit-tint); border-color: rgb(var(--crit) / 0.3); color: rgb(var(--crit))">
                        <span class="h-5 w-5"><x-nav-icon name="cadeado-fechado" :peso="1.6" /></span>
                    </div>

                    <div class="flex flex-col gap-2">
                        {{-- "Conta", e não "perfil": a recusa tanto vem da grade
                             de permissões quanto do escopo de revenda, e só a
                             primeira é assunto de perfil. --}}
                        <h2 class="font-display text-[21px] font-semibold text-ink">Sua conta não tem este acesso</h2>
                        <p class="text-[13.5px] text-ink-dim">{{ $motivo }}</p>
                    </div>

                    <div class="flex items-center justify-center gap-2.5 flex-wrap">
                        {{-- "Voltar" depende de haver história; quem caiu aqui
                             por link direto sai pela tela inicial, que é o
                             destino que a própria conta garante enxergar. --}}
                        <button type="button" @click="history.back()"
                                class="h-[34px] px-3.5 inline-flex items-center rounded-control border border-btn-line text-[12.5px] text-ink-dim hover:text-brand hover:border-brand transition">
                            Voltar
                        </button>
                        <a href="{{ Auth::user()->telaInicial() }}"
                           class="h-[34px] px-3.5 inline-flex items-center rounded-control bg-brand text-on-brand font-semibold text-[12.5px] hover:bg-brand-bright transition whitespace-nowrap">
                            Ir para minha tela inicial
                        </a>
                    </div>
                </div>

                <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint text-center">
                    Precisa deste acesso? Fale com um administrador
                </p>
            </div>
        </div>
    </x-app-layout>
@else
    <x-guest-layout>
        <div class="flex flex-col items-center gap-5 text-center">
            <div class="h-11 w-11 rounded-control border flex items-center justify-center"
                 style="background: var(--crit-tint); border-color: rgb(var(--crit) / 0.3); color: rgb(var(--crit))">
                <span class="h-5 w-5"><x-nav-icon name="cadeado-fechado" :peso="1.6" /></span>
            </div>

            <div class="flex flex-col gap-2">
                <h1 class="font-display text-[21px] font-semibold text-ink">Acesso restrito</h1>
                <p class="text-[13.5px] text-ink-dim">{{ $motivo }}</p>
            </div>

            <a href="{{ route('login') }}"
               class="w-full h-[42px] rounded-control bg-brand text-on-brand font-semibold text-[15px] flex items-center justify-center hover:bg-brand-bright transition">
                Ir para a tela de entrada
            </a>
        </div>
    </x-guest-layout>
@endauth
