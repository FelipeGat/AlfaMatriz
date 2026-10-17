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

    {{-- Etapa 8 (docs/specs/secretario-central-do-dia, FR-006): calendário no
         jeito do Gestor — Dia, Semana e Mês, grade de horas de 48 px que se
         ajusta aos eventos, faixa de dia inteiro e linha do agora. As três
         fontes da Etapa 2 continuam, cada evento com a cor da origem; sem o
         Google em dia, o aviso diz a limitação (dia vazio não é dia livre). --}}
    <div x-data="minhaAgenda()" x-init="iniciar()" class="mx-auto w-full max-w-6xl space-y-3">
        <p x-show="aviso" x-cloak x-text="aviso" class="rounded-panel border border-warn-line bg-warn-tint px-4 py-2.5 text-[13px] text-warn"></p>

        <div class="flex flex-wrap items-center gap-2">
            <div class="flex items-center gap-1">
                <button type="button" @click="andar(-1)" class="h-[34px] px-3 rounded-control border border-btn-line text-[15px] text-ink-dim hover:border-brand hover:text-brand" aria-label="Anterior">‹</button>
                <button type="button" @click="hoje()" class="h-[34px] px-3 rounded-control border border-btn-line text-[12.5px] font-semibold text-ink-dim hover:border-brand hover:text-brand">Hoje</button>
                <button type="button" @click="andar(1)" class="h-[34px] px-3 rounded-control border border-btn-line text-[15px] text-ink-dim hover:border-brand hover:text-brand" aria-label="Próximo">›</button>
            </div>
            <h2 class="min-w-0 flex-1 font-display text-[17px] font-semibold text-ink" x-text="titulo()"></h2>
            <div class="flex rounded-control border border-btn-line p-0.5">
                <template x-for="v in [['dia','Dia'],['semana','Semana'],['mes','Mês']]" :key="v[0]">
                    <button type="button" @click="trocar(v[0])" class="h-[28px] rounded-control px-3 text-[12.5px] font-semibold"
                            :class="visao === v[0] ? 'bg-brand text-on-brand' : 'text-ink-dim hover:text-brand'" x-text="v[1]"></button>
                </template>
            </div>
        </div>
        <div class="flex flex-wrap gap-3 text-[11.5px] text-ink-mute">
            <span class="flex items-center gap-1"><i class="inline-block h-2.5 w-2.5 rounded-sm bg-brand"></i>Google</span>
            <span class="flex items-center gap-1"><i class="inline-block h-2.5 w-2.5 rounded-sm bg-warn"></i>Secretário</span>
            <span class="flex items-center gap-1"><i class="inline-block h-2.5 w-2.5 rounded-sm bg-good"></i>Agenda do Matriz</span>
            <span class="flex items-center gap-1"><i class="inline-block h-2.5 w-2.5 rounded-sm border-2 border-crit"></i>conflito</span>
            <span x-show="carregando" class="text-ink-faint">carregando…</span>
        </div>

        {{-- Dia e Semana: grade hora × dia --}}
        <section x-show="visao !== 'mes'" class="overflow-x-auto rounded-panel border border-line bg-subtle">
            <div :style="`min-width: ${visao === 'semana' ? 760 : 320}px`">
                <div class="grid border-b border-line bg-head" :style="colunas()">
                    <div></div>
                    <template x-for="d in dias()" :key="d.chave">
                        <div class="border-l border-line px-2 py-1.5 text-center">
                            <p class="font-mono text-[10.5px] uppercase tracking-caps" :class="d.hoje ? 'text-brand-text' : 'text-ink-faint'" x-text="d.semana"></p>
                            <p class="text-[15px] font-semibold" :class="d.hoje ? 'text-brand-text' : 'text-ink'" x-text="d.numero"></p>
                        </div>
                    </template>
                </div>
                <div x-show="dias().some(d => doDia(d, true).length)" class="grid border-b border-line" :style="colunas()">
                    <div class="px-1 py-1 text-right font-mono text-[10px] text-ink-faint">dia</div>
                    <template x-for="d in dias()" :key="'t' + d.chave">
                        <div class="space-y-0.5 border-l border-line p-1">
                            <template x-for="e in doDia(d, true)" :key="e.inicio + e.titulo">
                                <a :href="e.link || null" target="_blank" rel="noopener noreferrer" :class="cor(e)" class="block truncate rounded-sm border-l-[3px] px-1.5 py-0.5 text-[11.5px] text-ink" x-text="e.titulo" :title="e.origem_rotulo"></a>
                            </template>
                        </div>
                    </template>
                </div>
                <div class="grid" :style="colunas()">
                    <div class="relative" :style="`height: ${altura()}px`">
                        <template x-for="h in horas()" :key="h">
                            <span class="absolute right-1 font-mono text-[10px] text-ink-faint" :class="h * 60 === faixa().ini ? 'translate-y-0.5' : '-translate-y-1/2'" :style="`top: ${(h * 60 - faixa().ini) * 0.8}px`" x-text="String(h).padStart(2, '0') + ':00'"></span>
                        </template>
                    </div>
                    <template x-for="d in dias()" :key="'g' + d.chave">
                        <div class="relative border-l border-line" :style="`height: ${altura()}px`">
                            <template x-for="h in horas()" :key="h">
                                <div class="absolute inset-x-0 border-t border-line" :style="`top: ${(h * 60 - faixa().ini) * 0.8}px`"></div>
                            </template>
                            <template x-if="d.hoje && agoraNaFaixa()">
                                <div class="absolute inset-x-0 z-10 border-t-2 border-crit" :style="`top: ${(minutosAgora() - faixa().ini) * 0.8}px`"></div>
                            </template>
                            <template x-for="b in blocos(d)" :key="b.e.inicio + b.e.titulo">
                                <a :href="b.e.link || null" target="_blank" rel="noopener noreferrer" :class="cor(b.e)"
                                   class="absolute overflow-hidden rounded-sm border-l-[3px] px-1.5 py-0.5 text-[11.5px] leading-tight text-ink shadow-sm"
                                   :style="`top:${b.top}px;height:${b.altura}px;left:calc(${b.esq}% + 2px);width:calc(${b.larg}% - 4px)`" :title="b.e.titulo + ' · ' + b.e.origem_rotulo">
                                    <span class="font-mono text-[10px] text-ink-dim" x-text="hora(b.e.inicio) + (b.e.fim ? '–' + hora(b.e.fim) : '')"></span>
                                    <span class="block font-semibold" x-text="b.e.titulo"></span>
                                </a>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
            <p x-show="!carregando && !eventos.length" class="border-t border-line px-4 py-3 text-[13px] text-ink-mute"
               x-text="googleOk ? 'Nada na agenda neste período.' : 'Nada nas fontes que consegui ler neste período.'"></p>
        </section>

        {{-- Mês --}}
        <section x-show="visao === 'mes'" x-cloak class="overflow-hidden rounded-panel border border-line bg-subtle">
            <div class="grid grid-cols-7 border-b border-line bg-head">
                <template x-for="s in ['seg','ter','qua','qui','sex','sáb','dom']" :key="s">
                    <p class="py-1.5 text-center font-mono text-[10.5px] uppercase tracking-caps text-ink-faint" x-text="s"></p>
                </template>
            </div>
            <div class="grid grid-cols-7">
                <template x-for="d in diasDoMes()" :key="d.chave">
                    <button type="button" @click="cursor = d.data; trocar('dia')" class="min-h-[92px] border-b border-l border-line p-1 text-left align-top hover:bg-head" :class="d.foraDoMes ? 'opacity-40' : ''">
                        <p class="text-[12px] font-semibold" :class="d.hoje ? 'text-brand-text' : 'text-ink'" x-text="d.numero"></p>
                        <template x-for="e in doDia(d).slice(0, 3)" :key="e.inicio + e.titulo">
                            <p :class="cor(e)" class="mt-0.5 truncate rounded-sm border-l-[3px] px-1 text-[11px] text-ink" x-text="(e.dia_inteiro ? '' : hora(e.inicio) + ' ') + e.titulo"></p>
                        </template>
                        <p x-show="doDia(d).length > 3" class="mt-0.5 text-[11px] font-semibold text-ink-mute" x-text="'+' + (doDia(d).length - 3)"></p>
                    </button>
                </template>
            </div>
        </section>
    </div>

    <script>
        function minhaAgenda() {
            const iso = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
            const semana = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
            const meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
            const segunda = d => { const x = new Date(d); x.setDate(x.getDate() - ((x.getDay() + 6) % 7)); x.setHours(0, 0, 0, 0); return x; };
            const mais = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
            return {
                visao: 'semana', cursor: new Date(), eventos: [], carregando: false, aviso: '', googleOk: false, agora: new Date(),
                iniciar() {
                    let salvo = null;
                    try { salvo = localStorage.getItem('secretario:agenda-visao'); } catch (e) {}
                    this.visao = salvo || (window.innerWidth < 768 ? 'dia' : 'semana');
                    this.carregar();
                    setInterval(() => this.agora = new Date(), 60000);
                },
                trocar(v) { this.visao = v; try { localStorage.setItem('secretario:agenda-visao', v); } catch (e) {} this.carregar(); },
                hoje() { this.cursor = new Date(); this.carregar(); },
                andar(n) {
                    const c = new Date(this.cursor);
                    if (this.visao === 'mes') c.setMonth(c.getMonth() + n, 1); else c.setDate(c.getDate() + n * (this.visao === 'semana' ? 7 : 1));
                    this.cursor = c; this.carregar();
                },
                intervalo() {
                    if (this.visao === 'dia') return [new Date(this.cursor), new Date(this.cursor)];
                    if (this.visao === 'semana') { const s = segunda(this.cursor); return [s, mais(s, 6)]; }
                    const ini = segunda(new Date(this.cursor.getFullYear(), this.cursor.getMonth(), 1));
                    return [ini, mais(ini, 41)];
                },
                async carregar() {
                    const [de, ate] = this.intervalo();
                    this.carregando = true;
                    try {
                        const r = await fetch(@json(route('secretario.agenda.eventos')) + `?de=${iso(de)}&ate=${iso(ate)}`, { headers: { Accept: 'application/json' } });
                        const j = await r.json();
                        this.eventos = j.eventos || []; this.aviso = j.google?.aviso || ''; this.googleOk = j.google?.estado === 'ok';
                    } catch (e) { this.aviso = 'Não consegui carregar a agenda agora.'; }
                    this.carregando = false;
                },
                titulo() {
                    const [de, ate] = this.intervalo();
                    if (this.visao === 'mes') return meses[this.cursor.getMonth()] + ' de ' + this.cursor.getFullYear();
                    if (this.visao === 'dia') return semana[de.getDay()] + ', ' + de.getDate() + ' de ' + meses[de.getMonth()];
                    return de.getDate() + ' de ' + meses[de.getMonth()] + ' a ' + ate.getDate() + ' de ' + meses[ate.getMonth()];
                },
                info(d) { return { data: d, chave: iso(d), semana: semana[d.getDay()], numero: d.getDate(), hoje: iso(d) === iso(this.agora) }; },
                dias() { const [de] = this.intervalo(); return Array.from({ length: this.visao === 'semana' ? 7 : 1 }, (_, i) => this.info(mais(de, i))); },
                diasDoMes() { const [de] = this.intervalo(); return Array.from({ length: 42 }, (_, i) => ({ ...this.info(mais(de, i)), foraDoMes: mais(de, i).getMonth() !== this.cursor.getMonth() })); },
                colunas() { return `grid-template-columns: 52px repeat(${this.dias().length}, minmax(0, 1fr))`; },
                doDia(d, soDiaInteiro = null) {
                    return this.eventos.filter(e => {
                        const ini = e.inicio.slice(0, 10), fim = (e.fim || e.inicio).slice(0, 10);
                        const dentro = d.chave >= ini && d.chave <= (e.dia_inteiro && e.fim && e.fim.slice(11) === '00:00' && fim > ini ? iso(mais(new Date(fim + 'T00:00'), -1)) : fim);
                        return dentro && (soDiaInteiro === null || e.dia_inteiro === soDiaInteiro);
                    }).sort((a, b) => a.inicio.localeCompare(b.inicio));
                },
                min(s) { return Number(s.slice(11, 13)) * 60 + Number(s.slice(14, 16)); },
                hora(s) { return s.slice(11, 16); },
                minutosAgora() { return this.agora.getHours() * 60 + this.agora.getMinutes(); },
                /* Faixa como no Gestor: 07–20 sem eventos; com eventos, 1 h antes do primeiro e 1 h depois do último. */
                faixa() {
                    const doPeriodo = this.dias().flatMap(d => this.doDia(d, false));
                    if (!doPeriodo.length) return { ini: 7 * 60, fim: 20 * 60 };
                    const ini = Math.max(0, Math.floor(Math.min(...doPeriodo.map(e => this.min(e.inicio))) / 60) * 60 - 60);
                    const fim = Math.min(24 * 60, Math.ceil(Math.max(...doPeriodo.map(e => e.fim && e.fim.slice(0, 10) === e.inicio.slice(0, 10) ? this.min(e.fim) : this.min(e.inicio) + 60)) / 60) * 60 + 60);
                    return { ini: Math.min(ini, 7 * 60), fim: Math.max(fim, Math.min(ini, 7 * 60) + 8 * 60, 20 * 60) };
                },
                horas() { const f = this.faixa(); return Array.from({ length: (f.fim - f.ini) / 60 + 1 }, (_, i) => f.ini / 60 + i); },
                altura() { const f = this.faixa(); return (f.fim - f.ini) * 0.8; },
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
                    return lista.map(b => ({ e: b.e, top: (b.ini - f.ini) * 0.8, altura: Math.max((b.fim - b.ini) * 0.8, 18), esq: b.raia * 100 / n, larg: 100 / n }));
                },
                cor(e) {
                    const base = { google: 'border-brand bg-brand/10', secretario: 'border-warn bg-warn-tint', matriz: 'border-good bg-good-tint' }[e.origem] || 'border-line bg-head';
                    return base + (e.conflito ? ' ring-1 ring-crit' : '');
                },
            };
        }
    </script>
</x-app-layout>
