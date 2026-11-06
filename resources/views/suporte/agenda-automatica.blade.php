@php
    use App\Services\Suporte\AgendaAutomatica as A;
    use Illuminate\Support\Carbon;
    $campo = 'w-full rounded-control border border-btn-line bg-canvas px-2.5 py-1.5 text-[13px] text-ink focus:border-brand focus:outline-none disabled:opacity-70';
    $rotulo = 'mb-1 block font-mono text-[10.5px] uppercase tracking-caps text-ink-faint';
    $botao = 'inline-flex h-[30px] items-center rounded-control bg-brand px-3.5 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright';
    $botaoLinha = 'inline-flex h-[28px] items-center gap-1 whitespace-nowrap rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim hover:border-brand hover:text-brand';
    $filtro = 'rounded-control border border-btn-line bg-canvas py-1 pl-2 pr-7 text-[12.5px] text-ink';
    $selo = 'rounded-badge px-1.5 py-0.5 text-[11px]';
    $tonsSituacao = ['lida' => 'bg-good-tint text-good', 'entregue' => 'bg-good-tint text-good', 'aceita' => 'bg-head text-ink', 'falha' => 'bg-crit-tint text-crit',
        'pendente' => 'bg-warn-tint text-warn', 'preparada' => 'bg-head text-ink-dim', 'ensaio' => 'bg-head text-ink-dim', 'sem_envio' => 'bg-head text-ink-dim'];
    $tonsModo = ['desligada' => 'bg-head text-ink-dim', 'ensaio' => 'bg-warn-tint text-warn', 'ligada' => 'bg-good-tint text-good'];
    $verificacoes = ['manha' => 'Manhã', 'programacao' => 'Programação', 'relatorio' => 'Relatório'];
    $resumo = ['destinatarios' => ['destinatário', 'destinatários'], 'enviadas' => ['enviada', 'enviadas'], 'ensaio' => ['em ensaio', 'em ensaio'],
        'sem_envio' => ['sem envio', 'sem envio'], 'pendencias' => ['pendência', 'pendências'], 'falhas' => ['falha', 'falhas']];
    // Chave que o Gestor não mandou é "sem dado" (2.1.3): um null ali não quer dizer "sem número" nem "sem pendência".
    $falta = fn (?array $i, string $k) => $i === null || in_array($k, $i['faltando'] ?? [], true);
    $numeroDe = fn (array $f) => match ($f['problema']) {
        'sem_numero' => 'sem número',
        'numero_compartilhado' => 'número compartilhado',
        default => $f['final'] ? '…'.$f['final'] : ($falta($f, 'final') ? 'sem dado' : 'sem número'),
    };
    $quando = fn (?string $iso) => $iso ? Carbon::parse($iso)->timezone(config('app.timezone'))->format('d/m H:i') : null;
    $cfg = $estado['configuracao'] ?? null;
    $modoAtual = $falta($cfg, 'modo') ? null : $cfg['modo'];
