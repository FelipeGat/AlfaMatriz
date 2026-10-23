{{--
    Monitor › Acesso (#394): as catracas e leitores do AlfaControl e do
    AlfaGym, por cliente. O sino aparece só como informação — ligar e
    desligar é no monitor.
    Espera: $resposta (acesso?fonte=), $filtros, $fonte, $clientes.
--}}
@use('App\Services\Monitor\FormatoDoMonitor', 'F')
@use('App\Http\Controllers\PainelDoMonitorController', 'Painel')
@php
    $totais = $fonte['totais'] ?? [];
    $janelas = ['hoje' => 'Hoje', 'd7' => '7 dias', 'd30' => '30 dias'];
@endphp

<x-monitor.tela tela="acesso" :resposta="$resposta"
                :contexto="$fonte ? ($fonte['online'] ?? 0).' de '.($fonte['total'] ?? 0).' aparelhos online no '.$filtros['fonte'] : null">
    <div class="flex flex-wrap items-center gap-2">
        <x-abas>
            @foreach (Painel::FONTES as $nome)
                <x-abas.item :href="route('monitor.acesso', ['fonte' => $nome, 'situacao' => $filtros['situacao'], 'busca' => $filtros['busca'] ?: null])"
                             :ativo="$filtros['fonte'] === $nome">{{ $nome }}</x-abas.item>
            @endforeach
        </x-abas>

        <form method="GET" class="flex flex-wrap items-center gap-2">
            <input type="hidden" name="fonte" value="{{ $filtros['fonte'] }}">
            <input type="search" name="busca" value="{{ $filtros['busca'] }}" placeholder="Buscar cliente, aparelho ou IP…"
                   class="h-[34px] w-[240px] py-0 text-[13px] rounded-control bg-input border-line text-ink placeholder:text-ink-faint">
            <select name="situacao" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="todos" @selected($filtros['situacao'] === 'todos')>Todos</option>
                <option value="online" @selected($filtros['situacao'] === 'online')>Online</option>
                <option value="offline" @selected($filtros['situacao'] === 'offline')>Offline</option>
            </select>
            <button type="submit"
                    class="h-[34px] px-3 rounded-control border border-btn-line text-ink-dim
                           text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
                Filtrar
            </button>
        </form>

        @if ($fonte && ($fonte['coleta'] ?? null) !== 'ok')
            <span class="ml-auto font-mono text-[11px] font-semibold text-crit">a coleta do {{ $filtros['fonte'] }} está com problema</span>
        @elseif ($fonte && F::hora($fonte['ultima_coleta'] ?? null))
            <span class="ml-auto font-mono text-[11px] text-ink-faint">última coleta {{ F::hora($fonte['ultima_coleta'])->format('d/m H:i') }}</span>
        @endif
    </div>

    <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(min(210px, 100%), 1fr))">
        @foreach ($janelas as $chave => $rotulo)
            <x-kpi-card :rotulo="'Liberados · '.$rotulo" :valor="F::inteiro($totais[$chave]['liberados'] ?? null)"
                        :delta="F::inteiro($totais[$chave]['negados'] ?? null).' negados'" acento="good" icone="check-circle" />
        @endforeach
    </div>

    {{--
        Um cartão por cliente, com o cabeçalho das colunas uma vez só, no topo
        (#402). Com o cabeçalho repetido em cada cliente, a faixa do nome e a
        linha das colunas ficavam do mesmo peso, e o olho não achava onde
        terminava um cliente e começava o outro — e 33 aparelhos davam quatro
        telas.

        As colunas dos cartões e do cabeçalho são a mesma `colgroup` em tabelas
        `fixa`: sem isso cada tabela acharia as próprias larguras, e a barra de
        histórico pularia de lugar de um cliente para o outro. A rolagem lateral
        é UMA só, por fora de tudo, para o cabeçalho rolar junto com os cartões
        em tela estreita — por isso a largura mínima de dentro é a mesma das
        tabelas, e a rolagem delas nunca entra em ação.
    --}}
    @php
        $colunas = '<colgroup><col class="w-[20%]"><col class="w-[14%]"><col class="w-[11%]"><col class="w-[19%]"><col class="w-[22%]"><col class="w-[7%]"><col class="w-[7%]"></colgroup>';
    @endphp

    @if ($clientes->isNotEmpty())
        <div class="overflow-x-auto">
            <div class="min-w-[980px]">
                {{-- A borda de 2px transparente à esquerda é a mesma dos cartões:
                     sem ela as colunas do cabeçalho ficariam 1px fora das deles. --}}
                <x-tabela min="980px" fixa class="mb-2 !bg-head border-l-2 border-l-transparent">
                        {!! $colunas !!}
                        <thead>
                            <tr class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                                <th class="px-4 py-2.5 font-semibold">Aparelho</th>
                                <th class="px-4 py-2.5 font-semibold">Modelo</th>
                                <th class="px-4 py-2.5 font-semibold">IP</th>
                                <th class="px-4 py-2.5 font-semibold">Agora</th>
                                <th class="px-4 py-2.5 font-semibold">Histórico</th>
                                <th class="px-4 py-2.5 font-semibold text-right">No ar 24h</th>
                                <th class="px-4 py-2.5 font-semibold">Sino</th>
                            </tr>
                        </thead>
                </x-tabela>

                <div class="space-y-4">
                    @foreach ($clientes as $cliente)
                        @php
                            $idDoCliente = isset($cliente['cliente_id']) ? $filtros['fonte'].':'.$cliente['cliente_id'] : null;
                            $online = (int) ($cliente['online'] ?? 0);
                            $total = (int) ($cliente['total'] ?? 0);
                            $aparelhos = collect($cliente['aparelhos']);
                            $ultimoPing = $aparelhos->map(fn (array $a) => F::hora($a['ultimo_ping'] ?? null))->filter()->max();

                            // A cor da faixa é a situação do cliente. Fechado
                            // agora fica neutro: fora do horário, offline é o
                            // esperado, e vermelho ali seria alarme falso.
                            [$borda, $tinta] = match (true) {
                                (bool) ($cliente['fechado_agora'] ?? false) => ['border-l-ink-faint', 'text-ink-faint'],
                                $total > 0 && $online >= $total => ['border-l-good', 'text-good'],
                                $online === 0 => ['border-l-crit', 'text-crit'],
                                default => ['border-l-warn', 'text-warn'],
                            };

                            // Recolhido de saída: todo offline e nenhum ping nas
                            // últimas 24 h (decisão do dono, 18/10/2026, que
                            // incluiu quem está fechado agora). Quem está buscando
                            // quer ver o que achou: com busca, abre.
                            $recolhivel = $online === 0
                                && (! $ultimoPing || $ultimoPing->lt(now()->subDay()));
                            $chave = $idDoCliente ?? $filtros['fonte'].':'.($cliente['nome'] ?? $loop->index);
                            $padrao = ! $recolhivel || $filtros['busca'] !== '';
                        @endphp

                        @php
                            $sub = new \Illuminate\Support\HtmlString(
                                '<span class="font-semibold '.$tinta.'">'.e($online.' de '.$total.' online').'</span> · '
                                .e($recolhivel
                                    ? 'último ping '.($ultimoPing ? $ultimoPing->diffForHumans() : 'nunca')
                                    : F::inteiro($cliente['acessos_hoje'] ?? null).' acessos hoje · '.F::inteiro($cliente['negados_hoje'] ?? null).' negados'));
                        @endphp

                        {{-- A borda da situação vai no cartão inteiro, e não só na
                             faixa: o grupo todo fica marcado, até o último aparelho.
                             Recolhido, a régua de baixo da faixa some — senão ela
                             encostaria na borda do cartão e viraria linha dupla. --}}
                        <x-tabela min="980px" fixa :titulo="$cliente['nome'] ?? '—'" :sub="$sub"
                                  class="tabela-zebrada border-l-2 {{ $borda }}"
                                  x-data="{ chave: {{ \Illuminate\Support\Js::from($chave) }}, padrao: {{ \Illuminate\Support\Js::from($padrao) }} }"
                                  x-init="$store.monitor.abertos[chave] ??= padrao"
                                  x-bind:class="$store.monitor.abertos[chave] ? '' : '[&>div:first-child]:border-b-transparent'">
                            <x-slot name="cabecalho">
                                    @if ($cliente['horario'] ?? null)
                                        <span class="hidden md:inline text-[11.5px] text-ink-faint">{{ $cliente['horario'] }}</span>
                                    @endif
                                    @if ($cliente['fechado_agora'] ?? false)
                                        <x-badge>fechado agora</x-badge>
                                    @endif
                                    @if ($recolhivel)
                                        <button type="button" @click="$store.monitor.abertos[chave] = ! $store.monitor.abertos[chave]"
                                                :aria-expanded="$store.monitor.abertos[chave] ? 'true' : 'false'"
                                                class="h-[28px] px-2 inline-flex items-center gap-1 rounded-control border border-btn-line
                                                       text-[12px] font-semibold text-ink-dim hover:text-brand hover:border-brand transition">
                                            <span x-text="$store.monitor.abertos[chave] ? 'Recolher' : 'Mostrar {{ $aparelhos->count() }}'">{{ $padrao ? 'Recolher' : 'Mostrar '.$aparelhos->count() }}</span>
                                            <span class="h-[14px] w-[14px] transition-transform" :class="$store.monitor.abertos[chave] ? 'rotate-180' : ''"><x-nav-icon name="chevron-down" /></span>
                                        </button>
                                    @endif
                                    @if ($idDoCliente)
                                        <a href="{{ route('monitor.cliente', $idDoCliente) }}" class="text-[12px] font-semibold text-brand-text hover:underline whitespace-nowrap">Ver cliente ›</a>
                                    @endif
                            </x-slot>

                                {!! $colunas !!}
                                <tbody x-show="$store.monitor.abertos[chave]" @if (! $padrao) x-cloak @endif>
                                    @foreach ($aparelhos as $aparelho)
                                        @php $ping = F::hora($aparelho['ultimo_ping'] ?? null); @endphp
                                        {{-- 40px por aparelho (README, linhas de lista): o ping
                                             vai ao lado do selo, e não embaixo — era a segunda
                                             linha que dobrava a altura de cada aparelho. --}}
                                        <tr class="h-10 border-b border-rule last:border-b-0 hover:bg-chip transition align-middle">
                                            <td class="px-4 text-[13px] font-medium text-ink truncate" title="{{ $aparelho['nome'] ?? '' }}">{{ $aparelho['nome'] ?? '—' }}</td>
                                            <td class="px-4 font-mono text-[11.5px] text-ink-dim truncate">{{ $aparelho['modelo'] ?? '—' }}</td>
                                            <td class="px-4 font-mono text-[11.5px] text-ink-dim truncate">{{ $aparelho['ip'] ?? '—' }}</td>
                                            <td class="px-4">
                                                <div class="flex items-center gap-2 min-w-0">
                                                    <x-badge :tom="F::tom($aparelho['estado'] ?? null)" ponto>{{ ($aparelho['estado'] ?? '') === 'online' ? 'Online' : 'Offline' }}</x-badge>
                                                    @if ($ping)
                                                        <span class="min-w-0 truncate text-[11px] text-ink-faint" title="{{ $ping->format('d/m/Y H:i:s') }}">
                                                            ping {{ $ping->diffForHumans() }}{{ is_numeric($aparelho['ping_ms'] ?? null) ? ' · '.F::ms($aparelho['ping_ms']) : '' }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </td>
                                            <td class="px-4"><x-monitor.barra :barra="$aparelho['barra'] ?? null" /></td>
                                            <td class="px-4 text-right font-sans tabular text-[12.5px] text-ink-dim whitespace-nowrap">{{ F::noAr($aparelho['pct_no_ar_24h'] ?? null) }}</td>
                                            <td class="px-4">
                                                <span class="inline-flex items-center gap-1 text-[12px] {{ ($aparelho['sino'] ?? false) ? 'text-ink-dim' : 'text-ink-faint' }}"
                                                      title="{{ ($aparelho['sino'] ?? false) ? 'O monitor avisa quando este aparelho cai' : 'O monitor não avisa quando este aparelho cai' }}">
                                                    <span class="h-[14px] w-[14px] shrink-0"><x-nav-icon :name="($aparelho['sino'] ?? false) ? 'bell' : 'speaker-x-mark'" /></span>
                                                    {{ ($aparelho['sino'] ?? false) ? 'ligado' : 'desligado' }}
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                        </x-tabela>
                    @endforeach
                </div>
            </div>
        </div>
    @else
        <div class="rounded-panel border border-line bg-surface px-5 py-8 text-center text-[13px] text-ink-mute">
            Nenhum aparelho {{ $filtros['situacao'] === 'todos' ? '' : $filtros['situacao'].' ' }}{{ $filtros['busca'] !== '' ? 'com «'.$filtros['busca'].'» ' : '' }}no {{ $filtros['fonte'] }}.
        </div>
    @endif
</x-monitor.tela>
