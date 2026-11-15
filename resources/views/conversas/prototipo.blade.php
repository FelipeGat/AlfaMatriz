{{--
    Protótipo da tela Conversas (T-602) — o chat interno do time, para o dono do
    produto ver a ideia no staging antes de decidir. Nada aqui grava: o estado
    vive no Alpine e some ao recarregar (os dados vêm de App\Support\ConversasDeExemplo).

    O molde é o WhatsApp Web, como ele pediu: lista à esquerda, conversa à
    direita. As classes repetem as do Suporte WhatsApp (suporte/conversas), que já
    é uma tela de conversa no Matriz — duas telas de chat com cara diferente
    ensinariam o time a usar duas coisas.

    O que a tela precisa provar, um item do checklist cada:
    - a conversa da tarefa é a MESMA do card: o "Ver no card" lê o mesmo array
      que a lista, então o que se escreve aqui aparece lá na hora;
    - "Levar para a T-N" tira a mensagem do Geral e a põe na tarefa, deixando no
      Geral só o rastro — senão o assunto continua sendo discutido nos dois lugares;
    - o aviso de mensagem nova usa o card flutuante do sino (layouts/notificacoes),
      mesmo canto e mesma forma, porque é o aviso que o time já reconhece.
--}}
@php
    $eu = auth()->user()->name;
