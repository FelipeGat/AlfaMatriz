@php
    use App\Models\SuporteMensagem;
    $botao = 'inline-flex h-[28px] items-center gap-1 whitespace-nowrap rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim hover:border-brand hover:text-brand';
    $nomeDe = fn ($c) => $c->cliente ? ($c->cliente->nome_fantasia ?: $c->cliente->nome) : ($c->nome_perfil ?: $c->telefoneLegivel());
    $campo = 'rounded-control border border-btn-line bg-canvas py-1 pl-2 pr-7 text-[12.5px] text-ink';
    // Situação da conversa, no molde do Gestor: de quem ela é agora.
    $situacaoDe = fn ($c) => $c->situacao === 'gestor' ? ['No Gestor'.($c->gestor_setor ? ' ('.$c->gestor_setor.')' : ''), 'bg-head text-ink-dim']
        : ($c->comHumano() ? ['Com '.($c->humano?->name ? explode(' ', $c->humano->name)[0] : 'humano'), 'bg-warn-tint text-warn'] : ['Com o Pedro', 'bg-brand/10 text-brand-text']);
    $minutos = fn (?int $s) => $s === null ? '—' : ($s < 60 ? $s.' s' : intdiv($s, 60).' min');
    $prefixo = ['pedro' => 'Pedro: ', 'humano_app' => 'Equipe: ', 'humano_matriz' => 'Equipe: '];
