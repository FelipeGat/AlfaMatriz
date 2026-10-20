@php
    use App\Models\SuporteMensagem;
    $botao = 'inline-flex h-[28px] items-center gap-1 whitespace-nowrap rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim hover:border-brand hover:text-brand';
    $nomeDe = fn ($c) => $c->cliente ? ($c->cliente->nome_fantasia ?: $c->cliente->nome) : ($c->nome_perfil ?: $c->telefoneLegivel());
@endphp
<x-app-layout>
    <x-slot name="titulo">Suporte WhatsApp</x-slot>
    <x-slot name="contexto"><span>(27) 3109-3265 · API oficial · o Pedro atende das 07:00 às 18:00</span></x-slot>

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

        <div class="grid min-h-[70vh] gap-3 lg:grid-cols-[320px_minmax(0,1fr)]">
            {{-- Conversas --}}
            <section class="rounded-panel border border-line bg-surface">
                <p class="border-b border-line px-4 py-2.5 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Conversas ({{ $lista->count() }})</p>
                <ul class="max-h-[75vh] divide-y divide-line overflow-y-auto">
                    @forelse ($lista as $c)
                        <li>
                            <a href="{{ route('suporte.conversas', $c->id) }}" class="flex items-start gap-2.5 px-4 py-2.5 hover:bg-head/50 {{ $atual?->id === $c->id ? 'bg-brand/10' : '' }}">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[13.5px] font-semibold text-ink">{{ $nomeDe($c) }}</span>
                                    <span class="block truncate text-[11.5px] text-ink-mute">{{ $c->cliente ? $c->telefoneLegivel() : 'Sem cadastro · '.$c->telefoneLegivel() }}</span>
                                    <span class="mt-0.5 block text-[11px] {{ $c->comHumano() || $c->situacao === 'gestor' ? 'text-warn' : 'text-ink-faint' }}">{{ $c->situacao === 'gestor' ? 'No Gestor ('.$c->gestor_setor.')' : ($c->comHumano() ? 'Com humano' : 'Com o Pedro') }}{{ $c->ultima_mensagem_em ? ' · '.$c->ultima_mensagem_em->format('d/m H:i') : '' }}</span>
                                </span>
                                @if ($c->nao_lidas > 0)<span class="mt-0.5 rounded-full bg-brand px-1.5 text-[11px] font-semibold text-on-brand">{{ $c->nao_lidas }}</span>@endif
                            </a>
                        </li>
                    @empty
                        <li class="px-4 py-8 text-center text-[13px] text-ink-mute">Nenhuma conversa ainda.</li>
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
                                <span class="self-center text-[12px] text-warn">Com {{ $atual->humano?->name ?? 'humano (celular)' }}{{ $atual->humano_ate ? ' até '.$atual->humano_ate->format('d/m H:i') : '' }}</span>
                                <form method="POST" action="{{ route('suporte.devolver', $atual->id) }}">@csrf<button class="{{ $botao }}">Devolver ao Pedro</button></form>
                            @else
                                <form method="POST" action="{{ route('suporte.assumir', $atual->id) }}">@csrf<button class="{{ $botao }}">Assumir</button></form>
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
                                    <p class="whitespace-pre-line break-words">{{ $m->texto }}</p>
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
                            <div class="flex items-end gap-2">
                                <textarea id="texto-resposta" name="texto" rows="2" maxlength="4000" required placeholder="Responder como suporte (o Pedro sai da conversa)…" class="min-w-0 flex-1 rounded-control border border-btn-line bg-canvas px-2.5 py-1.5 text-[13px] text-ink focus:border-brand focus:outline-none"></textarea>
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