@endphp
<x-app-layout>
    <x-slot name="titulo">Conversas</x-slot>
    <x-slot name="contexto"><span>Protótipo · dados de exemplo · nada é gravado</span></x-slot>

    <script>
        window.conversasPrototipo = function (conversas, chegada, eu) {
            return {
                conversas,
                chegada,
                eu,
                atualId: 'geral',
                busca: '',
                texto: '',
                anexo: null,
                levando: null,
                verCard: false,
                aviso: null,
                chegou: false,
                listaNoCelular: true,

                init() {
                    // Entra-se pelo Geral, já lido: o contador que sobra na lista
                    // é o das OUTRAS conversas, que é o que ele quer mostrar.
                    this.abrir('geral');
                    this.listaNoCelular = true;
                    // A mensagem "chega" sozinha uma vez, para quem só abre a
                    // tela e olha; o botão Simular repete quando quiser.
                    setTimeout(() => this.simularChegada(), 12000);
                },
                get atual() { return this.conversas.find(c => c.id === this.atualId); },
                get tarefas() { return this.conversas.filter(c => c.tipo === 'tarefa'); },
                grupo(tipo) {
                    const termo = this.busca.trim().toLowerCase();
                    return this.conversas.filter(c => c.tipo === tipo
                        && (! termo || c.nome.toLowerCase().includes(termo)));
                },
                ultima(c) { return c.mensagens[c.mensagens.length - 1] ?? null; },
                iniciais(nome) { return nome.split(' ').slice(0, 2).map(p => p[0]).join(''); },
                abrir(id) {
                    this.atualId = id;
                    this.atual.nao_lidas = 0;
                    this.levando = null;
                    this.listaNoCelular = false;
                    if (this.aviso && this.aviso.conversa === id) this.aviso = null;
                    this.$nextTick(() => this.rolarAoFim());
                },
                rolarAoFim() {
                    const el = this.$refs.mensagens;
                    if (el) el.scrollTop = el.scrollHeight;
                },
                agora() {
                    const d = new Date();
                    return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
                },
                enviar() {
                    if (! this.texto.trim() && ! this.anexo) return;
                    this.atual.mensagens.push({
                        id: Date.now(), autor: this.eu, em: 'hoje ' + this.agora(),
                        texto: this.texto.trim(), pergunta: false, anexo: this.anexo, levada_de: null,
                    });
                    this.texto = '';
                    this.anexo = null;
                    this.$refs.arquivo.value = '';
                    this.$nextTick(() => this.rolarAoFim());
                },
                escolherAnexo(evento) {
                    this.anexo = evento.target.files[0]?.name ?? null;
                },
                levarPara(mensagem, destinoId) {
                    const destino = this.conversas.find(c => c.id === destinoId);
                    destino.mensagens.push({ ...mensagem, id: Date.now(), levada_de: 'Geral' });
                    destino.nao_lidas++;
                    // No Geral fica só o rastro: a conversa continua na tarefa.
                    mensagem.levada_para = destino.tarefa.codigo;
                    this.levando = null;
                },
                simularChegada() {
                    if (this.chegou) {
                        this.chegada.mensagem = { ...this.chegada.mensagem, id: Date.now() };
                    }
                    this.chegou = true;
                    const c = this.conversas.find(x => x.id === this.chegada.conversa);
                    c.mensagens.push({ ...this.chegada.mensagem, em: 'hoje ' + this.agora() });
                    if (this.atualId === c.id) {
                        this.$nextTick(() => this.rolarAoFim());
                    } else {
                        c.nao_lidas++;
                    }
                    this.aviso = { conversa: c.id, titulo: this.chegada.mensagem.autor + ' em ' + c.tarefa.codigo, texto: this.chegada.mensagem.texto };
                },
                get totalNaoLidas() { return this.conversas.reduce((s, c) => s + c.nao_lidas, 0); },
            };
        };
    </script>

    <div class="mx-auto w-full max-w-7xl space-y-3"
         x-data="conversasPrototipo(@js($conversas), @js($chegada), @js($eu))">

        {{-- A faixa diz o que é a tela antes de qualquer clique: quem a abrir no
             staging sem contexto não pode achar que a mensagem foi enviada. --}}
        <div class="flex flex-wrap items-center gap-2 rounded-panel border border-line bg-warn-tint/40 px-3 py-2 text-[12.5px] text-ink-dim">
            <span class="h-4 w-4 shrink-0 text-warn"><x-nav-icon name="alert-triangle" :peso="1.7" /></span>
            <span class="min-w-0 flex-1">Protótipo para decidir o chat interno: as conversas das tarefas são cópias do quadro, o resto é exemplo. Enviar, anexar e levar para a tarefa só valem nesta página e somem ao recarregar.</span>
            <button type="button" @click="simularChegada()"
                    class="inline-flex h-[28px] items-center gap-1 whitespace-nowrap rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim hover:border-brand hover:text-brand">
                <span class="h-3.5 w-3.5"><x-nav-icon name="bell" :peso="1.7" /></span>
                Simular mensagem nova
            </button>
        </div>

        <div class="grid h-[75vh] min-h-0 gap-3 lg:grid-cols-[340px_minmax(0,1fr)]">
            {{-- A lista --}}
            <section class="flex min-h-0 flex-col rounded-panel border border-line bg-surface"
                     :class="! listaNoCelular && 'max-lg:hidden'">
                <div class="border-b border-line px-3 py-2.5">
                    <label class="flex items-center gap-2 rounded-control border border-btn-line bg-input px-2 py-1">
                        <span class="h-3.5 w-3.5 shrink-0 text-ink-faint"><x-nav-icon name="search" :peso="1.7" /></span>
                        <input type="search" x-model="busca" placeholder="Buscar conversa ou T-N"
                               class="min-w-0 flex-1 border-0 bg-transparent p-0 text-[12.5px] text-ink focus:ring-0">
                    </label>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    {{-- Três grupos, na ordem em que se procura: o time todo, as
                         pessoas, e o trabalho. A tarefa leva o código na frente
                         porque é por ele que o time fala dela. --}}
                    @foreach (['geral' => null, 'direta' => 'Diretas', 'tarefa' => 'Tarefas'] as $tipo => $rotulo)
                        @if ($rotulo)
                            <p x-show="grupo(@js($tipo)).length" class="px-4 pt-3 pb-1 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">{{ $rotulo }}</p>
                        @endif
                        <ul class="divide-y divide-line">
                            <template x-for="c in grupo(@js($tipo))" :key="c.id">
                                <li>
                                    <button type="button" @click="abrir(c.id)"
                                            class="flex w-full items-start gap-2.5 px-4 py-2.5 text-left hover:bg-head/50"
                                            :class="atualId === c.id && 'bg-brand/10'">
                                        <span class="mt-px flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold"
                                              :class="c.tipo === 'tarefa' ? 'bg-chip text-ink-dim font-mono text-[9.5px]' : 'bg-brand/20 text-brand-text'">
                                            @if ($tipo === 'geral')
                                                <span class="h-4 w-4"><x-nav-icon name="users" :peso="1.7" /></span>
                                            @else
                                                <span x-text="c.tipo === 'tarefa' ? c.tarefa.codigo.replace('T-', '') : iniciais(c.nome)"></span>
                                            @endif
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="flex items-center justify-between gap-2">
                                                <span class="truncate text-[13.5px] font-semibold text-ink" x-text="c.nome"></span>
                                                <span class="shrink-0 whitespace-nowrap text-[10.5px] text-ink-faint" x-text="ultima(c)?.em"></span>
                                            </span>
                                            <span class="flex items-center gap-2">
                                                <span class="min-w-0 flex-1 truncate text-[12px] text-ink-dim"
                                                      x-text="ultima(c) ? ultima(c).autor.split(' ')[0] + ': ' + (ultima(c).texto || ultima(c).anexo) : '—'"></span>
                                                <span x-show="c.nao_lidas > 0" x-text="c.nao_lidas"
                                                      class="shrink-0 rounded-full bg-brand px-1.5 text-[11px] font-semibold text-on-brand"></span>
                                            </span>
                                        </span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    @endforeach
                </div>
            </section>

            {{-- A conversa --}}
            <section class="flex min-h-0 flex-col rounded-panel border border-line bg-surface"
                     :class="listaNoCelular && 'max-lg:hidden'">
                <div class="flex flex-wrap items-start gap-3 border-b border-line px-4 py-3">
                    <button type="button" @click="listaNoCelular = true" class="lg:hidden h-7 w-7 shrink-0 text-ink-mute hover:text-ink" aria-label="Voltar à lista">
                        <span class="block h-4 w-4"><x-nav-icon name="chevron-left" :peso="1.8" /></span>
                    </button>
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate font-display text-[16px] font-semibold text-ink" x-text="atual.nome"></h2>
                        <p class="text-[12px] text-ink-mute" x-text="atual.descricao"></p>
                    </div>
                    <template x-if="atual.tipo === 'tarefa'">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="rounded-badge bg-crit-tint px-1.5 py-0.5 text-[11px] font-semibold text-crit" x-text="atual.tarefa.prioridade"></span>
                            <span class="rounded-badge bg-head px-1.5 py-0.5 text-[11px] text-ink-dim" x-text="atual.tarefa.tipo"></span>
                            <button type="button" @click="verCard = true"
                                    class="inline-flex h-[28px] items-center gap-1 whitespace-nowrap rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim hover:border-brand hover:text-brand">
                                <span class="h-3.5 w-3.5"><x-nav-icon name="view-grid" :peso="1.7" /></span>
                                Ver no card do quadro
                            </button>
                        </div>
                    </template>
                </div>

                {{-- A vez de quem, como no card: numa conversa de tarefa a pergunta
                     em aberto é o que importa, e ela não pode se perder no meio
                     das mensagens. --}}
                <template x-if="atual.tipo === 'tarefa' && atual.tarefa.pergunta_para">
                    <div class="mx-4 mt-3 flex items-center gap-1.5 rounded-tile border-l-2 border-pergunta bg-pergunta-tint px-[9px] py-[7px]">
                        <span class="h-3 w-3 shrink-0 text-pergunta"><x-nav-icon name="duvida" :peso="1.9" /></span>
                        <span class="font-mono text-[9.5px] font-semibold uppercase tracking-[0.08em] text-pergunta"
                              x-text="'Aguardando resposta de ' + atual.tarefa.pergunta_para"></span>
                    </div>
                </template>

                <div x-ref="mensagens" class="min-h-0 flex-1 space-y-2 overflow-y-auto px-4 py-3">
                    <template x-for="m in atual.mensagens" :key="m.id">
                        <div class="group flex" :class="m.autor === eu ? 'justify-end' : 'justify-start'">
                            <div class="relative max-w-[80%] rounded-panel px-3 py-2 text-[13px] text-ink"
                                 :class="m.autor === eu ? 'bg-brand/10' : 'border border-line bg-canvas'">
                                <p class="text-[11.5px] font-semibold text-ink-dim" x-show="m.autor !== eu" x-text="m.autor"></p>
                                <p x-show="m.levada_de" class="mb-1 text-[10.5px] text-ink-faint" x-text="'Trazida do ' + m.levada_de"></p>
                                <p class="whitespace-pre-line break-words" :class="m.levada_para && 'text-ink-mute'" x-text="m.texto"></p>
                                <template x-if="m.anexo">
                                    <span class="mt-1.5 inline-flex items-center gap-1.5 rounded-control border border-line bg-surface px-2 py-1 text-[12px] text-ink-dim">
                                        <span class="h-3.5 w-3.5"><x-nav-icon name="paperclip" :peso="1.7" /></span>
                                        <span x-text="m.anexo"></span>
                                    </span>
                                </template>
                                <p class="mt-1 flex flex-wrap items-center gap-1 text-[10.5px] text-ink-faint">
                                    <span x-text="m.em"></span>
                                    <span x-show="m.pergunta" class="rounded-badge bg-pergunta-tint px-1 font-semibold text-pergunta">pergunta</span>
                                    <span x-show="m.levada_para" class="rounded-badge bg-chip px-1 font-semibold text-ink-dim" x-text="'levada para a ' + m.levada_para"></span>
                                </p>

                                {{-- Só no Geral: é ali que o assunto nasce solto e
                                     precisa ir para o lugar dele. Aparece ao passar
                                     o mouse, como o menu da mensagem no WhatsApp
                                     Web: fixo em toda mensagem, viraria ruído. Flutua
                                     na borda de cima da mensagem (como a barra do Slack) para não reservar uma
                                     linha vazia em cada uma enquanto está escondido. --}}
                                <template x-if="atual.tipo === 'geral' && ! m.levada_para">
                                    <div class="absolute -top-3 right-2 z-10 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100"
                                         :class="levando === m.id && '!opacity-100'">
                                        <button type="button" @click="levando = levando === m.id ? null : m.id"
                                                class="inline-flex h-[24px] items-center gap-1 whitespace-nowrap rounded-control border border-btn-line bg-panel px-2 text-[11.5px] font-semibold text-ink-dim hover:border-brand hover:text-brand">
                                            <span class="h-3 w-3"><x-nav-icon name="arrow-uturn-left" :peso="1.8" /></span>
                                            Levar para a tarefa
                                        </button>
                                        <div x-show="levando === m.id" x-cloak @click.outside="levando = null"
                                             class="absolute right-0 top-full mt-1 w-[300px] overflow-hidden rounded-control border border-line bg-panel shadow-[0_12px_32px_rgb(0_0_0_/_0.32)]">
                                            <template x-for="t in tarefas" :key="t.id">
                                                <button type="button" @click="levarPara(m, t.id)"
                                                        class="block w-full truncate px-2.5 py-1.5 text-left text-[12px] text-ink-dim hover:bg-chip hover:text-ink"
                                                        x-text="'Levar para a ' + t.nome"></button>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                <form @submit.prevent="enviar()" class="border-t border-line px-4 py-3">
                    <p x-show="anexo" x-cloak class="mb-1.5 inline-flex items-center gap-1.5 rounded-control border border-line bg-canvas px-2 py-1 text-[12px] text-ink-dim">
                        <span class="h-3.5 w-3.5"><x-nav-icon name="paperclip" :peso="1.7" /></span>
                        <span x-text="anexo"></span>
                        <button type="button" @click="anexo = null; $refs.arquivo.value = ''" class="h-3.5 w-3.5 text-ink-faint hover:text-ink" aria-label="Tirar o anexo"><x-nav-icon name="x-mark" :peso="1.8" /></button>
                    </p>
                    <div class="flex items-end gap-2">
                        {{-- O arquivo nunca sai do navegador: só o nome entra na
                             mensagem, para mostrar onde o anexo apareceria. --}}
                        <input type="file" x-ref="arquivo" class="hidden" @change="escolherAnexo($event)">
                        <button type="button" @click="$refs.arquivo.click()" title="Anexar arquivo"
                                class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-control border border-btn-line text-ink-mute hover:border-brand hover:text-brand">
                            <span class="h-4 w-4"><x-nav-icon name="paperclip" :peso="1.7" /></span>
                        </button>
                        <textarea x-model="texto" rows="2" maxlength="4000"
                                  :placeholder="'Escrever em ' + atual.nome.split(' · ')[0] + '…'"
                                  @keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); enviar(); }"
                                  class="min-w-0 flex-1 rounded-control border border-btn-line bg-canvas px-2.5 py-1.5 text-[13px] text-ink focus:border-brand focus:outline-none"></textarea>
                        <button class="h-[34px] rounded-control bg-brand px-4 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright">Enviar</button>
                    </div>
                    <p class="mt-1.5 text-[11px] text-ink-faint">Enter envia; Shift+Enter quebra a linha.</p>
                </form>
            </section>
        </div>

        {{--
            Como a conversa aparece no card do quadro. É uma réplica do card
            (tarefas/_card), e não o card de verdade, porque o protótipo não toca
            no quadro — mas lê o MESMO array da lista: escreva em Conversas e
            abra aqui, a mensagem está lá. É isso que "uma conversa só" quer dizer.
        --}}
        <template x-teleport="body">
            <div x-show="verCard" x-cloak @keydown.escape.window="verCard = false"
                 class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="verCard = false">
                <template x-if="atual.tipo === 'tarefa'">
                    <div class="w-[300px] max-w-full">
                        <p class="mb-2 font-mono text-[10.5px] uppercase tracking-caps text-white">Assim fica no card do quadro</p>
                        <div class="rounded-[5px] border-2 border-line bg-card-quadro p-[10px] shadow-card">
                            <p class="text-[13.5px] font-medium leading-[1.35] text-ink">
                                <span class="font-mono text-[11.5px] font-semibold text-ink-dim" x-text="atual.tarefa.codigo"></span>
                                <span x-text="atual.tarefa.titulo"></span>
                            </p>
                            <p class="mt-[5px] text-[11.5px] leading-[1.4] text-ink-faint" x-text="atual.tarefa.sistema + ' · ' + atual.tarefa.responsavel"></p>

                            {{-- A tarja da pergunta continua no card, como hoje:
                                 a conversa compacta não a substitui. --}}
                            <div x-show="atual.tarefa.pergunta_para" class="mt-2 flex items-center gap-1.5 rounded-tile border-l-2 border-pergunta bg-pergunta-tint px-[9px] py-[7px]">
                                <span class="h-3 w-3 shrink-0 text-pergunta"><x-nav-icon name="duvida" :peso="1.9" /></span>
                                <span class="min-w-0 truncate font-mono text-[9.5px] font-semibold uppercase tracking-[0.08em] text-pergunta"
                                      x-text="'Aguardando ' + atual.tarefa.pergunta_para"></span>
                            </div>

                            {{-- A conversa compacta: as duas últimas, duas linhas
                                 cada. O resto mora em Conversas, a um clique. --}}
                            <div class="mt-2 rounded-tile border-l-2 border-line bg-chip px-[9px] py-[7px]">
                                <div class="flex items-center gap-1.5">
                                    <span class="h-3 w-3 shrink-0 text-ink-faint"><x-nav-icon name="balao" :peso="1.9" /></span>
                                    <span class="flex-1 font-mono text-[9.5px] font-semibold uppercase tracking-[0.08em] text-ink-mute">Conversa</span>
                                    <span class="font-sans tabular text-[9.5px] text-ink-mute" x-text="atual.mensagens.length + ' mensagens'"></span>
                                </div>
                                <template x-for="m in atual.mensagens.slice(-2)" :key="m.id">
                                    <p class="mt-1 line-clamp-2 text-[11.5px] leading-[1.4] text-ink">
                                        <span class="font-semibold" x-text="m.autor.split(' ')[0] + ':'"></span>
                                        <span x-text="m.texto || m.anexo"></span>
                                    </p>
                                </template>
                                <button type="button" @click="verCard = false; $nextTick(() => rolarAoFim())"
                                        class="mt-[7px] h-[26px] w-full rounded-tile border border-btn-line text-[11.5px] font-semibold text-ink-dim hover:border-brand hover:text-brand">
                                    Abrir em Conversas
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        {{-- O aviso de mensagem nova: o card flutuante do sino, no mesmo canto. --}}
        <div x-show="aviso" x-cloak
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-2"
             class="fixed bottom-4 right-4 z-[65] w-[300px] max-w-[calc(100vw-32px)] overflow-hidden rounded-panel border border-line bg-panel shadow-[0_12px_32px_rgb(0_0_0_/_0.32)]">
            <div role="button" tabindex="0" @click="abrir(aviso.conversa)" @keydown.enter="abrir(aviso.conversa)"
                 class="flex cursor-pointer items-start gap-3 p-3 text-left transition hover:bg-chip">
                <span class="mt-px flex h-8 w-8 shrink-0 items-center justify-center rounded-tile"
                      style="background: rgb(var(--brand) / var(--tint-alpha)); color: rgb(var(--brand))">
                    <span class="h-[16px] w-[16px]"><x-nav-icon name="balao" :peso="1.8" /></span>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[13px] font-semibold text-ink" x-text="aviso?.titulo"></span>
                    <span class="block truncate text-[11.5px] text-ink-mute" x-text="aviso?.texto"></span>
                </span>
                <button type="button" @click.stop="aviso = null"
                        class="flex h-6 w-6 shrink-0 items-center justify-center rounded-badge text-ink-faint transition hover:text-ink" aria-label="Dispensar">
                    <span class="h-3.5 w-3.5"><x-nav-icon name="x-mark" :peso="1.7" /></span>
                </button>
            </div>
        </div>
    </div>
</x-app-layout>