@endphp
<x-app-layout>
    <x-slot name="titulo">Suporte WhatsApp</x-slot>
    <x-slot name="contexto"><span>(27) 4042-4157 · API oficial · o Pedro atende das 07:00 às 18:00</span></x-slot>

    {{-- docs/specs/suporte-pedro-whatsapp: as conversas do número de suporte. Quem
         responde por aqui (ou pelo app do celular) assume a conversa, e o Pedro fica
         quieto nela até alguém devolver. --}}
    <div class="mx-auto w-full max-w-7xl space-y-3"
         x-data="{ marca: null }"
         x-init="setInterval(async () => { try { const j = await (await fetch(@js(route('suporte.marca')), { headers: { Accept: 'application/json' } })).json();
                    if (marca === null) { marca = j.marca; return; }
                    if (j.marca !== marca && ! (document.getElementById('texto-resposta')?.value)) location.reload(); } catch (e) {} }, 10000)">
        @include('suporte._abas', ['aba' => 'conversas'])
        @if (session('status'))<x-aviso>{{ session('status') }}</x-aviso>@endif
        @if ($errors->any())<x-aviso tom="critico" :segundos="0">{{ $errors->first() }}</x-aviso>@endif
        @if (! $configurado)
            <x-aviso tom="atencao" :segundos="0">O número de suporte ainda não está ligado à API oficial do WhatsApp: as conversas aparecem aqui quando a Meta aprovar e a configuração for feita no servidor.</x-aviso>
        @endif

        {{-- Indicadores do dia, sobre as conversas e os pedidos do Pedro (não sobre o filtro). --}}
        <div class="flex flex-wrap gap-2">
            @foreach ([
                ['Recebidas hoje', $indicadores['recebidas_hoje'], 'text-ink'],
                ['Com o Pedro', $indicadores['com_pedro'], 'text-brand-text'],
                ['Com humano', $indicadores['com_humano'], 'text-ink'],
                ['Na fila', $indicadores['na_fila'], $indicadores['na_fila'] > 0 ? 'text-crit' : 'text-ink'],
                ['No Gestor', $indicadores['no_gestor'], 'text-ink'],
                ['Esperando resposta', $indicadores['esperando'], $indicadores['esperando'] > 0 ? 'text-crit' : 'text-ink'],
                ['1ª resposta (méd.)', $minutos($indicadores['primeira_resposta']), 'text-ink'],
                ['Ao especialista hoje', $indicadores['especialista_hoje'], 'text-ink'],
                ['Atendimentos hoje', $indicadores['atendimentos_hoje'], 'text-ink'],
                ['Avaliação (30 dias)', $indicadores['avaliacao']?->n ? number_format((float) $indicadores['avaliacao']->media, 1, ',', '').' · '.$indicadores['avaliacao']->n.' nota(s)' : '—', 'text-ink'],
            ] as [$rotulo, $valor, $cor])
                <div class="min-w-[112px] rounded-panel border border-line bg-surface px-3 py-1.5">
                    <div class="text-[18px] font-semibold leading-tight {{ $cor }}">{{ $valor }}</div>
                    <div class="text-[10.5px] text-ink-mute">{{ $rotulo }}</div>
                </div>
            @endforeach
        </div>

        {{-- Filtros (os mesmos do Gestor, com a situação do suporte no lugar do setor). --}}
        <form method="GET" action="{{ route('suporte.conversas') }}" class="flex flex-wrap items-center gap-2 rounded-panel border border-line bg-surface px-3 py-2">
            <input type="search" name="busca" value="{{ $filtros['busca'] }}" placeholder="Buscar cliente ou número" class="w-52 rounded-control border border-btn-line bg-canvas px-2 py-1 text-[12.5px] text-ink">
            <select name="situacao" class="{{ $campo }}">
                <option value="">Situação: todas</option>
                @foreach (\App\Http\Controllers\SuporteConversasController::SITUACOES as $chave => $rotulo)
                    <option value="{{ $chave }}" @selected($filtros['situacao'] === $chave)>{{ $rotulo }}</option>
                @endforeach
            </select>
            <select name="atendente" class="{{ $campo }}">
                <option value="">Atendente: todos</option>
                <option value="sem" @selected($filtros['atendente'] === 'sem')>Sem responsável</option>
                @foreach ($atendentes as $a)
                    <option value="{{ $a->id }}" @selected($filtros['atendente'] === (string) $a->id)>{{ $a->id === auth()->id() ? 'Eu' : $a->name }}</option>
                @endforeach
            </select>
            <select name="periodo" class="{{ $campo }}">
                <option value="">Período: tudo</option>
                @foreach (['hoje' => 'Hoje', 'ontem' => 'Ontem', '7dias' => '7 dias', '30dias' => '30 dias'] as $chave => $rotulo)
                    <option value="{{ $chave }}" @selected($filtros['periodo'] === $chave)>{{ $rotulo }}</option>
                @endforeach
            </select>
            {{-- A janela da Meta é independente da situação: dá para estar com o Pedro com ela encerrada. --}}
            <select name="janela" class="{{ $campo }}">
                <option value="">Janela Meta: todas</option>
                <option value="aberta" @selected($filtros['janela'] === 'aberta')>Aberta</option>
                <option value="fechada" @selected($filtros['janela'] === 'fechada')>Encerrada</option>
            </select>
            <label class="flex items-center gap-1 text-[12.5px] text-ink-dim"><input type="checkbox" name="nao_lidas" value="1" @checked($filtros['nao_lidas']) class="rounded border-btn-line"> Não lidas</label>
            <button class="{{ $botao }}">Filtrar</button>
            @if (array_filter([$filtros['busca'], $filtros['situacao'], $filtros['atendente'], $filtros['periodo'], $filtros['janela']]) || $filtros['nao_lidas'])
                <a href="{{ route('suporte.conversas') }}" class="text-[12px] text-ink-mute hover:underline">Limpar filtros</a>
            @endif
        </form>

        <div class="grid min-h-[70vh] gap-3 lg:grid-cols-[340px_minmax(0,1fr)]">
            {{-- Conversas --}}
            <section class="rounded-panel border border-line bg-surface">
                <p class="border-b border-line px-4 py-2.5 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Conversas ({{ $lista->count() }})</p>
                <ul class="max-h-[75vh] divide-y divide-line overflow-y-auto">
                    @forelse ($lista as $c)
                        @php [$sit, $sitCor] = $situacaoDe($c); @endphp
                        <li>
                            <a href="{{ route('suporte.conversas', ['conversa' => $c->id] + array_filter($filtros)) }}" class="flex items-start gap-2.5 px-4 py-2.5 hover:bg-head/50 {{ $atual?->id === $c->id ? 'bg-brand/10' : '' }}">
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="truncate text-[13.5px] font-semibold text-ink">{{ $nomeDe($c) }}</span>
                                        <span class="shrink-0 whitespace-nowrap text-[10.5px] text-ink-faint" title="{{ $c->ultima_mensagem_em?->format('d/m/Y H:i') }}">{{ $c->ultima_mensagem_em?->diffForHumans(null, true) }}</span>
                                    </span>
                                    <span class="block truncate text-[11.5px] text-ink-mute">{{ $c->cliente ? $c->telefoneLegivel() : 'Sem cadastro · '.$c->telefoneLegivel() }}</span>
                                    <span class="block truncate text-[12px] text-ink-dim">{{ ($prefixo[$c->ultima_direcao] ?? '').($c->ultimo_texto ?? '—') }}</span>
                                    <span class="mt-1 flex flex-wrap items-center gap-1">
                                        <span class="rounded-badge px-1.5 py-0.5 text-[10.5px] font-semibold {{ $sitCor }}">{{ $sit }}</span>
                                        @if (\App\Http\Controllers\SuporteConversasController::atrasada($c))
                                            <span class="rounded-badge bg-crit-tint px-1.5 py-0.5 text-[10.5px] font-semibold text-crit" title="Cliente esperando desde {{ $c->ultima_entrada_em->format('H:i') }}">ATRASADO</span>
                                        @endif
                                        @unless ($c->janelaAberta())<span class="rounded-badge bg-head px-1.5 py-0.5 text-[10.5px] text-ink-faint" title="Passaram 24 h da última mensagem do cliente">Janela encerrada</span>@endunless
                                        @if ($c->nao_lidas > 0)<span class="ml-auto rounded-full bg-brand px-1.5 text-[11px] font-semibold text-on-brand">{{ $c->nao_lidas }}</span>@endif
                                    </span>
                                </span>
                            </a>
                        </li>
                    @empty
                        <li class="px-4 py-8 text-center text-[13px] text-ink-mute">{{ array_filter([$filtros['busca'], $filtros['situacao'], $filtros['atendente'], $filtros['periodo'], $filtros['janela']]) || $filtros['nao_lidas'] ? 'Nenhuma conversa com esses filtros.' : 'Nenhuma conversa ainda.' }}</li>
                    @endforelse
                </ul>
            </section>

            {{-- A conversa --}}
            <section class="flex min-h-0 flex-col rounded-panel border border-line bg-surface">
                @if (! $atual)
                    <div class="m-auto px-6 py-16 text-center text-[13.5px] text-ink-mute">Escolha uma conversa à esquerda.</div>
                @else
                    <div class="flex flex-wrap items-start gap-3 border-b border-line px-4 py-3">
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate font-display text-[16px] font-semibold text-ink">{{ $nomeDe($atual) }}</h2>
                            <p class="text-[12px] text-ink-mute">{{ $atual->telefoneLegivel() }}{{ $atual->nome_perfil && $atual->cliente ? ' · no WhatsApp: '.$atual->nome_perfil : '' }}</p>
                            @if ($atual->cliente)
                                <p class="mt-1 flex flex-wrap gap-1">
                                    @forelse ($atual->cliente->sistemas->where('pivot.ativo', true) as $s)
                                        <span class="rounded-badge bg-head px-1.5 py-0.5 text-[11px] text-ink-dim">{{ $s->nome }}</span>
                                    @empty
                                        <span class="text-[11.5px] text-ink-faint">Sem sistema ativo no cadastro.</span>
                                    @endforelse
                                    @foreach ($atual->cliente->contratos->where('status', 'ativo') as $ct)
                                        <span class="rounded-badge bg-good-tint px-1.5 py-0.5 text-[11px] text-good">Contrato {{ $ct->plano ?: 'ativo' }}</span>
                                    @endforeach
                                </p>
                            @endif
                            @if ($atual->tarefa)
                                <p class="mt-1 text-[12px]"><a href="{{ route('tarefas.index', ['tarefa' => $atual->tarefa_id]) }}" class="text-brand-text hover:underline">Atendimento T-{{ $atual->tarefa_id }}</a> · {{ \App\Models\Tarefa::STATUS[$atual->tarefa->status] ?? $atual->tarefa->status }}</p>
                            @endif
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            @if ($atual->comHumano())
                                @if (! $atual->humano_id && $atual->fila_desde)
                                    {{-- A fila (21/10/2026): o Pedro passou a conversa e ninguém assumiu ainda. --}}
                                    <span class="self-center text-[12px] text-crit" title="{{ $atual->fila_motivo }}">Na fila desde {{ $atual->fila_desde->format('d/m H:i') }}</span>
                                    <form method="POST" action="{{ route('suporte.assumir', $atual->id) }}">@csrf<button class="{{ $botao }}">Assumir</button></form>
                                @else
                                    <span class="self-center text-[12px] text-warn">Com {{ $atual->humano?->name ?? 'humano (celular)' }}{{ $atual->humano_ate ? ' até '.$atual->humano_ate->format('d/m H:i') : '' }}</span>
                                @endif
                                <form method="POST" action="{{ route('suporte.devolver', $atual->id) }}">@csrf<button class="{{ $botao }}">Devolver ao Pedro</button></form>
                            @elseif ($atual->situacao === 'avaliacao')
                                <span class="self-center text-[12px] text-ink-mute">Encerrado: aguardando a avaliação do cliente</span>
                            @else
                                <form method="POST" action="{{ route('suporte.assumir', $atual->id) }}">@csrf<button class="{{ $botao }}">Assumir</button></form>
                            @endif
                            @if ($atual->situacao !== 'avaliacao')
                                {{-- Encerramento com avaliação (como no chat da Intelbras, 21/10/2026). --}}
                                <form method="POST" action="{{ route('suporte.encerrar', $atual->id) }}" onsubmit="return confirm('Encerrar o atendimento e pedir a avaliação ao cliente?')">@csrf<button class="{{ $botao }}">Encerrar e pedir avaliação</button></form>
                            @endif
                            @php $nota = \App\Models\SuporteAvaliacao::where('conversa_id', $atual->id)->whereNotNull('nota')->latest('id')->first(); @endphp
                            @if ($nota)
                                <span class="self-center text-[12px] {{ $nota->nota <= 2 ? 'text-crit' : 'text-ink-mute' }}" title="{{ $nota->comentario }}">Última avaliação: {{ $nota->nota }} ({{ \App\Models\SuporteAvaliacao::NOTAS[$nota->nota] }}){{ $nota->comentario ? ' — “'.\Illuminate\Support\Str::limit($nota->comentario, 60).'”' : '' }}</span>
                            @endif
                        </div>
                    </div>

                    @if ($reg = $atual->atendimento)
                        {{-- O registro do atendimento: prioridade e prazo do roteiro, e o nº do Gestor que é obrigatório informar. --}}
                        <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-2.5 text-[12.5px] {{ $reg->faltaNumeroDoGestor() ? 'bg-warn-tint/40' : '' }}">
                            @if ($reg->prioridade)
                                <span class="rounded-badge px-1.5 py-0.5 text-[11px] font-semibold {{ $reg->prioridade === 'critica' ? 'bg-crit-tint text-crit' : 'bg-head text-ink-dim' }}">{{ \App\Models\SuporteRoteiro::PRIORIDADES[$reg->prioridade] ?? $reg->prioridade }}</span>
                            @endif
                            @if ($reg->roteiro)<span class="text-ink-dim">{{ $reg->roteiro->titulo }}</span>@endif
                            @if ($reg->prazo_em)
                                <span class="{{ $reg->prazo_em->isPast() ? 'font-semibold text-crit' : 'text-ink-dim' }}">Prazo: {{ $reg->prazo_em->format('d/m H:i') }}{{ $reg->prazo_em->isPast() ? ' (estourado)' : '' }}</span>
                            @endif
                            @if ($reg->gestor_numero)
                                <span class="text-good">Gestor nº {{ $reg->gestor_numero }}{{ $reg->registradoPor ? ' · por '.$reg->registradoPor->name : '' }}</span>
                            @elseif ($reg->destino === 'gestor')
                                <form method="POST" action="{{ route('suporte.gestor-numero', $atual->id) }}" class="ml-auto flex flex-wrap items-center gap-2">
                                    @csrf
                                    <span class="font-semibold text-warn">Lance no Gestor e informe o nº:</span>
                                    <input name="gestor_numero" required maxlength="30" placeholder="nº no Gestor" class="w-28 rounded-control border border-btn-line bg-canvas px-2 py-1 text-[12.5px] text-ink">
                                    <button class="{{ $botao }}">Registrar</button>
                                </form>
                            @endif
                        </div>
                    @endif

                    @if ($registroProposto)
                        {{-- O registro que o Pedro propôs para a base (T-476): só vale depois que alguém confirmar. --}}
                        <div class="flex flex-wrap items-center gap-2 border-b border-line bg-warn-tint/40 px-4 py-2.5 text-[12.5px]">
                            <span class="text-ink-dim">O Pedro propôs o registro deste atendimento para a base: <b class="text-ink">{{ $registroProposto->titulo }}</b>
                                ({{ \App\Models\SuporteNota::SITUACOES[$registroProposto->situacao] ?? $registroProposto->situacao }}).</span>
                            <a href="{{ route('suporte.base.ver', $registroProposto->id) }}" class="{{ $botao }} ml-auto">Conferir e confirmar</a>
                        </div>
                    @endif

                    @if (! $atual->cliente_id)
                        <form method="POST" action="{{ route('suporte.vincular', $atual->id) }}" class="flex flex-wrap items-center gap-2 border-b border-line bg-warn-tint/40 px-4 py-2.5 text-[12.5px]">
                            @csrf
                            <span class="text-ink-dim">Número sem cadastro. De qual cliente é?</span>
                            <select name="cliente_id" required class="rounded-control border border-btn-line bg-canvas py-1 pl-2 pr-7 text-[12.5px] text-ink">
                                <option value="">Escolha…</option>
                                @foreach ($clientes as $cl)<option value="{{ $cl->id }}">{{ $cl->nome_fantasia ?: $cl->nome }}</option>@endforeach
                            </select>
                            <button class="{{ $botao }}">Ligar ao cliente</button>
                        </form>
                    @endif

                    <div class="flex-1 space-y-2 overflow-y-auto px-4 py-3" style="max-height: 58vh" x-init="$el.scrollTop = $el.scrollHeight">
                        @foreach ($atual->mensagens as $m)
                            <div class="flex {{ $m->daEmpresa() ? 'justify-end' : 'justify-start' }}">
                                <div class="max-w-[80%] rounded-panel px-3 py-2 text-[13px] {{ $m->daEmpresa() ? 'bg-brand/10 text-ink' : 'border border-line bg-canvas text-ink' }}">
                                    @if (in_array($m->tipo, ['image', 'audio', 'video'], true))
                                        {{-- A mídia vem do disco do Matriz, ou é buscada no Gestor na primeira vez. --}}
                                        @if ($m->tipo === 'image')
                                            <a href="{{ route('suporte.midia', $m->id) }}" target="_blank"><img src="{{ route('suporte.midia', $m->id) }}" alt="Foto do cliente" loading="lazy" class="mb-1 max-h-72 max-w-full rounded-control"></a>
                                        @elseif ($m->tipo === 'audio')
                                            <audio controls preload="none" src="{{ route('suporte.midia', $m->id) }}" class="mb-1 w-64 max-w-full"></audio>
                                        @else
                                            <video controls preload="metadata" src="{{ route('suporte.midia', $m->id) }}" class="mb-1 max-h-72 max-w-full rounded-control"></video>
                                        @endif
                                        @php $legenda = trim(preg_replace('/^\[(imagem|áudio|vídeo)\]/u', '', (string) $m->texto)); @endphp
                                        @if ($legenda !== '')<p class="whitespace-pre-line break-words">{{ $legenda }}</p>@endif
                                        @if ($m->transcricao)<p class="mt-1 border-l-2 border-line pl-2 text-[12px] italic text-ink-dim">{{ $m->tipo === 'video' ? 'O que o Pedro viu e ouviu: ' : 'Transcrição: ' }}{{ $m->transcricao }}</p>@endif
                                    @else
                                        <p class="whitespace-pre-line break-words">{{ $m->texto }}</p>
                                    @endif
                                    {{-- De onde veio a resposta do Pedro: as notas da base do suporte que ele usou (T-476). --}}
                                    @if ($m->fontes)
                                        <p class="mt-1 border-l-2 border-line pl-2 text-[11px] text-ink-dim">Fontes:
                                            @foreach ($m->fontes as $f)
                                                @if (isset($fontes[$f]))<a href="{{ route('suporte.base.ver', $f) }}" class="text-brand-text hover:underline">{{ $fontes[$f] }}</a>@else<span>nota {{ $f }} (apagada)</span>@endif{{ $loop->last ? '' : ' · ' }}
                                            @endforeach
                                        </p>
                                    @endif
                                    <p class="mt-1 text-[10.5px] text-ink-faint">
                                        {{ $m->direcao === 'humano_matriz' ? ($m->autor?->name ?? 'Humano') : SuporteMensagem::DIRECOES[$m->direcao] ?? $m->direcao }}
                                        · {{ ($m->enviada_em ?? $m->created_at)->format('d/m H:i') }}{{ $m->daEmpresa() && $m->status ? ' · '.$m->status : '' }}
                                        @if ($m->erro)<span class="text-crit"> · {{ $m->erro }}</span>@endif
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <form method="POST" action="{{ route('suporte.responder', $atual->id) }}" class="border-t border-line px-4 py-3">
                        @csrf
                        @if ($atual->situacao === 'gestor')
                            <p class="text-[12.5px] text-ink-mute">Conversa entregue ao {{ $atual->gestor_setor }} no Gestor: a equipe desse setor responde por lá.</p>
                        @elseif ($atual->janelaAberta())
                            @php $resta = (int) now()->diffInMinutes($atual->ultima_entrada_em->copy()->addHours(\App\Models\SuporteConversa::JANELA_HORAS)); @endphp
                            <p class="mb-1.5 text-[11px] text-ink-faint">Janela de resposta aberta por mais {{ intdiv($resta, 60) ? intdiv($resta, 60).' h ' : '' }}{{ $resta % 60 }} min. Enter envia; Shift+Enter quebra a linha.</p>
                            <div class="flex items-end gap-2">
                                <textarea id="texto-resposta" name="texto" rows="2" maxlength="4000" required placeholder="Responder como suporte (o Pedro sai da conversa)…" class="min-w-0 flex-1 rounded-control border border-btn-line bg-canvas px-2.5 py-1.5 text-[13px] text-ink focus:border-brand focus:outline-none"
                                          @keydown.enter="if (! $event.shiftKey && $el.value.trim()) { $event.preventDefault(); $el.form.requestSubmit(); }"></textarea>
                                <button class="h-[34px] rounded-control bg-brand px-4 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright">Enviar</button>
                            </div>
                        @else
                            <p class="text-[12.5px] text-ink-mute">Passaram 24 h da última mensagem do cliente: o WhatsApp só deixa responder quando ele escrever de novo.</p>
                        @endif
                    </form>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
