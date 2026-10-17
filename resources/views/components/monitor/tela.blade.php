@props([
    'tela',                 // geral | acesso | clientes | incidentes | relatorio | servidor | cliente
    'resposta' => null,     // RespostaDoMonitor da tela (nulo na aba de alertas recebidos)
    'contexto' => null,
    'migalhas' => null,     // caminho da tela filha: [['rotulo' => …, 'rota' => …]]
    'atual' => null,
    'automatico' => true,   // atualiza sozinha a cada 30 s
])

{{--
    A moldura das telas do Monitor (#394): abas, a hora do dado e a
    atualização.

    A hora que importa é a do RETRATO do monitor (`gerado_em`), e não a da
    consulta: com o cache e o último dado bom, as duas se separam justamente
    quando o monitor está mal — que é quando alguém precisa saber de quando é
    o número na tela.

    Servidor e cliente são telas FILHAS (abertas a partir da Geral, do Acesso
    e dos Clientes): têm migalhas, e a aba de onde vieram fica acesa.
--}}
@php
    $abaAcesa = match ($tela) {
        'servidor' => 'geral',
        'cliente' => 'clientes',
        default => $tela,
    };

    $abas = [
        'geral' => ['rota' => route('monitor.geral'), 'rotulo' => 'Geral', 'icone' => 'bolt'],
        'acesso' => ['rota' => route('monitor.acesso'), 'rotulo' => 'Acesso', 'icone' => 'key'],
        'clientes' => ['rota' => route('monitor.clientes'), 'rotulo' => 'Clientes', 'icone' => 'users'],
        'incidentes' => ['rota' => route('monitor.incidentes'), 'rotulo' => 'Incidentes', 'icone' => 'alert-triangle'],
        'relatorio' => ['rota' => route('monitor.relatorio'), 'rotulo' => 'Relatórios', 'icone' => 'chart-bar'],
    ];

    $gerado = $resposta?->geradoEm;
@endphp

<x-app-layout>
    @if ($migalhas)
        <x-slot name="caminho">
            <x-migalhas :caminho="$migalhas" :atual="$atual" />
        </x-slot>
    @else
        <x-slot name="titulo">Monitor</x-slot>
    @endif

    @if ($contexto)
        <x-slot name="contexto"><span id="monitor-contexto">{{ $contexto }}</span></x-slot>
    @endif

    <x-slot name="acoes">
        <div x-data class="flex items-center gap-2">
            <span class="hidden sm:inline font-mono text-[11px] text-ink-faint whitespace-nowrap"
                  x-text="$store.monitor.hora"></span>
            <button type="button" @click="$dispatch('monitor-atualizar')" :disabled="$store.monitor.carregando"
                    class="h-[34px] px-3 inline-flex items-center gap-1.5 rounded-control border border-btn-line text-ink-dim
                           text-[12.5px] font-semibold hover:text-brand hover:border-brand transition whitespace-nowrap disabled:opacity-60">
                <span class="h-[14px] w-[14px]" :class="$store.monitor.carregando ? 'animate-spin' : ''"><x-nav-icon name="repeat" /></span>
                Atualizar
            </button>
        </div>
    </x-slot>

    <div class="space-y-4">
        <x-abas>
            @foreach ($abas as $chave => $aba)
                <x-abas.item :href="$aba['rota']" :ativo="$abaAcesa === $chave" :icone="$aba['icone']">
                    {{ $aba['rotulo'] }}
                </x-abas.item>
            @endforeach
        </x-abas>

        <div id="monitor-conteudo" class="space-y-4"
             data-hora="{{ $gerado ? 'dados do monitor de '.$gerado->format('H:i:s') : 'atualizado às '.now()->format('H:i') }}"
             x-data="monitorTela({ automatico: {{ $automatico ? 'true' : 'false' }} })"
             @monitor-atualizar.window="atualizar()">
            @if ($resposta && $resposta->velho)
                {{-- Último dado bom: a tela continua útil, mas diz que é velho
                     e por quê — senão o número de há uma hora passa por agora. --}}
                <div class="rounded-panel border border-warn-line bg-warn-tint px-4 py-3 text-[13px] text-ink">
                    <span class="font-semibold">{{ $resposta->erro }}</span>
                    Mostrando o último retrato que chegou{{ $gerado ? ', de '.$gerado->format('d/m H:i:s').' ('.$gerado->diffForHumans().')' : '' }}.
                </div>
            @endif

            @if ($resposta && ! $resposta->temDados())
                <div class="rounded-panel border border-line bg-surface px-5 py-8 text-center">
                    <p class="text-[13px] font-medium text-ink">Sem dados do AlfaMonitor agora.</p>
                    <p class="mt-1 text-[12.5px] text-ink-mute">{{ $resposta->erro }}</p>
                </div>
            @else
                {{ $slot }}
            @endif
        </div>
    </div>

    @include('monitor._alpine')
</x-app-layout>
