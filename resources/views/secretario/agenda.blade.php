<x-app-layout>
    <x-slot name="titulo">Minha agenda</x-slot>
    <x-slot name="contexto">
        <span>Secretário · Google · Agenda do Matriz</span>
    </x-slot>
    <x-slot name="acoes">
        <a href="{{ route('secretario.meu-dia') }}"
           class="h-[34px] px-3 inline-flex items-center gap-1.5 rounded-control border border-btn-line text-ink-dim text-[12.5px] font-semibold hover:text-brand hover:border-brand transition whitespace-nowrap">
            Meu Dia
        </a>
    </x-slot>

    {{-- Calendário no jeito do Gestor (desenho de 17/10/2026; FR-006 de
         docs/specs/secretario-central-do-dia): Dia, Semana, Mês e Agenda, grade
         de horas de 48 px que se ajusta aos eventos, coluna de hoje marcada,
         linha do agora e filtro por calendário. "Novo evento" cria um
         compromisso do Secretário (com lembrete) — nunca um evento no Google
         sem confirmação. Sem o Google em dia, o aviso diz a limitação. --}}
    @php
        $campo = 'w-full rounded-control border border-btn-line bg-canvas px-3 py-2 text-[13.5px] text-ink focus:border-brand focus:outline-none';
        $calendarios = [
            'google' => ['Google Agenda', 'bg-exame', 'border-exame bg-exame-tint'], 'secretario' => ['Secretário (Matriz)', 'bg-warn', 'border-warn bg-warn-tint'],
            'matriz' => ['Agenda do Matriz', 'bg-good', 'border-good bg-good-tint'], 'grupo_solucoes' => ['Grupo Soluções', 'bg-retorno', 'border-retorno bg-retorno-tint'],
            'vixcard' => ['VixCard', 'bg-bloqueio', 'border-bloqueio bg-bloqueio-tint'], 'outros' => ['Outros', 'bg-ink-faint', 'border-ink-faint bg-head'],
        ];
    @endphp
    <div x-data="minhaAgenda(@js(collect($calendarios)->map(fn ($c) => $c[2])))" x-init="iniciar()" class="mx-auto grid w-full max-w-7xl grid-cols-1 gap-4 2xl:grid-cols-[minmax(0,1fr)_300px]">
        <div class="min-w-0 space-y-3">
            <div x-show="aviso" x-cloak class="flex items-center gap-3 rounded-panel border border-warn-line bg-warn-tint px-3 py-1.5">
                <p class="min-w-0 flex-1 text-[13px] text-warn" x-text="aviso"></p>
                <a href="{{ route('secretario.configuracoes') }}#google" class="shrink-0 rounded-control bg-brand px-2.5 py-1 text-[12px] font-semibold text-on-brand hover:bg-brand-bright">Conectar agora</a>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div class="flex items-center gap-1">
                    <button type="button" @click="andar(-1)" class="h-[28px] px-2 rounded-control border border-btn-line text-[13px] text-ink-dim hover:border-brand hover:text-brand" aria-label="Anterior">‹</button>
                    <button type="button" @click="hoje()" class="h-[28px] px-2.5 rounded-control border border-btn-line text-[12px] font-semibold text-ink-dim hover:border-brand hover:text-brand">Hoje</button>
                    <button type="button" @click="andar(1)" class="h-[28px] px-2 rounded-control border border-btn-line text-[13px] text-ink-dim hover:border-brand hover:text-brand" aria-label="Próximo">›</button>
                </div>
                <h2 class="min-w-0 flex-1 truncate whitespace-nowrap font-display text-[14px] font-semibold text-ink" x-text="titulo()"></h2>
                <div class="flex rounded-control border border-btn-line p-0.5">
                    <template x-for="v in [['dia','Dia'],['semana','Semana'],['mes','Mês'],['lista','Agenda']]" :key="v[0]">
                        <button type="button" @click="trocar(v[0])" class="h-[24px] rounded-control px-2.5 text-[12px] font-semibold"
                                :class="visao === v[0] ? 'bg-brand text-on-brand' : 'text-ink-dim hover:text-brand'" x-text="v[1]"></button>
                    </template>
                </div>
                <button type="button" @click="novo.aberto = true; novo.data = iso(cursor); $nextTick(() => $refs.novoTitulo.focus())"
                        class="inline-flex h-[28px] items-center gap-1 rounded-control bg-brand px-2.5 text-[12px] font-semibold text-on-brand hover:bg-brand-bright">
                    <span class="h-3.5 w-3.5"><x-nav-icon name="plus" peso="2" /></span> Novo evento
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-panel border border-line bg-surface px-2.5 py-1.5">
                @foreach ($calendarios as $k => [$rotulo, $ponto])
                    <label class="flex cursor-pointer items-center gap-1 text-[11.5px] text-ink-dim">
                        <input type="checkbox" x-model="ativos.{{ $k }}" class="h-3 w-3 rounded border-btn-line text-brand focus:ring-0 focus:ring-offset-0">
                        <i class="h-2 w-2 rounded-sm {{ $ponto }}"></i>{{ \Illuminate\Support\Str::before($rotulo, ' (') }}
                    </label>
                @endforeach
                <span x-show="carregando" class="ml-auto text-[12px] text-ink-faint">carregando…</span>
            </div>

            {{-- Novo evento --}}
            <form x-show="novo.aberto" x-cloak @submit.prevent="criar()" class="grid grid-cols-1 gap-2 rounded-panel border border-brand bg-surface p-3 sm:grid-cols-[minmax(0,1fr)_150px_110px_160px_auto]">
                <input type="text" x-ref="novoTitulo" x-model="novo.titulo" maxlength="190" required placeholder="O quê (ex.: reunião com a Condonal)" class="{{ $campo }}">
                <input type="date" x-model="novo.data" required class="{{ $campo }}">
                <input type="time" x-model="novo.hora" class="{{ $campo }}" title="Sem hora vira tarefa do dia">
                <select x-model="novo.empresa" class="{{ $campo }}">
                    <option value="">Sem empresa</option>
                    @foreach (\App\Services\Secretario\CentralDeGestao::EMPRESAS as $k => $nome)<option value="{{ $k }}">{{ $nome }}</option>@endforeach
                </select>
                <div class="flex gap-1.5">
                    <button class="h-[34px] rounded-control bg-brand px-4 text-[13px] font-semibold text-on-brand hover:bg-brand-bright">Criar</button>
                    <button type="button" @click="novo.aberto = false" class="h-[34px] rounded-control border border-btn-line px-3 text-[13px] text-ink-dim">Fechar</button>
                </div>
                <p x-show="novo.recado" x-text="novo.recado" class="text-[12.5px] sm:col-span-5" :class="novo.erro ? 'text-crit' : 'text-good'"></p>
            </form>

            {{-- Dia e Semana: grade hora × dia --}}
            <section x-show="visao === 'dia' || visao === 'semana'" class="overflow-x-auto rounded-panel border border-line bg-surface">
                <div :style="`min-width: ${visao === 'semana' ? 680 : 300}px`">
                    <div class="grid border-b border-line" :style="colunas()">
                        <div></div>
                        <template x-for="d in dias()" :key="d.chave">
                            <div class="border-l border-line px-2 py-2 text-center" :class="d.hoje ? 'bg-brand/10' : ''">
                                <p class="font-mono text-[11px] uppercase tracking-caps" :class="d.hoje ? 'text-brand-text' : 'text-ink-faint'" x-text="d.semana"></p>
                                <p class="text-[14px] font-semibold" :class="d.hoje ? 'text-brand-text' : 'text-ink'" x-text="d.numero + '/' + d.mes"></p>
                            </div>
                        </template>
                    </div>
                    <div x-show="dias().some(d => doDia(d, true).length)" class="grid border-b border-line" :style="colunas()">
                        <div class="px-1 py-1 text-right font-mono text-[10px] text-ink-faint">dia</div>
                        <template x-for="d in dias()" :key="'t' + d.chave">
                            <div class="space-y-0.5 border-l border-line p-1" :class="d.hoje ? 'bg-brand/10' : ''">
                                <template x-for="e in doDia(d, true)" :key="e.inicio + e.titulo">
                                    <a :href="e.link || null" target="_blank" rel="noopener noreferrer" :class="cor(e)" class="block truncate rounded-sm border-l-[3px] px-1.5 py-0.5 text-[11.5px] text-ink" x-text="e.titulo" :title="e.origem_rotulo"></a>
                                </template>
                            </div>
                        </template>
                    </div>
                    <div class="grid" :style="colunas()">
                        <div class="relative" :style="`height: ${altura()}px`">
                            <template x-for="h in horas()" :key="h">
                                <span class="absolute right-1.5 font-mono text-[10.5px] text-ink-faint" :class="'translate-y-0.5'" :style="`top: ${(h * 60 - faixa().ini) * 0.6}px`" x-text="String(h).padStart(2, '0') + ':00'"></span>
                            </template>
                        </div>
                        <template x-for="d in dias()" :key="'g' + d.chave">
                            <div class="relative border-l border-line" :class="d.hoje ? 'bg-brand/10' : ''" :style="`height: ${altura()}px`">
                                <template x-for="h in horas()" :key="h">
                                    <div class="absolute inset-x-0 border-t border-line" :style="`top: ${(h * 60 - faixa().ini) * 0.6}px`"></div>
                                </template>
                                <template x-if="d.hoje && agoraNaFaixa()">
                                    <div class="absolute inset-x-0 z-10 border-t-2 border-crit" :style="`top: ${(minutosAgora() - faixa().ini) * 0.6}px`"><i class="absolute -left-1 -top-[5px] h-2 w-2 rounded-full bg-crit"></i></div>
                                </template>
                                <template x-for="b in blocos(d)" :key="b.e.inicio + b.e.titulo">
                                    <a :href="b.e.link || null" target="_blank" rel="noopener noreferrer" :class="cor(b.e)"
                                       class="absolute overflow-hidden rounded-[6px] border-l-[3px] px-1.5 py-1 text-[11.5px] leading-tight text-ink"
                                       :style="`top:${b.top}px;height:${b.altura}px;left:calc(${b.esq}% + 3px);width:calc(${b.larg}% - 6px)`" :title="b.e.titulo + ' · ' + b.e.origem_rotulo">
                                        {{-- Curto (até ~1 h): uma linha só, hora e título; longo: hora, título e origem. --}}
                                        <template x-if="b.altura < 44">
                                            <span class="block truncate"><span class="font-semibold" x-text="b.e.titulo"></span> <b class="font-mono text-[10px] font-normal text-ink-mute" x-text="hora(b.e.inicio)"></b></span>
                                        </template>
                                        <template x-if="b.altura >= 44">
                                            <span class="block">
                                                <span class="block font-mono text-[10.5px] font-semibold text-ink-dim" x-text="hora(b.e.inicio) + (b.e.fim ? ' – ' + hora(b.e.fim) : '')"></span>
                                                <span class="block font-semibold" x-text="b.e.titulo"></span>
                                                <span class="block truncate text-[10.5px] text-ink-mute" x-text="b.e.origem_rotulo"></span>
                                            </span>
                                        </template>
                                    </a>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </section>

            {{-- Mês --}}
            <section x-show="visao === 'mes'" x-cloak class="overflow-hidden rounded-panel border border-line bg-surface">
                <div class="grid grid-cols-7 border-b border-line">
                    <template x-for="s in ['seg','ter','qua','qui','sex','sáb','dom']" :key="s">
                        <p class="py-2 text-center font-mono text-[11px] uppercase tracking-caps text-ink-faint" x-text="s"></p>
                    </template>
                </div>
                <div class="grid grid-cols-7">
                    <template x-for="d in diasDoMes()" :key="d.chave">
                        <button type="button" @click="cursor = d.data; trocar('dia')" class="min-h-[84px] border-b border-l border-line p-1.5 text-left align-top hover:bg-head" :class="(d.foraDoMes ? 'opacity-40 ' : '') + (d.hoje ? 'bg-brand/10' : '')">
                            <p class="text-[12.5px] font-semibold" :class="d.hoje ? 'text-brand-text' : 'text-ink'" x-text="d.numero"></p>
                            <template x-for="e in doDia(d).slice(0, 3)" :key="e.inicio + e.titulo">
                                <p :class="cor(e)" class="mt-0.5 truncate rounded-sm border-l-[3px] px-1 text-[11px] text-ink" x-text="(e.dia_inteiro ? '' : hora(e.inicio) + ' ') + e.titulo"></p>
                            </template>
                            <p x-show="doDia(d).length > 3" class="mt-0.5 text-[11px] font-semibold text-ink-mute" x-text="'+' + (doDia(d).length - 3)"></p>
                        </button>
                    </template>
                </div>
            </section>

            {{-- Agenda (lista) --}}
            <section x-show="visao === 'lista'" x-cloak class="rounded-panel border border-line bg-surface">
                <template x-for="d in dias()" :key="'l' + d.chave">
                    <div x-show="doDia(d).length" class="border-b border-line last:border-0">
                        <p class="bg-head px-4 py-1.5 text-[12.5px] font-semibold" :class="d.hoje ? 'text-brand-text' : 'text-ink'" x-text="d.semana + ', ' + d.numero + '/' + d.mes"></p>
                        <template x-for="e in doDia(d)" :key="'le' + e.inicio + e.titulo">
                            <a :href="e.link || null" target="_blank" rel="noopener noreferrer" class="flex items-center gap-3 px-4 py-2.5 hover:bg-head">
                                <span class="w-[92px] shrink-0 font-mono text-[12px] text-ink-dim" x-text="e.dia_inteiro ? 'dia inteiro' : hora(e.inicio) + (e.fim ? '–' + hora(e.fim) : '')"></span>
                                <i class="h-2.5 w-2.5 shrink-0 rounded-sm" :class="cor(e).split(' ')[0].replace('border-', 'bg-')"></i>
                                <span class="min-w-0 flex-1 truncate text-[13.5px] text-ink" x-text="e.titulo"></span>
                                <span x-show="e.conflito" class="rounded-badge border border-crit px-1 text-[10.5px] font-semibold text-crit">conflito</span>
                                <span class="hidden shrink-0 text-[12px] text-ink-mute sm:block" x-text="e.origem_rotulo"></span>
                            </a>
                        </template>
                    </div>
                </template>
                <p x-show="!dias().some(d => doDia(d).length)" class="px-4 py-4 text-[13.5px] text-ink-mute">Nada neste período nas fontes ligadas.</p>
            </section>
            <p x-show="!carregando && !visiveis().length && visao !== 'lista'" class="text-[13px] text-ink-mute"
               x-text="googleOk ? 'Nada na agenda neste período.' : 'Nada nas fontes que consegui ler neste período.'"></p>
        </div>

        {{-- Lateral --}}
        <aside class="grid min-w-0 grid-cols-1 gap-4 md:grid-cols-3 2xl:block 2xl:space-y-4">
            <section class="rounded-panel border border-line bg-surface p-3">
                <div class="mb-2 flex items-center">
                    <button type="button" @click="mini(-1)" class="px-2 text-ink-mute hover:text-brand">‹</button>
                    <p class="flex-1 text-center text-[14px] font-semibold text-ink" x-text="meses[miniCursor.getMonth()].replace(/^./, c => c.toUpperCase()) + ' de ' + miniCursor.getFullYear()"></p>
                    <button type="button" @click="mini(1)" class="px-2 text-ink-mute hover:text-brand">›</button>
                </div>
                <div class="grid grid-cols-7 text-center text-[11px] font-semibold text-ink-faint">
                    <template x-for="s in ['S','T','Q','Q','S','S','D']"><span x-text="s"></span></template>
                </div>
                <div class="mt-1 grid grid-cols-7 gap-y-0.5 text-center">
                    <template x-for="d in diasDoMini()" :key="'m' + d.chave">
                        <button type="button" @click="cursor = d.data; carregar()" class="mx-auto flex h-7 w-7 items-center justify-center rounded-full text-[12px]"
                                :class="d.hoje ? 'bg-brand font-semibold text-on-brand' : (d.naSemana ? 'bg-brand/10 text-ink' : (d.foraDoMes ? 'text-ink-faint' : 'text-ink hover:bg-head'))" x-text="d.numero"></button>
                    </template>
                </div>
            </section>

            <section class="rounded-panel border border-line bg-surface p-4">
                <h2 class="mb-2 font-display text-[15px] font-semibold text-ink">Meus calendários</h2>
                <ul class="space-y-2">
                    @foreach ($calendarios as $k => [$rotulo, $ponto])
                        <li>
                            <label class="flex cursor-pointer items-center gap-2 text-[13.5px] text-ink">
                                <input type="checkbox" x-model="ativos.{{ $k }}" class="rounded border-btn-line text-brand focus:ring-brand">
                                <i class="h-2.5 w-2.5 rounded-full {{ $ponto }}"></i>
                                <span class="flex-1">{{ $rotulo }}</span>
                                <span class="text-[11.5px] text-ink-faint" x-text="eventos.filter(e => e.calendario === '{{ $k }}').length || ''"></span>
                            </label>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="rounded-panel border border-line bg-surface p-4">
                <h2 class="mb-2 font-display text-[15px] font-semibold text-ink">Próximos eventos</h2>
                <ul class="space-y-2.5">
                    <template x-for="e in proximos" :key="'p' + e.inicio + e.titulo">
                        <li class="flex gap-2.5">
                            <i class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full" :class="cor(e).split(' ')[0].replace('border-', 'bg-')"></i>
                            <div class="min-w-0">
                                <p class="truncate text-[13.5px] font-semibold text-ink" x-text="e.titulo"></p>
                                <p class="text-[12px] text-ink-mute" x-text="quandoCurto(e)"></p>
                            </div>
                        </li>
                    </template>
                    <li x-show="!proximos.length" class="text-[13px] text-ink-mute">Nada nos próximos 14 dias.</li>
                </ul>
            </section>
        </aside>
    </div>

    <script>
        function minhaAgenda(cores) {
            const iso = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
            const semana = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
            const meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
            const segunda = d => { const x = new Date(d); x.setDate(x.getDate() - ((x.getDay() + 6) % 7)); x.setHours(0, 0, 0, 0); return x; };
            const mais = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
            const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
            return {
                iso, meses, visao: 'semana', cursor: new Date(), miniCursor: new Date(), eventos: [], proximos: [], carregando: false, aviso: '', googleOk: false, agora: new Date(),
                ativos: Object.fromEntries(Object.keys(cores).map(k => [k, true])),
                novo: { aberto: false, titulo: '', data: '', hora: '', empresa: '', recado: '', erro: false },
                iniciar() {
                    let salvo = null;
                    try { salvo = localStorage.getItem('secretario:agenda-visao'); const a = JSON.parse(localStorage.getItem('secretario:agenda-calendarios') || 'null'); if (a) Object.assign(this.ativos, a); } catch (e) {}
                    this.visao = salvo || (window.innerWidth < 768 ? 'dia' : 'semana');
                    this.$watch('ativos', v => { try { localStorage.setItem('secretario:agenda-calendarios', JSON.stringify(v)); } catch (e) {} });
                    this.carregar(); this.carregarProximos();
                    setInterval(() => this.agora = new Date(), 60000);
                },
                trocar(v) { this.visao = v; try { localStorage.setItem('secretario:agenda-visao', v); } catch (e) {} this.carregar(); },
                hoje() { this.cursor = new Date(); this.miniCursor = new Date(); this.carregar(); },
                andar(n) {
                    const c = new Date(this.cursor);
                    if (this.visao === 'mes') c.setMonth(c.getMonth() + n, 1); else c.setDate(c.getDate() + n * (this.visao === 'dia' ? 1 : 7));
                    this.cursor = c; this.miniCursor = new Date(c); this.carregar();
                },
                mini(n) { const c = new Date(this.miniCursor); c.setMonth(c.getMonth() + n, 1); this.miniCursor = c; },
                intervalo() {
                    if (this.visao === 'dia') return [new Date(this.cursor), new Date(this.cursor)];
                    if (this.visao === 'semana' || this.visao === 'lista') { const s = segunda(this.cursor); return [s, mais(s, 6)]; }
                    const ini = segunda(new Date(this.cursor.getFullYear(), this.cursor.getMonth(), 1));
                    return [ini, mais(ini, 41)];
                },
                async buscar(de, ate) {
                    const r = await fetch(@json(route('secretario.agenda.eventos')) + `?de=${iso(de)}&ate=${iso(ate)}`, { headers: { Accept: 'application/json' } });
                    return r.json();
                },
                async carregar() {
                    const [de, ate] = this.intervalo();
                    this.carregando = true;
                    try { const j = await this.buscar(de, ate); this.eventos = j.eventos || []; this.aviso = j.google?.aviso || ''; this.googleOk = j.google?.estado === 'ok'; }
                    catch (e) { this.aviso = 'Não consegui carregar a agenda agora.'; }
                    this.carregando = false;
                },
                async carregarProximos() {
                    try {
                        const j = await this.buscar(new Date(), mais(new Date(), 14));
                        const agora = iso(new Date()) + 'T' + String(new Date().getHours()).padStart(2, '0') + ':' + String(new Date().getMinutes()).padStart(2, '0');
                        this.proximos = (j.eventos || []).filter(e => (e.fim || e.inicio) >= agora).slice(0, 5);
                    } catch (e) {}
                },
                async criar() {
                    this.novo.recado = ''; this.novo.erro = false;
                    const r = await fetch(@json(route('secretario.agenda.novo')), { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                        body: JSON.stringify({ titulo: this.novo.titulo, data: this.novo.data, hora: this.novo.hora || null, empresa: this.novo.empresa || null }) });
                    const j = await r.json().catch(() => ({}));
                    if (!r.ok) { this.novo.erro = true; this.novo.recado = Object.values(j.errors || {}).flat()[0] || 'Não deu certo.'; return; }
                    this.novo.recado = j.mensagem; this.novo.titulo = ''; this.novo.hora = '';
                    this.carregar(); this.carregarProximos();
                },
                titulo() {
                    const [de, ate] = this.intervalo(), c = s => s.slice(0, 3) + '.';
                    if (this.visao === 'mes') return meses[this.cursor.getMonth()].replace(/^./, x => x.toUpperCase()) + ' de ' + this.cursor.getFullYear();
                    if (this.visao === 'dia') return semana[de.getDay()] + ', ' + de.getDate() + ' de ' + meses[de.getMonth()] + ' de ' + de.getFullYear();
                    return de.getMonth() === ate.getMonth() ? de.getDate() + ' – ' + ate.getDate() + ' de ' + c(meses[de.getMonth()]) + ' de ' + de.getFullYear()
                        : de.getDate() + ' de ' + c(meses[de.getMonth()]) + ' – ' + ate.getDate() + ' de ' + c(meses[ate.getMonth()]) + ' de ' + ate.getFullYear();
                },
                info(d) { return { data: d, chave: iso(d), semana: semana[d.getDay()], numero: d.getDate(), mes: String(d.getMonth() + 1).padStart(2, '0'), hoje: iso(d) === iso(this.agora) }; },
                dias() { const [de] = this.intervalo(); return Array.from({ length: this.visao === 'dia' ? 1 : 7 }, (_, i) => this.info(mais(de, i))); },
                diasDoMes() { const [de] = this.intervalo(); return Array.from({ length: 42 }, (_, i) => ({ ...this.info(mais(de, i)), foraDoMes: mais(de, i).getMonth() !== this.cursor.getMonth() })); },
                diasDoMini() {
                    const ini = segunda(new Date(this.miniCursor.getFullYear(), this.miniCursor.getMonth(), 1)), s = iso(segunda(this.cursor)), f = iso(mais(segunda(this.cursor), 6));
                    return Array.from({ length: 42 }, (_, i) => { const d = mais(ini, i); return { ...this.info(d), foraDoMes: d.getMonth() !== this.miniCursor.getMonth(), naSemana: this.visao === 'semana' && iso(d) >= s && iso(d) <= f }; });
                },
                colunas() { return `grid-template-columns: 56px repeat(${this.dias().length}, minmax(0, 1fr))`; },
                visiveis() { return this.eventos.filter(e => this.ativos[e.calendario] !== false); },
                doDia(d, soDiaInteiro = null) {
                    return this.visiveis().filter(e => {
                        const ini = e.inicio.slice(0, 10), fim = (e.fim || e.inicio).slice(0, 10);
                        const dentro = d.chave >= ini && d.chave <= (e.dia_inteiro && e.fim && e.fim.slice(11) === '00:00' && fim > ini ? iso(mais(new Date(fim + 'T00:00'), -1)) : fim);
                        return dentro && (soDiaInteiro === null || e.dia_inteiro === soDiaInteiro);
                    }).sort((a, b) => a.inicio.localeCompare(b.inicio));
                },
                min(s) { return Number(s.slice(11, 13)) * 60 + Number(s.slice(14, 16)); },
                hora(s) { return s.slice(11, 16); },
                quandoCurto(e) {
                    const d = new Date(e.inicio.slice(0, 10) + 'T00:00'), h = iso(this.agora), amanha = iso(mais(this.agora, 1));
                    const dia = iso(d) === h ? 'Hoje' : (iso(d) === amanha ? 'Amanhã' : semana[d.getDay()] + ', ' + d.getDate() + '/' + String(d.getMonth() + 1).padStart(2, '0'));
                    return dia + (e.dia_inteiro ? '' : ', ' + this.hora(e.inicio));
                },
                minutosAgora() { return this.agora.getHours() * 60 + this.agora.getMinutes(); },
                /* Faixa padrão 08:00–18:00 (Felipe, 17/10/2026); evento da semana antes ou depois estende até a hora cheia que o cobre. */
                faixa() {
                    const doPeriodo = this.dias().flatMap(d => this.doDia(d, false).map(e => ({ e, d })));
                    let ini = 8 * 60, fim = 18 * 60;
                    for (const { e, d } of doPeriodo) {
                        const comeca = e.inicio.slice(0, 10) < d.chave ? 0 : this.min(e.inicio);
                        const termina = !e.fim ? comeca + 60 : (e.fim.slice(0, 10) > d.chave ? 24 * 60 : this.min(e.fim));
                        ini = Math.min(ini, Math.floor(comeca / 60) * 60);
                        fim = Math.max(fim, Math.min(24 * 60, Math.ceil(termina / 60) * 60));
                    }
                    return { ini, fim };
                },
                horas() { const f = this.faixa(); return Array.from({ length: (f.fim - f.ini) / 60 + 1 }, (_, i) => f.ini / 60 + i); },
                altura() { const f = this.faixa(); return (f.fim - f.ini) * 0.6; },
                agoraNaFaixa() { const f = this.faixa(), m = this.minutosAgora(); return m >= f.ini && m <= f.fim; },
                /* Eventos que se sobrepõem dividem a largura, em raias. */
                blocos(d) {
                    const f = this.faixa();
                    const lista = this.doDia(d, false).map(e => {
                        const ini = e.inicio.slice(0, 10) < d.chave ? 0 : this.min(e.inicio);
                        const fim = !e.fim ? ini + 60 : (e.fim.slice(0, 10) > d.chave ? 24 * 60 : this.min(e.fim));
                        return { e, ini: Math.max(ini, f.ini), fim: Math.min(Math.max(fim, ini + 20), f.fim) };
                    });
                    const raias = [];
                    lista.forEach(b => { let r = raias.findIndex(fim => fim <= b.ini); if (r < 0) { r = raias.length; raias.push(0); } raias[r] = b.fim; b.raia = r; });
                    const n = Math.max(1, raias.length);
                    return lista.map(b => ({ e: b.e, top: (b.ini - f.ini) * 0.6, altura: Math.max((b.fim - b.ini) * 0.6, 20), esq: b.raia * 100 / n, larg: 100 / n }));
                },
                cor(e) { return (cores[e.calendario] || cores.outros) + (e.conflito ? ' ring-1 ring-crit' : ''); },
            };
        }
    </script>
</x-app-layout>
