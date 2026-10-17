<x-app-layout>
    <x-slot name="titulo">Mensagens</x-slot>
    <x-slot name="contexto"><span>só leitura · nada é enviado daqui</span></x-slot>

    {{-- Mensagens (desenho de 17/10/2026; docs/specs/secretario-emails-gestor).
         Lista à esquerda, mensagem à direita. Guardados só os metadados: a
         prévia e o texto são lidos AGORA no servidor de e-mail (BODY.PEEK, não
         marca como lida) e não ficam no Matriz. Nada é enviado daqui. --}}
    @php
        $campo = 'rounded-control border border-btn-line bg-canvas px-2.5 py-1.5 text-[13px] text-ink focus:border-brand focus:outline-none';
        $url = fn (array $mais) => route('secretario.emails.index', array_filter($mais + $filtros + ['ver' => $filtro], fn ($v) => $v !== null && $v !== ''));
        $situacao = fn ($e) => match (true) {
            $e->respondido => ['Respondida', 'bg-good-tint text-good'],
            $e->pede_retorno => ['Aguardando resposta', 'bg-warn-tint text-warn'],
            $e->urgencia === 'alta' => ['Urgente', 'bg-crit-tint text-crit'],
            ! $e->lido => ['Não lida', 'bg-brand/10 text-brand-text'],
            default => ['Lida', 'bg-head text-ink-mute'],
        };
        $avatar = ['bg-brand/10 text-brand-text', 'bg-retorno-tint text-retorno', 'bg-good-tint text-good', 'bg-warn-tint text-warn', 'bg-exame-tint text-exame', 'bg-crit-tint text-crit'];
        $lista = $emails->getCollection()->map(fn ($e) => [
            'id' => $e->id, 'de' => $e->de_nome ?: $e->de_email, 'email' => $e->de_email, 'assunto' => $e->assunto,
            'quando' => $e->recebido_em->format('d/m/Y H:i'), 'caixa' => $e->conta?->rotulo, 'situacao' => $situacao($e),
            'abrir' => $e->linkParaAbrir(), 'favorito' => $e->favorito,
            'rascunho' => route('secretario.emails.rascunho', $e->id), 'lembrar' => route('secretario.emails.lembrar', $e->id),
            'texto' => route('secretario.emails.texto', $e->id), 'estrela' => route('secretario.emails.favorito', $e->id),
        ])->values();
    @endphp
    <div x-data="mensagens(@js($lista))" x-init="iniciar()" class="mx-auto w-full max-w-7xl space-y-3">
        @if ($contas->isEmpty())
            <p class="rounded-panel border border-line bg-subtle px-4 py-4 text-[13.5px] text-ink-mute">
                Nenhuma caixa ligada. Ligue em <a href="{{ route('secretario.configuracoes') }}#emails" class="font-semibold text-brand-text">Secretário → Configurações → E-mail</a>.
            </p>
        @else
            <div class="flex flex-wrap items-center gap-2">
                @foreach (['importantes' => 'Importantes', 'aguardando' => 'Aguardando resposta', 'todas' => 'Todas'] as $k => $rot)
                    <a href="{{ $url(['ver' => $k, 'page' => null]) }}"
                       class="inline-flex h-[30px] items-center rounded-control border px-3 text-[12.5px] font-semibold {{ $filtro === $k ? 'border-brand bg-brand/10 text-brand-text' : 'border-btn-line text-ink-dim hover:text-brand' }}">{{ $rot }}</a>
                @endforeach
                <button type="button" @click="atualizar()" :disabled="atualizando" class="ml-auto inline-flex h-[30px] items-center gap-1.5 rounded-control border border-brand px-3 text-[12.5px] font-semibold text-brand-text hover:bg-brand/10 disabled:opacity-60">
                    <span class="h-3.5 w-3.5" :class="atualizando && 'animate-spin'"><x-nav-icon name="repeat" peso="2" /></span> <span x-text="atualizando ? 'Lendo…' : 'Atualizar agora'"></span>
                </button>
            </div>
            <p class="text-[12px] text-ink-mute">{{ app(\App\Services\Secretario\ConsultaDeEmails::class)->rodape(auth()->user()) }}</p>

            <div class="grid grid-cols-1 gap-3 lg:grid-cols-[minmax(0,420px)_minmax(0,1fr)]">
                {{-- Lista --}}
                <section class="min-w-0 rounded-panel border border-line bg-surface">
                    <form method="GET" class="space-y-2 border-b border-line p-3">
                        <input type="hidden" name="ver" value="{{ $filtro }}">
                        <label class="relative block">
                            <span class="pointer-events-none absolute left-2.5 top-2 h-4 w-4 text-ink-faint"><x-nav-icon name="search" /></span>
                            <input type="search" name="q" value="{{ $filtros['q'] ?? '' }}" placeholder="Buscar mensagens, cliente, assunto…" class="{{ $campo }} w-full pl-8">
                        </label>
                        <div class="grid grid-cols-3 gap-2">
                            <select name="conta" onchange="this.form.submit()" class="{{ $campo }} min-w-0">
                                <option value="">Todas as caixas</option>
                                @foreach ($contas as $c)<option value="{{ $c->id }}" @selected((string) ($filtros['conta'] ?? '') === (string) $c->id)>{{ $c->rotulo }}</option>@endforeach
                            </select>
                            <select name="status" onchange="this.form.submit()" class="{{ $campo }} min-w-0">
                                <option value="">Todos os status</option>
                                @foreach (['nao_lidos' => 'Não lidas', 'aguardando' => 'Aguardando resposta', 'respondidas' => 'Respondidas', 'favoritos' => 'Com estrela'] as $k => $r)
                                    <option value="{{ $k }}" @selected(($filtros['status'] ?? '') === $k)>{{ $r }}</option>
                                @endforeach
                            </select>
                            <select name="dias" onchange="this.form.submit()" class="{{ $campo }} min-w-0">
                                @foreach (['1' => 'Hoje', '3' => 'Últimos 3 dias', '7' => 'Últimos 7 dias', '30' => 'Últimos 30 dias'] as $k => $r)
                                    <option value="{{ $k }}" @selected($filtros['dias'] === (string) $k)>{{ $r }}</option>
                                @endforeach
                            </select>
                        </div>
                    </form>
                    <ul class="divide-y divide-line">
                        @forelse ($emails as $n => $e)
                            @php [$rotulo, $cor] = $situacao($e); $nome = $e->de_nome ?: $e->de_email; @endphp
                            <li>
                                <button type="button" @click="abrir({{ $e->id }})" class="flex w-full items-start gap-2.5 px-3 py-2.5 text-left hover:bg-head"
                                        :class="atual?.id === {{ $e->id }} && 'bg-brand/10'">
                                    <span class="mt-4 h-2 w-2 shrink-0 rounded-full {{ $e->lido ? 'bg-transparent' : 'bg-brand' }}" title="{{ $e->lido ? '' : 'não lida' }}"></span>
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-[12.5px] font-semibold {{ $avatar[crc32($nome) % count($avatar)] }}">{{ mb_strtoupper(mb_substr(preg_replace('/[^\pL]/u', '', $nome) ?: '?', 0, 2)) }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-baseline gap-2">
                                            <span class="min-w-0 flex-1 truncate text-[13px] {{ $e->lido ? 'text-ink-dim' : 'font-semibold text-ink' }}">{{ $nome }}</span>
                                            <span class="shrink-0 text-[11px] text-ink-faint">{{ $e->recebido_em->isToday() ? $e->recebido_em->format('H:i') : $e->recebido_em->format('d/m') }}</span>
                                        </span>
                                        <span class="block truncate text-[13px] {{ $e->lido ? 'text-ink' : 'font-semibold text-ink' }}">{{ $e->assunto }}</span>
                                        <span class="block truncate text-[12px] text-ink-mute" x-text="previas[{{ $e->id }}] ?? @js((string) $e->conta?->rotulo)"></span>
                                        <span class="mt-1 flex items-center gap-1.5">
                                            <span class="rounded-control px-1.5 py-0.5 text-[10.5px] font-semibold {{ $cor }}">{{ $rotulo }}</span>
                                            <span class="text-[10.5px] text-ink-faint">{{ $e->conta?->rotulo }}</span>
                                            <span class="ml-auto h-4 w-4 text-[15px] leading-4" :class="estrelas[{{ $e->id }}] ? 'text-warn' : 'text-ink-faint'" @click.stop="estrela({{ $e->id }})" x-text="estrelas[{{ $e->id }}] ? '★' : '☆'" title="Estrela (só aqui no Matriz)"></span>
                                        </span>
                                    </span>
                                </button>
                            </li>
                        @empty
                            <li class="px-4 py-6 text-center text-[13px] text-ink-mute">Nada neste filtro.</li>
                        @endforelse
                    </ul>
                    <div class="px-3 py-2">{{ $emails->links() }}</div>
                </section>

                {{-- Mensagem aberta --}}
                <section class="min-w-0 rounded-panel border border-line bg-surface">
                    <template x-if="!atual">
                        <p class="px-4 py-10 text-center text-[13.5px] text-ink-mute">Escolha uma mensagem à esquerda.</p>
                    </template>
                    <template x-if="atual">
                        <div class="space-y-3 p-4">
                            <h2 class="font-display text-[17px] font-semibold leading-snug text-ink" x-text="atual.assunto"></h2>
                            <div class="flex items-start gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand/10 text-[13px] font-semibold text-brand-text" x-text="iniciais(atual.de)"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-[13.5px] text-ink"><b x-text="atual.de"></b> <span class="text-ink-mute" x-text="'<' + atual.email + '>'"></span></p>
                                    <p class="text-[12px] text-ink-mute" x-text="'para ' + (atual.caixa || '—')"></p>
                                </div>
                                <span class="shrink-0 text-[12px] text-ink-mute" x-text="atual.quando"></span>
                                <button type="button" @click="estrela(atual.id)" class="shrink-0 text-[17px] leading-none" :class="estrelas[atual.id] ? 'text-warn' : 'text-ink-faint hover:text-warn'" x-text="estrelas[atual.id] ? '★' : '☆'" title="Estrela"></button>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-control px-2 py-0.5 text-[12px] font-semibold" :class="atual.situacao[1]" x-text="atual.situacao[0]"></span>
                                <a x-show="atual.abrir" :href="atual.abrir" target="_blank" rel="noopener noreferrer" class="ml-auto inline-flex h-[28px] items-center gap-1.5 rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim hover:border-brand hover:text-brand">
                                    <span class="h-3.5 w-3.5"><x-nav-icon name="envelope" /></span> Abrir no e-mail
                                </a>
                            </div>
                            <div class="rounded-ctl border border-line bg-canvas px-4 py-3">
                                <p x-show="carregando" class="text-[13px] text-ink-faint">Lendo a mensagem no servidor de e-mail…</p>
                                <p x-show="!carregando && erroTexto" class="text-[13px] text-warn">Não consegui ler o texto agora. Use “Abrir no e-mail”.</p>
                                <pre x-show="!carregando && !erroTexto" class="max-h-[52vh] overflow-y-auto whitespace-pre-wrap break-words font-mono text-[12.5px] leading-relaxed text-ink" x-text="texto"></pre>
                            </div>
                            <p class="text-[11.5px] text-ink-faint">Texto lido agora no servidor, sem marcar como lida e sem guardar no Matriz. Anexos: abra no e-mail.</p>

                            <div>
                                <p class="mb-1.5 text-[13px] font-semibold text-ink">Ações rápidas</p>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" @click="acao(atual.rascunho)" class="inline-flex h-[30px] items-center gap-1.5 rounded-control bg-brand px-3 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright">
                                        <span class="h-3.5 w-3.5"><x-nav-icon name="chat" peso="2" /></span> Preparar resposta (IA)
                                    </button>
                                    <button type="button" @click="acao(atual.lembrar)" class="inline-flex h-[30px] items-center gap-1.5 rounded-control border border-btn-line px-3 text-[12.5px] font-semibold text-ink-dim hover:border-brand hover:text-brand">
                                        <span class="h-3.5 w-3.5"><x-nav-icon name="bell" /></span> Lembrar de responder (amanhã 9h)
                                    </button>
                                    <button type="button" @click="agenda.aberto = !agenda.aberto" class="inline-flex h-[30px] items-center gap-1.5 rounded-control border border-btn-line px-3 text-[12.5px] font-semibold text-ink-dim hover:border-brand hover:text-brand">
                                        <span class="h-3.5 w-3.5"><x-nav-icon name="calendar" /></span> Adicionar à agenda
                                    </button>
                                </div>
                                <form x-show="agenda.aberto" x-cloak @submit.prevent="agendar()" class="mt-2 flex flex-wrap items-center gap-2 rounded-ctl border border-line p-2">
                                    <input type="date" x-model="agenda.data" required class="{{ $campo }}">
                                    <input type="time" x-model="agenda.hora" class="{{ $campo }}" title="Sem hora vira tarefa do dia">
                                    <button class="h-[30px] rounded-control bg-brand px-3 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright">Criar na agenda</button>
                                </form>
                                <p x-show="recado" x-cloak x-text="recado" class="mt-2 rounded-ctl bg-head px-3 py-2 text-[12.5px] text-ink"></p>
                            </div>
                        </div>
                    </template>
                </section>
            </div>
        @endif
    </div>

    <script>
        function mensagens(lista) {
            const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
            const hoje = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
            return {
                lista, atual: null, texto: '', carregando: false, erroTexto: false, recado: '', atualizando: false, previas: {},
                estrelas: Object.fromEntries(lista.map(e => [e.id, e.favorito])),
                agenda: { aberto: false, data: hoje(), hora: '' },
                iniciar() {
                    if (lista.length && window.innerWidth >= 1024) this.abrir(lista[0].id);
                    if (lista.length) fetch(@json(route('secretario.emails.previas')), { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                        body: JSON.stringify({ ids: lista.map(e => e.id) }) }).then(r => r.json()).then(j => this.previas = j.previas || {}).catch(() => {});
                },
                iniciais(n) { return (n || '?').replace(/[^\p{L}]/gu, '').slice(0, 2).toUpperCase() || '?'; },
                async abrir(id) {
                    this.atual = lista.find(e => e.id === id); this.recado = ''; this.agenda.aberto = false;
                    this.carregando = true; this.erroTexto = false; this.texto = '';
                    try { const r = await fetch(this.atual.texto, { headers: { Accept: 'application/json' } }); const j = await r.json(); this.erroTexto = !r.ok; this.texto = j.texto || ''; }
                    catch (e) { this.erroTexto = true; }
                    this.carregando = false;
                    if (window.innerWidth < 1024) this.$nextTick(() => this.$root.querySelector('section:last-of-type')?.scrollIntoView({ behavior: 'smooth' }));
                },
                async acao(url) {
                    this.recado = '…';
                    const r = await fetch(url, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() } });
                    const j = await r.json().catch(() => ({})); this.recado = j.mensagem || (r.ok ? 'Feito.' : 'Não deu certo.');
                },
                async estrela(id) {
                    const e = lista.find(x => x.id === id);
                    const r = await fetch(e.estrela, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() } });
                    if (r.ok) this.estrelas[id] = (await r.json()).favorito;
                },
                async agendar() {
                    const r = await fetch(@json(route('secretario.agenda.novo')), { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                        body: JSON.stringify({ titulo: ('Retorno: ' + this.atual.de + ' — ' + this.atual.assunto).slice(0, 190), data: this.agenda.data, hora: this.agenda.hora || null }) });
                    const j = await r.json().catch(() => ({}));
                    this.recado = r.ok ? j.mensagem : (Object.values(j.errors || {}).flat()[0] || 'Não deu certo.');
                    if (r.ok) this.agenda.aberto = false;
                },
                async atualizar() {
                    this.atualizando = true;
                    for (const id of @json($contas->pluck('id'))) {
                        await fetch(@json(url('secretario/emails/contas')) + '/' + id + '/ler', { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() } }).catch(() => {});
                    }
                    location.reload();
                },
            };
        }
    </script>
</x-app-layout>
