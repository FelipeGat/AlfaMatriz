<x-app-layout>
    <x-slot name="titulo">Meu Dia</x-slot>
    <x-slot name="contexto">
        <span>{{ ucfirst(\Illuminate\Support\Carbon::now()->locale('pt_BR')->translatedFormat('l, d \d\e F')) }}</span>
    </x-slot>
    <x-slot name="acoes">
        <a href="{{ route('secretario.configuracoes') }}"
           class="h-[30px] px-3 inline-flex items-center gap-1.5 rounded-control border border-btn-line text-ink-dim text-[12.5px] font-semibold hover:text-brand hover:border-brand transition whitespace-nowrap">
            <span class="h-4 w-4"><x-nav-icon name="settings" /></span> Configurações
        </a>
    </x-slot>

    {{-- Meu Dia (desenho de 17/10/2026): à esquerda o dia — Lá fora, registrar,
         compromissos e tarefas; à direita o que pede olhar — anotações,
         acompanhamentos e mensagens. Tudo com dado real da conta: o que não
         existe (WhatsApp) não vira botão de enfeite. --}}
    @php
        $acao = 'flex min-w-0 flex-col gap-1 rounded-panel border border-line bg-surface px-3.5 py-3 text-left hover:border-brand transition';
        $chip = 'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[12px] font-semibold';
    @endphp
    <div x-data="secretarioMeuDia()" x-init="carregar()" class="mx-auto grid w-full max-w-7xl grid-cols-1 gap-4 2xl:grid-cols-[minmax(0,1fr)_320px]">
        <div class="min-w-0 space-y-4">
            {{-- Saudação e atalhos --}}
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
                <div class="min-w-0 flex-1">
                    <h1 class="font-display text-[22px] font-semibold leading-tight text-ink">
                        {{ now()->hour < 12 ? 'Bom dia' : (now()->hour < 18 ? 'Boa tarde' : 'Boa noite') }}, {{ \Illuminate\Support\Str::of(auth()->user()->name)->before(' ') }}.
                    </h1>
                    <p class="mt-0.5 text-[13px] text-ink-mute">Seu secretário está pronto para ajudar.</p>
                </div>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:w-[540px]">
                    <a href="{{ route('secretario.reunioes.index') }}#gravar" class="{{ $acao }}">
                        <span class="h-6 w-6 text-crit"><x-nav-icon name="microphone" peso="1.7" /></span>
                        <span class="text-[13px] font-semibold text-ink">Gravar áudio</span>
                        <span class="text-[11.5px] text-ink-mute">Transcrever e resumir</span>
                    </a>
                    <button type="button" @click="$refs.anotacao.focus()" class="{{ $acao }}">
                        <span class="h-6 w-6 text-brand-text"><x-nav-icon name="pencil" peso="1.7" /></span>
                        <span class="text-[13px] font-semibold text-ink">Nova anotação</span>
                        <span class="text-[11.5px] text-ink-mute">Adicionar rápido</span>
                    </button>
                    <button type="button" @click="$refs.registrar.focus()" class="{{ $acao }}">
                        <span class="h-6 w-6 text-good"><x-nav-icon name="calendar" peso="1.7" /></span>
                        <span class="text-[13px] font-semibold text-ink">Novo compromisso</span>
                        <span class="text-[11.5px] text-ink-mute">Na minha agenda</span>
                    </button>
                    <a href="{{ route('central-ia.index') }}" class="{{ $acao }}">
                        <span class="h-6 w-6 text-brand-text"><x-nav-icon name="chat" peso="1.7" /></span>
                        <span class="text-[13px] font-semibold text-ink">Falar com a IA</span>
                        <span class="text-[11.5px] text-ink-mute">Central · Telegram</span>
                    </a>
                </div>
            </div>

            @if (session('status'))
                <x-aviso>{{ session('status') }}</x-aviso>
            @endif
            @unless ($telegramPareado)
                {{-- Faixa fixa, e não o toast do x-aviso: é um estado da conta, não um aviso que passa. --}}
                <p class="rounded-panel border border-warn-line bg-warn-tint px-4 py-2.5 text-[13px] text-warn">O Telegram não está ligado à Central: os lembretes chegam só no sino. Ligue em Central de IA → Ajustes → Telegram.</p>
            @endunless

            @include('secretario._la-fora')

            {{-- Registrar do jeito que se fala. A proposta aparece aqui e só vira item com Confirmar. --}}
            <section class="rounded-panel border border-line bg-surface">
                <div class="flex items-center gap-2 border-b border-line px-4 py-3">
                    <span class="h-5 w-5 text-brand-text"><x-nav-icon name="calendar" peso="1.7" /></span>
                    <h2 class="font-display text-[15px] font-semibold text-ink">Registrar</h2>
                    <span class="ml-auto text-[12px] text-ink-mute">lembrete, tarefa ou compromisso, do jeito que você fala</span>
                </div>
                <div class="p-4">
                    <form @submit.prevent="interpretar()" class="flex flex-col gap-2 sm:flex-row">
                        <input type="text" x-ref="registrar" x-model="texto" maxlength="2000" autocomplete="off"
                               placeholder="ex.: amanhã às 9h ligar para o Alexandre"
                               class="min-w-0 flex-1 rounded-control border border-btn-line bg-canvas px-3 py-1.5 text-[13px] text-ink placeholder:text-ink-faint focus:border-brand focus:outline-none">
                        <button type="submit" :disabled="enviando || !texto.trim()"
                                class="h-[30px] rounded-control bg-brand px-4 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright disabled:opacity-60">Registrar</button>
                    </form>
                    <div x-show="proposta || pergunta || recado" x-cloak class="mt-3 rounded-ctl border border-line bg-subtle p-3 text-[13.5px] text-ink">
                        <p class="whitespace-pre-line" x-text="proposta ? proposta.resumo : (pergunta || recado)"></p>
                        <div x-show="proposta" class="mt-3 flex gap-2">
                            <button type="button" @click="confirmar()" :disabled="enviando" class="h-[30px] rounded-control bg-brand px-3 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright disabled:opacity-60">✓ Confirmar</button>
                            <button type="button" @click="descartar()" class="h-[30px] rounded-control border border-btn-line px-3 text-[12.5px] font-semibold text-ink-dim hover:text-brand hover:border-brand">Corrigir</button>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Compromissos de hoje: as três fontes da agenda, com quanto falta. --}}
            <section class="rounded-panel border border-line bg-surface">
                <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3">
                    <span class="h-5 w-5 text-brand-text"><x-nav-icon name="calendar" peso="1.7" /></span>
                    <h2 class="font-display text-[15px] font-semibold text-ink">Compromissos de hoje</h2>
                    <a href="{{ route('secretario.agenda') }}" class="ml-auto text-[12.5px] font-semibold text-brand-text hover:underline">Ver agenda completa →</a>
                    <div class="flex w-full flex-wrap gap-1.5 sm:w-auto">
                        <span class="{{ $chip }} bg-brand/10 text-brand-text"><i class="h-2 w-2 rounded-full bg-brand"></i>{{ $contagem['compromissos'] }} compromisso(s)</span>
                        <span class="{{ $chip }} bg-warn-tint text-warn"><i class="h-2 w-2 rounded-full bg-warn"></i>{{ $contagem['pendencias'] }} pendência(s)</span>
                        <span class="{{ $chip }} bg-good-tint text-good"><i class="h-2 w-2 rounded-full bg-good"></i>{{ $contagem['concluidos'] }} concluído(s)</span>
                    </div>
                </div>
                <ul class="divide-y divide-line">
                    @forelse ($compromissos as $c)
                        <li class="flex items-center gap-3 px-4 py-3 {{ $c['quanto'] === 'encerrado' ? 'opacity-60' : '' }}">
                            <i class="h-2.5 w-2.5 shrink-0 rounded-full {{ ['google' => 'bg-brand', 'secretario' => 'bg-warn', 'matriz' => 'bg-good'][$c['origem']] ?? 'bg-ink-faint' }}"></i>
                            <span class="w-[48px] shrink-0 font-mono text-[13px] text-ink-dim">{{ $c['dia_inteiro'] ? 'dia' : $c['inicio']->format('H:i') }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-[14px] font-semibold text-ink">
                                    @if ($c['link'])<a href="{{ $c['link'] }}" target="_blank" rel="noopener noreferrer" class="hover:text-brand">{{ $c['titulo'] }}</a>@else{{ $c['titulo'] }}@endif
                                    @if ($c['conflito'])<span class="ml-1 rounded-badge border border-crit px-1 text-[10.5px] font-semibold text-crit">conflito</span>@endif
                                </p>
                                <p class="truncate text-[12px] text-ink-mute">{{ $c['origem_rotulo'] }}{{ $c['fim'] ? ' · até '.$c['fim']->format('H:i') : '' }}</p>
                            </div>
                            <span class="shrink-0 rounded-control bg-head px-2.5 py-1 text-[12px] font-semibold {{ $c['quanto'] === 'agora' ? 'text-good' : 'text-ink-dim' }}">{{ ucfirst($c['quanto']) }}</span>
                        </li>
                    @empty
                        <li class="px-4 py-4 text-[13.5px] text-ink-mute">Nenhum compromisso hoje nas fontes que consegui ler.</li>
                    @endforelse
                    <li>
                        <button type="button" @click="$refs.registrar.focus(); texto = 'hoje às '" class="flex w-full items-center gap-2 px-4 py-3 text-left text-[13.5px] text-ink-mute hover:text-brand">
                            <span class="h-4 w-4"><x-nav-icon name="plus" /></span> Adicionar compromisso
                        </button>
                    </li>
                </ul>
            </section>

            {{-- Tarefas e lembretes, com as ações de sempre (sem recarregar). --}}
            <template x-for="secao in secoes" :key="secao.chave">
                <x-painel x-show="(dados[secao.chave] || []).length" x-cloak solto>
                    <div class="h-[30px] flex items-center gap-3 px-4 bg-head border-b border-line">
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

            {{-- Briefing de hoje (Etapa 3): recolhível; o estado fica no navegador. --}}
            <template x-if="dados.briefing">
                <section class="rounded-panel border border-line bg-subtle">
                    <button type="button" @click="briefingAberto = !briefingAberto; try { localStorage.setItem('secretario:briefing-aberto', briefingAberto ? 'sim' : 'nao') } catch (e) {}"
                            class="flex h-[30px] w-full items-center gap-2 border-b border-line bg-head px-4 text-left">
                        <span class="font-display text-[15px] font-semibold text-ink">Briefing de hoje</span>
                        <span class="font-mono text-[11px] text-ink-faint" x-text="'gerado às ' + dados.briefing.gerado_em"></span>
                        <span class="ml-auto text-[12px] text-ink-mute" x-text="briefingAberto ? 'recolher' : 'mostrar'"></span>
                    </button>
                    <pre x-show="briefingAberto" x-text="dados.briefing.texto" class="whitespace-pre-wrap break-words px-4 py-3 font-sans text-[13px] leading-relaxed text-ink"></pre>
                </section>
            </template>
            <p x-show="erro" x-cloak class="text-center text-[12.5px] text-crit" x-text="erro"></p>
        </div>

        {{-- Coluna da direita --}}
        <aside class="grid min-w-0 grid-cols-1 items-start gap-4 md:grid-cols-3 2xl:block 2xl:space-y-4">
            <section class="rounded-panel border border-line bg-surface p-4">
                <div class="mb-3 flex items-center gap-2">
                    <span class="h-5 w-5 text-ink-dim"><x-nav-icon name="pencil" peso="1.7" /></span>
                    <h2 class="font-display text-[15px] font-semibold text-ink">Anotações rápidas</h2>
                </div>
                <form method="POST" action="{{ route('secretario.anotacoes') }}" class="rounded-ctl border border-btn-line bg-canvas p-2">
                    @csrf
                    <textarea name="texto" x-ref="anotacao" rows="3" maxlength="500" required placeholder="Digite uma anotação rápida…"
                              class="w-full resize-none border-0 bg-transparent p-1 text-[13.5px] text-ink placeholder:text-ink-faint focus:outline-none focus:ring-0"></textarea>
                    <div class="flex justify-end"><button class="h-[32px] rounded-control bg-brand px-4 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright">Salvar</button></div>
                </form>
                @if ($anotacoes->isNotEmpty())
                    <ul class="mt-3 space-y-1.5">
                        @foreach ($anotacoes as $n)
                            <li class="flex items-start gap-2 text-[13px] text-ink">
                                <span class="min-w-0 flex-1 break-words">{{ $n->titulo }} <span class="text-[11px] text-ink-faint">· {{ $n->created_at->format('d/m H:i') }}</span></span>
                                <form method="POST" action="{{ route('secretario.anotacoes.arquivar', $n->id) }}">@csrf
                                    <button class="text-[11.5px] text-ink-faint hover:text-good" title="Arquivar">✓</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="rounded-panel border border-line bg-surface">
                <div class="flex items-center gap-2 px-4 pt-4">
                    <span class="h-5 w-5 text-good"><x-nav-icon name="check-circle" peso="1.7" /></span>
                    <h2 class="font-display text-[15px] font-semibold text-ink">Acompanhamentos</h2>
                    <a href="{{ route('secretario.acompanhamentos') }}" class="ml-auto text-[12.5px] font-semibold text-brand-text hover:underline">Ver todos →</a>
                </div>
                <ul class="mt-2 divide-y divide-line">
                    @forelse ($urgentes as $u)
                        <li class="flex items-start gap-2.5 px-4 py-2.5">
                            <i class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ ['alta' => 'bg-crit', 'normal' => 'bg-warn', 'baixa' => 'bg-brand'][$u['prioridade']] ?? 'bg-ink-faint' }}"></i>
                            <a href="{{ $u['link'] }}" class="min-w-0 flex-1 hover:text-brand">
                                <p class="line-clamp-2 text-[13px] font-semibold text-ink">{{ $u['titulo'] }}</p>
                                <p class="truncate text-[11.5px] text-ink-mute">{{ $u['sub'] }}</p>
                            </a>
                            @if ($u['prazo'])
                                <span class="shrink-0 text-[12px] font-semibold {{ in_array($u['prazo'], ['Hoje', 'Vencido'], true) ? 'text-crit' : ($u['prazo'] === 'Amanhã' ? 'text-warn' : 'text-brand-text') }}">{{ $u['prazo'] }}</span>
                            @endif
                        </li>
                    @empty
                        <li class="px-4 py-3 text-[13px] text-ink-mute">Nada pedindo acompanhamento agora.</li>
                    @endforelse
                </ul>
            </section>

            <section class="rounded-panel border border-line bg-surface">
                <div class="flex items-center gap-2 px-4 pt-4">
                    <span class="h-5 w-5 text-brand-text"><x-nav-icon name="envelope" peso="1.7" /></span>
                    <h2 class="font-display text-[15px] font-semibold text-ink">Mensagens recentes</h2>
                    <a href="{{ route('secretario.emails.index', ['ver' => 'todas']) }}" class="ml-auto text-[12.5px] font-semibold text-brand-text hover:underline">Ver todas →</a>
                </div>
                <ul class="mt-2 divide-y divide-line">
                    @forelse ($emailsRecentes as $e)
                        @php $nome = $e->de_nome ?: $e->de_email; @endphp
                        <li class="flex items-start gap-2.5 px-4 py-2.5">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand/10 text-[13px] font-semibold text-brand-text">{{ mb_strtoupper(mb_substr($nome, 0, 1)) }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-[13px] {{ $e->lido ? 'font-medium text-ink-dim' : 'font-semibold text-ink' }}">{{ $nome }}</p>
                                <p class="truncate text-[12px] text-ink-mute">{{ $e->assunto }}</p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1">
                                <span class="text-[11px] text-ink-faint">{{ $e->recebido_em->isToday() ? $e->recebido_em->format('H:i') : $e->recebido_em->format('d/m') }}</span>
                                @unless ($e->lido)<span class="h-2 w-2 rounded-full bg-good" title="não lido"></span>@endunless
                            </div>
                        </li>
                    @empty
                        <li class="px-4 py-3 text-[13px] text-ink-mute">Nenhuma caixa de e-mail ligada. Ligue em <a href="{{ route('secretario.configuracoes') }}" class="font-semibold text-brand-text">Configurações</a>.</li>
                    @endforelse
                </ul>
            </section>
        </aside>
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
                briefingAberto: (() => { try { return localStorage.getItem('secretario:briefing-aberto') !== 'nao'; } catch (e) { return true; } })(),
                dados: {}, carregado: false, texto: '', proposta: null, pergunta: '', recado: '', enviando: false, erro: '',
                secoes: [
                    // "Próximos" mora na Minha agenda; aqui, só o que é de hoje ou já passou.
                    { chave: 'atrasados', titulo: 'Atrasados' }, { chave: 'hoje', titulo: 'Tarefas de hoje' }, { chave: 'sem_data', titulo: 'Sem data' },
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
