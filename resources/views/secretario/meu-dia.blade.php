<x-app-layout>
    <x-slot name="titulo">Meu Dia</x-slot>
    <x-slot name="contexto">
        <span>{{ ucfirst(\Illuminate\Support\Carbon::now()->locale('pt_BR')->translatedFormat('l, d \d\e F')) }}</span>
    </x-slot>
    <x-slot name="acoes">
        <a href="{{ route('secretario.configuracoes') }}"
           class="h-[34px] px-3 inline-flex items-center gap-1.5 rounded-control border border-btn-line text-ink-dim text-[12.5px] font-semibold hover:text-brand hover:border-brand transition whitespace-nowrap">
            Configurações
        </a>
    </x-slot>

    {{-- Secretário (docs/specs/secretario-executivo). Só o que pede ação tem
         destaque: atrasados em cima, depois hoje em ordem de hora, depois o
         que não tem data. Tudo com um toque e sem recarregar (FR-022, FR-023). --}}
    <div x-data="secretarioMeuDia()" x-init="carregar()" class="mx-auto w-full max-w-3xl space-y-4">
        <p class="font-display text-[18px] font-semibold text-ink">
            {{ now()->hour < 12 ? 'Bom dia' : (now()->hour < 18 ? 'Boa tarde' : 'Boa noite') }}, {{ \Illuminate\Support\Str::of(auth()->user()->name)->before(' ') }}.
        </p>

        @unless ($telegramPareado)
            <x-aviso tom="atencao" :segundos="0">O Telegram não está ligado à Central: os lembretes chegam só no sino. Ligue em Central de IA → Ajustes → Telegram.</x-aviso>
        @endunless

        {{-- Registrar do jeito que se fala. A proposta aparece aqui e só vira item com Confirmar. --}}
        <x-painel titulo="Registrar">
            <form @submit.prevent="interpretar()" class="flex flex-col gap-2 sm:flex-row">
                <input type="text" x-model="texto" maxlength="2000" autocomplete="off"
                       placeholder="ex.: amanhã às 9h ligar para o Alexandre"
                       class="min-w-0 flex-1 rounded-control border border-btn-line bg-surface px-3 py-2 text-[14px] text-ink placeholder:text-ink-faint focus:border-brand focus:outline-none">
                <button type="submit" :disabled="enviando || !texto.trim()"
                        class="h-[38px] rounded-control bg-brand px-4 text-[13px] font-semibold text-on-brand hover:bg-brand-bright disabled:opacity-60">
                    Registrar
                </button>
            </form>
            <div x-show="proposta || pergunta || recado" x-cloak class="mt-3 rounded-ctl border border-line bg-surface p-3 text-[13.5px] text-ink">
                <p class="whitespace-pre-line" x-text="proposta ? proposta.resumo : (pergunta || recado)"></p>
                <div x-show="proposta" class="mt-3 flex gap-2">
                    <button type="button" @click="confirmar()" :disabled="enviando"
                            class="h-[34px] rounded-control bg-brand px-3 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright disabled:opacity-60">✓ Confirmar</button>
                    <button type="button" @click="descartar()"
                            class="h-[34px] rounded-control border border-btn-line px-3 text-[12.5px] font-semibold text-ink-dim hover:text-brand hover:border-brand">Corrigir</button>
                </div>
            </div>
        </x-painel>

        <template x-if="dados.proximo">
            <div class="rounded-panel border border-line bg-subtle px-4 py-3">
                <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Próximo</p>
                <p class="mt-1 text-[15px] font-semibold text-ink" x-text="dados.proximo.titulo"></p>
                <p class="text-[12.5px] text-ink-mute" x-text="dados.proximo.quando_texto"></p>
            </div>
        </template>

        <template x-for="secao in secoes" :key="secao.chave">
            <x-painel x-show="(dados[secao.chave] || []).length" x-cloak solto>
                <div class="h-[38px] flex items-center gap-3 px-4 bg-head border-b border-line">
                    <h2 class="font-display text-[15px] font-semibold" :class="secao.chave === 'atrasados' ? 'text-crit' : 'text-ink'" x-text="secao.titulo"></h2>
                    <span class="ml-auto font-mono text-[11px] text-ink-faint" x-text="(dados[secao.chave] || []).length"></span>
                </div>
                <ul class="divide-y divide-line">
                    <template x-for="item in dados[secao.chave]" :key="item.id">
                        <li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center">
                            <div class="min-w-0 flex-1">
                                <p class="text-[14px] font-semibold text-ink break-words" x-text="item.titulo"></p>
                                <p class="text-[12px] text-ink-mute">
                                    <span x-text="rotulo(item)"></span>
                                    <span x-show="item.recorrencia_texto" x-text="' · ' + item.recorrencia_texto"></span>
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-1.5">
                                <button type="button" @click="agir(item, 'concluir')" class="h-[32px] rounded-control border border-good-line px-2.5 text-[12px] font-semibold text-good hover:bg-good-tint">✓ Concluir</button>
                                <template x-if="item.quando">
                                    <span class="contents">
                                        <button type="button" @click="agir(item, 'adiar_60')" class="h-[32px] rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim hover:text-brand hover:border-brand">+1 h</button>
                                        <button type="button" @click="agir(item, 'amanha')" class="h-[32px] rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim hover:text-brand hover:border-brand">Amanhã</button>
                                    </span>
                                </template>
                                <button type="button" @click="reagendar(item)" class="h-[32px] rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim hover:text-brand hover:border-brand">Reagendar</button>
                                <button type="button" @click="cancelar(item)" class="h-[32px] rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-mute hover:text-crit hover:border-crit">Cancelar</button>
                            </div>
                        </li>
                    </template>
                </ul>
            </x-painel>
        </template>

        <p x-show="carregado && vazio()" x-cloak class="rounded-panel border border-line bg-subtle px-4 py-6 text-center text-[13.5px] text-ink-mute">
            Nada registrado para hoje.
        </p>
        <p x-show="dados.concluidos_hoje" x-cloak class="text-center text-[12px] text-ink-faint" x-text="dados.concluidos_hoje + ' concluído(s) hoje'"></p>
        <p x-show="erro" x-cloak class="text-center text-[12.5px] text-crit" x-text="erro"></p>
    </div>

    <script>
        function secretarioMeuDia() {
            const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
            const chamar = async (url, metodo = 'GET', corpo = null) => {
                const r = await fetch(url, { method: metodo, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                    body: corpo ? JSON.stringify(corpo) : null });
                const json = await r.json().catch(() => ({}));
                if (!r.ok && r.status !== 409) throw new Error(json.message || 'Não deu certo. Tente de novo.');
                return json;
            };
            return {
                dados: {}, carregado: false, texto: '', proposta: null, pergunta: '', recado: '', enviando: false, erro: '',
                secoes: [
                    { chave: 'atrasados', titulo: 'Atrasados' }, { chave: 'hoje', titulo: 'Hoje' },
                    { chave: 'proximos', titulo: 'Próximos' }, { chave: 'sem_data', titulo: 'Sem data' },
                ],
                async carregar() {
                    try { this.dados = await chamar(@json(route('secretario.itens'))); this.erro = ''; } catch (e) { this.erro = e.message; }
                    this.carregado = true;
                },
                vazio() { return !['atrasados', 'hoje'].some(k => (this.dados[k] || []).length); },
                rotulo(item) {
                    const tipos = { tarefa: 'Tarefa', lembrete: 'Lembrete', compromisso: 'Compromisso', acompanhamento: 'Acompanhamento' };
                    return tipos[item.tipo] + (item.quando_texto ? ' · ' + item.quando_texto : ' · sem data');
                },
                async interpretar() {
                    this.enviando = true; this.erro = ''; this.recado = ''; this.pergunta = ''; this.proposta = null;
                    try {
                        const r = await chamar(@json(route('secretario.interpretar')), 'POST', { texto: this.texto });
                        if (r.nao_e_do_secretario) this.recado = 'Não entendi como registro. Ex.: "amanhã às 9h ligar para o Alexandre".';
                        else if (r.item) { this.recado = r.mensagem; this.texto = ''; await this.carregar(); }
                        else if (r.falta) { this.pergunta = r.pergunta; this.texto = ''; }
                        else if (r.proposta) this.proposta = r.proposta;
                        else this.recado = r.mensagem || '';
                    } catch (e) { this.erro = e.message; }
                    this.enviando = false;
                },
                async confirmar() {
                    this.enviando = true;
                    try {
                        const r = await chamar(@json(url('secretario/propostas')) + '/' + this.proposta.id + '/confirmar', 'POST');
                        this.recado = r.mensagem; this.proposta = null; this.texto = ''; await this.carregar();
                    } catch (e) { this.erro = e.message; }
                    this.enviando = false;
                },
                async descartar() {
                    if (this.proposta) await chamar(@json(url('secretario/propostas')) + '/' + this.proposta.id + '/descartar', 'POST').catch(() => {});
                    this.proposta = null; this.recado = 'Corrija o texto acima e registre de novo.';
                },
                async agir(item, acao) {
                    try { const r = await chamar(@json(url('secretario/itens')) + '/' + item.id + '/acao', 'POST', { acao }); this.recado = r.mensagem; this.pergunta = ''; this.proposta = null; }
                    catch (e) { this.erro = e.message; }
                    await this.carregar();
                },
                async cancelar(item) {
                    if (item.recorrencia_texto) {
                        const todas = confirm('Cancelar TODAS as próximas ocorrências? (Cancelar = só esta)');
                        return this.agir(item, todas ? 'cancelar_todas' : 'cancelar_esta');
                    }
                    return this.agir(item, 'cancelar');
                },
                async reagendar(item) {
                    const texto = prompt('Para quando? (ex.: quinta às 15h, amanhã às 9h)');
                    if (!texto) return;
                    try { const r = await chamar(@json(url('secretario/itens')) + '/' + item.id + '/reagendar', 'POST', { texto }); this.recado = r.mensagem; }
                    catch (e) { this.erro = e.message; }
                    await this.carregar();
                },
            };
        }
    </script>
</x-app-layout>