@endphp
<x-app-layout>
    <x-slot name="titulo">Suporte WhatsApp</x-slot>
    <x-slot name="contexto"><span>A agenda do dia seguinte aos técnicos e o relatório aos gestores, pelo WhatsApp</span></x-slot>

    {{-- docs/specs/pedro-agenda-automatica (US4): os dados e o relógio são do Gestor; aqui se escolhe quem recebe,
         quando e em que modo, e se confere o que saiu. Telefone só pelo final, e nenhum número é digitado aqui. --}}
    <div class="mx-auto w-full max-w-6xl space-y-4">
        @include('suporte._abas', ['aba' => 'agenda-automatica'])
        @if (session('status'))<x-aviso>{{ session('status') }}</x-aviso>@endif
        @if ($errors->any())<x-aviso tom="critico" :segundos="0">{{ $errors->first() }}</x-aviso>@endif
        @if ($erro)
            <p class="rounded-panel border border-line bg-crit-tint px-4 py-3 text-[13px] font-semibold text-crit">{{ $erro }}</p>
        @endif
        @unless ($administra)
            <p class="text-[12.5px] text-ink-mute">Você vê a agenda automática; só os administradores do Pedro alteram.</p>
        @endunless

        {{-- Funcionamento: modo e horários --}}
        <section class="rounded-panel border border-line bg-surface">
            <h2 class="border-b border-line px-4 py-2.5 font-display text-[15px] font-semibold text-ink">Funcionamento
                <span class="text-[12.5px] font-normal text-ink-mute">· as verificações rodam no Gestor, mesmo com esta máquina desligada</span></h2>
            @if ($cfg === null)
                <p class="px-4 py-3 text-[12.5px] text-ink-mute">Sem dado: o Gestor não mandou a configuração.</p>
            @else
                <div class="flex flex-wrap items-center gap-2 px-4 pt-3 text-[13px] text-ink-dim">
                    <span>Modo</span>
                    <span class="{{ $selo }} font-semibold {{ $tonsModo[$modoAtual] ?? 'bg-head text-ink-dim' }}">{{ $modoAtual === null ? 'sem dado' : (A::MODOS[$modoAtual] ?? $modoAtual) }}</span>
                    <span class="ml-2">Envio de verdade</span>
                    @php $real = $estado['envio_real'] ?? null; @endphp
                    <span class="{{ $selo }} font-semibold {{ $real ? 'bg-good-tint text-good' : 'bg-head text-ink-dim' }}">{{ $real === null ? 'sem dado' : ($real ? 'sim' : 'não') }}</span>
                </div>
                <form method="POST" action="{{ route('suporte.agenda-automatica.configurar') }}" class="grid gap-3 px-4 py-3 md:grid-cols-5"
                      x-data="{ modo: @js($modoAtual) }">
                    @csrf
                    <input type="hidden" name="modo_atual" value="{{ $modoAtual }}">
                    <label><span class="{{ $rotulo }}">Modo</span>
                        <select name="modo" x-model="modo" class="{{ $campo }}" @disabled(! $administra)>
                            @foreach (A::MODOS as $k => $v)<option value="{{ $k }}" @selected(old('modo', $modoAtual) === $k)>{{ $v }}</option>@endforeach
                        </select></label>
                    @foreach (['manha' => 'Manhã: mudanças da noite', 'programacao' => 'Programação de amanhã', 'relatorio' => 'Relatório aos gestores', 'silencio_inicio' => 'Silêncio a partir de'] as $k => $v)
                        <label><span class="{{ $rotulo }}">{{ $v }}</span>
                            <input type="time" name="{{ $k }}" value="{{ old($k, $cfg[$k]) }}" class="{{ $campo }}" @disabled(! $administra) @if ($falta($cfg, $k)) placeholder="sem dado" @endif></label>
                    @endforeach
                    <p class="text-[12.5px] text-ink-mute md:col-span-5"><b>Desligada</b>: nada sai, e as verificações só registram que rodaram.
                        <b>Ensaio</b>: grava o texto exato que sairia para cada pessoa, sem enviar. <b>Ligada</b>: envia de verdade, e só no Gestor de produção.</p>
                    @if ($administra)
                        <label x-show="modo === 'ligada' && @js($modoAtual) !== 'ligada'" x-cloak class="flex items-start gap-2 text-[12.5px] text-ink md:col-span-5">
                            <input type="checkbox" name="confirmar_ligada" value="1" class="mt-0.5">
                            <span>Sei que, ligada, as mensagens passam a sair de verdade para os técnicos e os gestores da lista.</span></label>
                        <div class="md:col-span-5"><button class="{{ $botao }}">Salvar no Gestor</button></div>
                    @endif
                </form>
            @endif
        </section>

        <div class="grid gap-4 md:grid-cols-2">
            {{-- Os quatro modelos da Meta --}}
            <section class="rounded-panel border border-line bg-surface">
                <h2 class="border-b border-line px-4 py-2.5 font-display text-[15px] font-semibold text-ink">Modelos na Meta
                    <span class="text-[12.5px] font-normal text-ink-mute">· para quem não escreveu nas últimas 24 h</span></h2>
                @php $modelos = $estado['modelos'] ?? null; @endphp
                @if ($modelos === null)
                    <p class="px-4 py-3 text-[12.5px] text-ink-mute">Sem dado.</p>
                @else
                    @foreach ($modelos as $m)
                        <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-2 text-[13px] last:border-b-0">
                            <span class="min-w-0 flex-1 truncate font-mono text-[12px] text-ink">{{ $m['nome'] ?? 'sem dado' }}</span>
                            @if ($falta($m, 'conferido') || $falta($m, 'aprovado'))
                                <span class="{{ $selo }} bg-head text-ink-dim">sem dado</span>
                            @elseif (! $m['conferido'])
                                <span class="{{ $selo }} bg-head text-ink-dim" title="A lista de aprovados vem da tela do WhatsApp do Gestor: abra-a lá para conferir.">não conferido</span>
                            @elseif ($m['aprovado'])
                                <span class="{{ $selo }} bg-good-tint text-good">aprovado</span>
                                @if ($m['categoria'])<span class="{{ $selo }} {{ $m['categoria'] === 'UTILITY' ? 'bg-head text-ink-dim' : 'bg-crit-tint text-crit' }}">{{ $m['categoria'] }}</span>@endif
                                @if ($m['variaveis_ok'] === false)<span class="{{ $selo }} bg-warn-tint text-warn">variáveis diferentes</span>@endif
                            @else
                                <span class="{{ $selo }} bg-warn-tint text-warn">não aprovado</span>
                            @endif
                        </div>
                    @endforeach
                    @if (collect($modelos)->contains(fn ($m) => ($m['conferido'] ?? null) === false))
                        <p class="px-4 py-2 text-[12px] text-ink-mute">"Não conferido": a lista de aprovados vem da tela do WhatsApp do Gestor e vale 10 minutos depois de aberta lá.</p>
                    @endif
                @endif
            </section>

            {{-- Verificações de hoje e de ontem --}}
            <section class="rounded-panel border border-line bg-surface">
                <h2 class="border-b border-line px-4 py-2.5 font-display text-[15px] font-semibold text-ink">Verificações
                    <span class="text-[12.5px] font-normal text-ink-mute">· hoje e ontem</span></h2>
                @php $lista = $estado['verificacoes'] ?? null; @endphp
                @if ($lista === null)
                    <p class="px-4 py-3 text-[12.5px] text-ink-mute">Sem dado.</p>
                @else
                    @forelse ($lista as $v)
                        <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-2 text-[13px] last:border-b-0">
                            <span class="w-[44px] shrink-0 font-mono text-[11.5px] text-ink-faint">{{ $v['dia'] ? Carbon::parse($v['dia'])->format('d/m') : '—' }}</span>
                            <span class="w-[92px] shrink-0 font-semibold text-ink">{{ $verificacoes[$v['tipo']] ?? ($v['tipo'] ?? 'sem dado') }}</span>
                            <span class="{{ $selo }} {{ $tonsModo[$v['modo'] === 'real' ? 'ligada' : $v['modo']] ?? 'bg-head text-ink-dim' }}">{{ $v['modo'] ?? 'sem dado' }}</span>
                            @if ($v['erro'])
                                <span class="min-w-0 flex-1 text-[12px] text-crit">{{ $v['erro'] }}</span>
                            @elseif ($v['concluida_em'])
                                <span class="min-w-0 flex-1 text-[12px] text-ink-mute">concluída {{ $quando($v['concluida_em']) }}</span>
                                @if (is_array($v['resumo']))
                                    <span class="w-full text-[12px] text-ink-mute">{{ collect($v['resumo'])->map(fn ($n, $k) => $n.' '.(isset($resumo[$k]) ? $resumo[$k][(int) $n === 1 ? 0 : 1] : $k))->implode(' · ') }}</span>
                                @endif
                            @else
                                <span class="min-w-0 flex-1 text-[12px] text-warn">{{ $falta($v, 'concluida_em') ? 'sem dado' : 'em aberto' }}</span>
                            @endif
                        </div>
                    @empty
                        <p class="px-4 py-3 text-[12.5px] text-ink-mute">Nenhuma verificação hoje nem ontem: o agendador do Gestor não rodou o comando.</p>
                    @endforelse
                @endif
            </section>
        </div>

        {{-- Destinatários --}}
        <section class="rounded-panel border border-line bg-surface">
            <h2 class="border-b border-line px-4 py-2.5 font-display text-[15px] font-semibold text-ink">Quem recebe
                <span class="text-[12.5px] font-normal text-ink-mute">· o número é o celular do cadastro de funcionário do Gestor, lido a cada envio</span></h2>
            @php $destinatarios = $estado['destinatarios'] ?? null; @endphp
            @if ($destinatarios === null)
                <p class="px-4 py-3 text-[12.5px] text-ink-mute">Sem dado.</p>
            @else
                @forelse ($destinatarios as $d)
                    <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-2 text-[13px] last:border-b-0 {{ $d['ativo'] === false ? 'opacity-70' : '' }}">
                        <span class="min-w-0 flex-1 truncate font-semibold text-ink">{{ $d['nome'] ?: 'sem dado' }}</span>
                        <span class="{{ $selo }} bg-head text-ink-dim">{{ A::PAPEIS[$d['papel']] ?? ($d['papel'] ?? 'sem dado') }}</span>
                        <span class="w-[44px] shrink-0 font-mono text-[12px] text-ink-dim" title="Final do celular no cadastro do Gestor">{{ $falta($d, 'final') ? 'sem dado' : ($d['final'] ? '…'.$d['final'] : '—') }}</span>
                        @if ($falta($d, 'pendencia'))
                            <span class="{{ $selo }} bg-head text-ink-dim">sem dado</span>
                        @elseif ($d['pendencia'])
                            <span class="{{ $selo }} bg-warn-tint text-warn">{{ A::PENDENCIAS[$d['pendencia']] ?? $d['pendencia'] }}</span>
                        @else
                            <span class="{{ $selo }} bg-good-tint text-good">recebe</span>
                        @endif
                        {{-- Quem registrou vai no title: e-mail comprido cortado pela metade não serve a ninguém. --}}
                        <span class="w-[260px] shrink-0 text-right text-[11.5px] text-ink-faint" @if ($d['consentimento_por']) title="Consentimento registrado por {{ $d['consentimento_por'] }}" @endif>
                            @if ($d['parou_em'])pediu para parar {{ $quando($d['parou_em']) }}
                            @elseif ($d['consentimento_em'])consentimento {{ $quando($d['consentimento_em']) }}
                            @else{{ $falta($d, 'consentimento_em') ? 'sem dado' : 'sem consentimento' }}@endif
                        </span>
                        @if ($administra && $d['id'])
                            <span class="flex shrink-0 items-center gap-1.5">
                                @if (! $d['consentimento_em'] || $d['parou_em'])
                                    <x-confirmar :action="route('suporte.agenda-automatica.mudar', $d['id'])" confirmar="Registrar"
                                                 titulo="A pessoa concordou?"
                                                 :mensagem="($d['nome'] ?: 'Esta pessoa').' concordou em receber os avisos da agenda por este número. Fica registrado agora, em seu nome'.($d['parou_em'] ? ', e desfaz o pedido de parar.' : '.')"
                                                 :class="$botaoLinha">
                                        <x-slot name="campos"><input type="hidden" name="acao" value="consentimento"></x-slot>
                                        Registrar consentimento
                                    </x-confirmar>
                                @endif
                                <form method="POST" action="{{ route('suporte.agenda-automatica.mudar', $d['id']) }}">
                                    @csrf
                                    <input type="hidden" name="acao" value="{{ $d['ativo'] === false ? 'ativar' : 'desativar' }}">
                                    <button class="{{ $botaoLinha }}">{{ $d['ativo'] === false ? 'Ativar' : 'Desativar' }}</button>
                                </form>
                            </span>
                        @endif
                    </div>
                @empty
                    <p class="px-4 py-3 text-[12.5px] text-ink-mute">Ninguém na lista ainda: nada sai para ninguém.</p>
                @endforelse
            @endif

            @if ($administra)
                <form method="POST" action="{{ route('suporte.agenda-automatica.incluir') }}" class="grid gap-3 border-t border-line px-4 py-3 md:grid-cols-[1fr_160px_auto]">
                    @csrf
                    @if ($funcionarios === null)
                        <p class="text-[12.5px] text-ink-mute md:col-span-3">Sem dado: o Gestor não mandou a lista de funcionários.</p>
                    @else
                        <label><span class="{{ $rotulo }}">Funcionário ativo no Gestor</span>
                            <select name="funcionario_id" required class="{{ $campo }}">
                                <option value="">Escolha…</option>
                                @foreach ($funcionarios as $f)
                                    <option value="{{ $f['id'] }}" @selected((string) old('funcionario_id') === (string) $f['id'])>{{ $f['nome'] }}{{ $f['is_tecnico'] ? ' · técnico' : '' }} · {{ $numeroDe($f) }}</option>
                                @endforeach
                            </select></label>
                        <label><span class="{{ $rotulo }}">Recebe como</span>
                            <select name="papel" class="{{ $campo }}">
                                @foreach (A::PAPEIS as $k => $v)<option value="{{ $k }}" @selected(old('papel', 'tecnico') === $k)>{{ $v }}</option>@endforeach
                            </select></label>
                        <div class="flex items-end"><button class="{{ $botao }}">Incluir</button></div>
                        <label class="flex items-start gap-2 text-[12.5px] text-ink md:col-span-3">
                            <input type="checkbox" name="consentimento" value="1" class="mt-0.5" @checked(old('consentimento'))>
                            <span>A pessoa concordou em receber os avisos da agenda por este número.</span></label>
                        <p class="text-[12px] text-ink-mute md:col-span-3">Sem celular, ou com o mesmo celular de outro funcionário ativo, ela entra como pendente e nada sai até o cadastro ser corrigido no Gestor.</p>
                    @endif
                </form>
            @endif
        </section>

        {{-- Envios e ensaio --}}
        <section class="rounded-panel border border-line bg-surface">
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-2.5">
                <h2 class="flex-1 font-display text-[15px] font-semibold text-ink">O que saiu
                    <span class="text-[12.5px] font-normal text-ink-mute">· e, no ensaio, o que sairia</span></h2>
                @if ($administra)
                    <form method="POST" action="{{ route('suporte.agenda-automatica.previa') }}" class="flex flex-wrap items-center gap-1.5">
                        @csrf
                        <select name="tipo" class="{{ $filtro }}">
                            <option value="programacao">Programação</option>
                            <option value="relatorio">Relatório</option>
                        </select>
                        <select name="dia" class="{{ $filtro }}">
                            <option value="amanha">de amanhã</option>
                            <option value="hoje">de hoje</option>
                        </select>
                        <button class="{{ $botaoLinha }}" title="Grava como ensaio e não envia nada, em qualquer modo.">Prévia agora</button>
                    </form>
                @endif
            </div>
            <form method="GET" action="{{ route('suporte.agenda-automatica') }}" class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-2">
                <input type="date" name="dia" value="{{ $filtros['dia'] }}" class="{{ $filtro }}">
                <select name="tipo" class="{{ $filtro }}">
                    <option value="">Todos os tipos</option>
                    @foreach (A::TIPOS as $k => $v)<option value="{{ $k }}" @selected($filtros['tipo'] === $k)>{{ $v }}</option>@endforeach
                </select>
                <select name="situacao" class="{{ $filtro }}">
                    <option value="">Todas as situações</option>
                    @foreach (A::SITUACOES as $k => $v)<option value="{{ $k }}" @selected($filtros['situacao'] === $k)>{{ $v }}</option>@endforeach
                </select>
                <select name="modo" class="{{ $filtro }}">
                    <option value="">Ensaio e de verdade</option>
                    <option value="ensaio" @selected($filtros['modo'] === 'ensaio')>Só ensaio</option>
                    <option value="real" @selected($filtros['modo'] === 'real')>Só de verdade</option>
                </select>
                <button class="{{ $botaoLinha }}">Filtrar</button>
            </form>
            @php $envios = $avisos['avisos'] ?? null; @endphp
            @if ($envios === null)
                <p class="px-4 py-3 text-[12.5px] text-ink-mute">Sem dado.</p>
            @else
                @forelse ($envios as $a)
                    <div x-data="{ aberto: false }" class="border-b border-line last:border-b-0">
                        <button type="button" x-on:click="aberto = ! aberto" class="flex w-full flex-wrap items-center gap-2 px-4 py-2 text-left text-[13px] hover:bg-chip">
                            <span class="w-[44px] shrink-0 font-mono text-[11.5px] text-ink-faint">{{ $a['dia_agenda'] ? Carbon::parse($a['dia_agenda'])->format('d/m') : '—' }}</span>
                            <span class="w-[96px] shrink-0 text-ink-dim">{{ A::TIPOS[$a['tipo']] ?? ($a['tipo'] ?? 'sem dado') }}</span>
                            <span class="min-w-0 flex-1 truncate font-semibold text-ink">{{ $a['nome'] ?: 'sem dado' }}
                                <span class="font-normal text-ink-mute">· {{ A::PAPEIS[$a['papel']] ?? $a['papel'] }}{{ $a['final'] ? ' · …'.$a['final'] : '' }}</span></span>
                            @if ($a['modo'] === 'ensaio' && $a['situacao'] !== 'ensaio')<span class="{{ $selo }} bg-head text-ink-dim">ensaio</span>@endif
                            <span class="{{ $selo }} font-semibold {{ $tonsSituacao[$a['situacao']] ?? 'bg-head text-ink-dim' }}">{{ A::SITUACOES[$a['situacao']] ?? ($a['situacao'] ?? 'sem dado') }}</span>
                            @if ($a['pendencia'])<span class="{{ $selo }} bg-warn-tint text-warn">{{ A::PENDENCIAS[$a['pendencia']] ?? $a['pendencia'] }}</span>@endif
                            @if ($a['tentativas'] > 1)<span class="text-[11.5px] text-ink-faint">{{ $a['tentativas'] }} tentativas</span>@endif
                            <span class="w-[150px] shrink-0 whitespace-nowrap text-right text-[11.5px] text-ink-faint">
                                {{ $a['enviado_em'] ? 'enviado '.$quando($a['enviado_em']) : 'criado '.$quando($a['criado_em']) }}</span>
                        </button>
                        <div x-show="aberto" x-cloak class="space-y-2 px-4 pb-3 text-[12.5px]">
                            @if ($a['canal'] || $a['modelo'])
                                <p class="text-ink-mute">{{ $a['canal'] === 'modelo' ? 'Modelo' : 'Canal' }}: <span class="font-mono text-ink-dim">{{ $a['modelo'] ?: $a['canal'] }}</span></p>
                            @endif
                            @if ($a['erro'] || $a['erro_codigo'])
                                <p class="text-crit">Erro{{ $a['erro_codigo'] ? ' '.$a['erro_codigo'] : '' }}: {{ $a['erro'] }}</p>
                            @endif
                            @if ($a['texto'] !== null)
                                <pre class="whitespace-pre-wrap rounded-control border border-line bg-canvas px-3 py-2 font-sans text-[12.5px] text-ink">{{ $a['texto'] }}</pre>
                            @else
                                <p class="text-ink-mute">{{ $falta($a, 'texto') ? 'Sem dado.' : 'Sem texto: depois de 365 dias o Gestor guarda só tipo, dia e situação.' }}</p>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="px-4 py-6 text-center text-[13px] text-ink-mute">{{ array_filter($filtros) ? 'Nenhum aviso com esse filtro.' : 'Nenhum aviso ainda.' }}</p>
                @endforelse
                @if (($avisos['pagina'] ?? 1) > 1 || ($avisos['tem_mais'] ?? false))
                    <div class="flex items-center justify-between border-t border-line px-4 py-2 text-[12.5px]">
                        @if ($avisos['pagina'] > 1)
                            <a href="{{ route('suporte.agenda-automatica', array_filter($filtros) + ['pagina' => $avisos['pagina'] - 1]) }}" class="text-brand hover:text-brand-bright">&larr; Mais recentes</a>
                        @else<span></span>@endif
                        <span class="text-ink-faint">página {{ $avisos['pagina'] }}</span>
                        @if ($avisos['tem_mais'])
                            <a href="{{ route('suporte.agenda-automatica', array_filter($filtros) + ['pagina' => $avisos['pagina'] + 1]) }}" class="text-brand hover:text-brand-bright">Mais antigos &rarr;</a>
                        @else<span></span>@endif
                    </div>
                @endif
            @endif
        </section>
    </div>
</x-app-layout>
