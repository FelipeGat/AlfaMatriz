<x-app-layout>
    <x-slot name="titulo">Central de IA</x-slot>
    <x-slot name="contexto">Cockpit de projetos</x-slot>

    {{--
        Sem rolagem de página (pedido do dono, 10/10/2026): o cockpit ocupa a
        altura da janela — 100dvh menos a topbar (56px) e o respiro do <main>
        (24 + 40) — e só as LISTAS rolam por dentro. O conteúdo é separado em
        abas para caber.
    --}}
    <section x-data="centralIa({
                painel: '{{ route('central-ia.painel') }}',
                relatorio: '{{ route('central-ia.relatorio') }}',
                historico: '{{ route('central-ia.mensagens') }}',
                enviar: '{{ route('central-ia.enviar') }}',
                anexar: '{{ route('central-ia.anexos.criar') }}',
                tarefas: '{{ route('central-ia.tarefas', '__P__') }}',
                fixar: '{{ route('central-ia.fixar', '__P__') }}',
                statusTarefa: '{{ url('central-ia/tarefas/__T__/status') }}',
                ponto: '{{ url('central-ia/tarefas/__T__/ponto') }}',
                continuar: '{{ route('central-ia.sessoes.continuar') }}',
                grupos: '{{ route('central-ia.grupos.criar') }}',
                assuntos: '{{ route('central-ia.assuntos.criar') }}',
                assunto: '{{ url('central-ia/assuntos/__A__') }}',
                semear: '{{ route('central-ia.assuntos.semear') }}',
                grupo: '{{ url('central-ia/grupos/__G__') }}',
                grupoDoProjeto: '{{ route('central-ia.projetos.grupo', '__P__') }}',
                canal: '{{ route('central-ia.canal') }}',
                preferencias: '{{ route('central-ia.preferencias') }}',
                arquivarChats: '{{ route('central-ia.chats.arquivar') }}',
                administracao: '{{ route('central-ia.administracao') }}',
                politicaUsuario: '{{ url('central-ia/usuarios/__U__/politica') }}',
                telegramCodigo: '{{ route('central-ia.telegram.codigo') }}',
                telegramDesvincular: '{{ route('central-ia.telegram.desvincular') }}',
                confirmar: '{{ url('central-ia/execucoes/__E__/confirmar') }}',
                pops: '{{ route('central-ia.pops.criar') }}',
                pop: '{{ url('central-ia/pops/__P__') }}',
                podeEnviar: {{ $podeEnviar ? 'true' : 'false' }},
             })"
             data-central-ia class="mx-auto flex h-[calc(100dvh-7.5rem)] max-w-[1600px] flex-col gap-3">

        {{--
            Faixa única (revisão de UX, 12/10/2026): abas à esquerda; à direita
            um selo com o estado da máquina (abre os detalhes) e o canal. As
            três linhas de antes comiam ~280px no celular antes do conteúdo.
        --}}
        <div class="flex flex-none items-center gap-2 text-xs">
            <nav class="flex min-w-0 flex-1 overflow-x-auto sm:flex-none" aria-label="Seções da Central">
                <div class="flex flex-none rounded-control border border-line bg-panel p-0.5">
                @foreach (['chats' => 'Chats Ativos', 'business' => 'Business', 'projetos' => 'Projetos', 'atividade' => 'Atividade', 'consumo' => 'Consumo', 'ajustes' => 'Ajustes'] as $chave => $rotulo)
                    <button type="button" @click="irPara('{{ $chave }}')" class="whitespace-nowrap rounded-ctl px-3 py-1.5 font-semibold"
                            :class="tela === '{{ $chave }}' ? 'bg-nav-active text-ink' : 'text-ink-mute hover:text-ink'"
                            :aria-current="tela === '{{ $chave }}' ? 'page' : null">
                        {{ $rotulo }}
                        @if ($chave === 'business')
                            <span x-show="assuntos.some(a => novidades[a.id])" class="ml-1 inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-pergunta"></span>
                        @endif
                        @if ($chave === 'chats')
                            <span x-show="totalNovidades" class="ml-1 animate-pulse rounded-badge bg-pergunta px-1 text-on-brand" x-text="totalNovidades"></span>
                        @endif
                        @if ($chave === 'atividade')
                            <span x-show="emExecucao + naFila" class="ml-1 rounded-badge bg-brand px-1 text-on-brand" x-text="emExecucao + naFila"></span>
                            <span x-show="eventos.some(e => e.nivel === 'erro' && Date.now() - new Date(e.em) < 3600000)" class="ml-1 inline-block h-1.5 w-1.5 rounded-full bg-crit"></span>
                        @endif
                    </button>
                @endforeach
                </div>
            </nav>
            <div class="ml-auto flex flex-none items-center gap-2">
                <button type="button" @click="alternarIndicadores()" :aria-expanded="(!indicadoresRecolhidos).toString()" aria-controls="central-indicadores"
                        class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-badge border px-2 py-1"
                        :class="maquina.online ? 'border-good-line bg-good-tint text-good' : 'border-crit bg-crit-tint text-crit'"
                        :title="'Conversa privada de ' + contaNome + ' · ' + rotuloConexao + ' · clique para ver os detalhes'">
                    <span class="h-1.5 w-1.5 rounded-full" :class="maquina.online ? 'bg-good' : 'bg-crit'"></span>
                    <span class="hidden sm:inline" x-text="!maquina.online ? 'Máquina fora do ar' : (emExecucao ? emExecucao + ' rodando' + (naFila ? ' · ' + naFila + ' na fila' : '') : 'Pronta')"></span>
                    <span class="sm:hidden" x-show="emExecucao" x-text="emExecucao"></span>
                </button>
                <button type="button" x-show="atendimento.disponivel && (telegram.vinculado || atendimento.ativo === 'telegram')" x-cloak
                        @click="transferirCanal(atendimento.ativo === 'telegram' ? 'web' : 'telegram')"
                        :disabled="transferindoCanal || aba.enviando || !!aba.pendente || !podeEnviar"
                        class="whitespace-nowrap rounded-badge border px-2 py-1 font-semibold disabled:opacity-50"
                        :class="atendimento.ativo === 'telegram' ? 'border-brand-mute bg-nav-active text-ink' : 'border-line text-brand-text'"
                        :title="atendimento.ativo === 'telegram' ? 'O atendimento está no Telegram (' + (atendimento.projeto || 'Geral') + '). Clique para voltar para cá.' : 'Passar o atendimento para o Telegram, com o histórico'"
                        :aria-label="atendimento.ativo === 'telegram' ? 'Voltar o atendimento para o AlfaMatriz' : 'Continuar pelo Telegram'">
                    <span>📱</span><span class="hidden sm:inline" x-text="transferindoCanal ? ' Transferindo…' : (atendimento.ativo === 'telegram' ? ' No Telegram · voltar' : ' Telegram')"></span>
                </button>
            </div>
        </div>

        <div id="central-indicadores" x-show="!indicadoresRecolhidos" x-cloak class="flex flex-none flex-wrap items-center gap-2 text-xs">
            @foreach ($agentes as $chave => $nome)
                <span class="rounded-badge border px-2 py-1"
                      :class="consumo.limites?.esgotados?.['{{ $chave }}'] ? 'border-warn-line text-warn' : (maquina.agentes.includes('{{ $chave }}') ? 'border-line text-ink-dim' : 'border-line text-ink-faint line-through')"
                      :title="consumo.limites?.esgotados?.['{{ $chave }}'] || ''">{{ $nome }}<span x-show="consumo.limites?.esgotados?.['{{ $chave }}']"> · no limite</span></span>
            @endforeach
            <span class="rounded-badge border border-line px-2 py-1 text-ink-mute" x-text="emExecucao + ' de ' + paralelo + ' rodando' + (naFila ? ' · ' + naFila + ' na fila' : '')"></span>
            <span class="rounded-badge border border-line px-2 py-1 text-ink-mute"
                  x-show="consumo.limites" x-text="(consumo.limites?.central.usados ?? 0) + (consumo.limites?.central.limite == null ? ' pedidos hoje · sem teto interno' : ' de ' + consumo.limites.central.limite + ' pedidos hoje')"></span>
            <span class="rounded-badge border border-line px-2 py-1 text-ink-mute" x-text="'Conversa privada de ' + contaNome"></span>
            <span role="status" aria-live="polite" class="rounded-badge border border-line px-2 py-1 text-ink-mute" x-text="rotuloConexao"></span>
            <button type="button" x-show="!telegram.vinculado" @click="irPara('ajustes')" class="font-semibold text-brand-text">Vincular Telegram</button>
        </div>

        {{--
            O que espera por você, em qualquer aba (FR-029): o código que a sua
            máquina mandou ao Telegram e as dúvidas dos agentes. Um clique.
        --}}
        <section x-show="esperandoVoce" x-cloak aria-label="Esperando você"
                 class="flex-none space-y-1.5 rounded-panel border border-pergunta-line bg-pergunta-tint px-3 py-2 text-sm">
            <template x-for="c in confirmacoes" :key="'c' + c.id">
                <form @submit.prevent="confirmarCodigo(c.id)" class="flex flex-wrap items-center gap-2">
                    <span class="font-semibold text-pergunta">🔐 Confirme</span>
                    <span class="min-w-0 flex-1 text-ink"
                          x-text="'#' + c.id + ' · ' + (c.resumo || (c.agente + ' em ' + (c.projeto || 'Geral'))) + (c.tentativas ? ' · ' + c.tentativas + ' de 3 tentativas erradas' : '')"></span>
                    <label class="sr-only" :for="'central-codigo-' + c.id" x-text="'Código do pedido #' + c.id"></label>
                    <input :id="'central-codigo-' + c.id" x-model="codigos[c.id]" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                           placeholder="código do Telegram"
                           class="w-40 rounded-control border-line bg-input py-1 text-sm tracking-widest text-ink placeholder-ink-faint focus:border-brand focus:ring-brand">
                    <button type="submit" :disabled="confirmando === c.id"
                            class="rounded-control bg-brand px-3 py-1.5 text-xs font-semibold text-on-brand disabled:opacity-50">Confirmar</button>
                </form>
            </template>
            <template x-for="t in duvidasPendentes" :key="'d' + t.id">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-semibold text-pergunta">❓ Dúvida</span>
                    <span class="min-w-0 flex-1 truncate text-ink" x-text="(t.projeto || 'Geral') + ' · #' + t.id + ' ' + t.titulo"></span>
                    <button type="button" @click="abrirChat(t)" class="text-xs font-semibold text-brand-text">Responder</button>
                </div>
            </template>
        </section>

        <div x-show="aviso" x-cloak role="alert" class="flex-none rounded-panel border border-warn-line bg-warn-tint px-4 py-2 text-sm text-ink">
            <span x-text="aviso"></span>
            <button type="button" @click="aviso = ''" x-show="!bloqueado" class="ml-2 font-semibold text-brand-text">Fechar</button>
            <a href="{{ route('login') }}" x-show="bloqueado" class="ml-2 font-semibold text-brand-text">Entrar de novo</a>
        </div>

        <div x-show="tela === 'projetos'" x-cloak class="min-h-0 flex-1 overflow-y-auto space-y-4">
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <input type="search" x-model="busca" placeholder="Buscar projeto…" aria-label="Buscar projeto" autocomplete="off"
                       class="w-56 rounded-control border-line bg-input py-1.5 text-sm text-ink placeholder-ink-faint focus:border-brand focus:ring-brand">
                <div class="flex rounded-control border border-line p-0.5" role="group" aria-label="Exibição">
                    <button type="button" @click="definirModoProjetos('cards')" :aria-pressed="(modoProjetos === 'cards').toString()" class="rounded-ctl px-2.5 py-1 font-semibold" :class="modoProjetos === 'cards' ? 'bg-nav-active text-ink' : 'text-ink-mute'">Cards</button>
                    <button type="button" @click="definirModoProjetos('lista')" :aria-pressed="(modoProjetos === 'lista').toString()" class="rounded-ctl px-2.5 py-1 font-semibold" :class="modoProjetos === 'lista' ? 'bg-nav-active text-ink' : 'text-ink-mute'">Lista</button>
                </div>
                <button type="button" @click="criarGrupo()" class="font-semibold text-brand-text">+ Novo grupo</button>
                <span class="text-ink-faint">Clique num projeto para conversar sobre ele; o grupo muda no rodapé do card.</span>
            </div>
            <template x-for="g in secoes" :key="g.id"><section class="space-y-2">
                {{-- Seta para recolher o grupo (fica lembrado neste navegador). --}}
                <h3 class="font-semibold text-ink">
                    <button type="button" @click="recolher('g' + g.id)" class="flex items-center gap-2 hover:text-brand-text" :aria-expanded="(!recolhidos.includes('g' + g.id)).toString()">
                        <span class="inline-block text-xs text-ink-mute transition-transform" :class="recolhidos.includes('g' + g.id) ? '-rotate-90' : ''">▼</span>
                        <span x-text="g.nome + ' (' + g.projetos.length + ')'"></span>
                        <span x-show="g.projetos.some(p => p.tarefas_em_curso)" class="rounded-badge bg-brand px-1.5 text-[11px] text-on-brand"
                              x-text="g.projetos.filter(p => p.tarefas_em_curso).length + ' com chat aberto'"></span>
                    </button>
                </h3>
                <div x-show="!recolhidos.includes('g' + g.id)" :class="modoProjetos === 'cards' ? 'grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5' : 'flex flex-col gap-2'">
                    <template x-for="p in g.projetos" :key="p.nome">
                        {{-- O grupo muda no próprio card: escondido no cabeçalho do
                             projeto aberto, ninguém achava (dono, 12/10/2026). --}}
                        {{-- Projeto com chat em aberto: card destacado. --}}
                        <div class="min-w-0 rounded-panel border hover:border-brand-mute"
                             :class="(p.tarefas_em_curso ? 'border-brand-mute bg-nav-active' : 'border-line bg-panel') + (modoProjetos === 'lista' ? ' flex items-center justify-between gap-3 pr-2' : '')">
                            <button type="button" @click="abrirProjetoCatalogo(p)" :title="'Abrir ' + p.nome" class="block w-full min-w-0 px-3 pt-2.5 text-left" :class="modoProjetos === 'lista' ? 'pb-2.5' : 'pb-1'">
                                <strong class="block truncate text-sm text-ink" x-text="p.nome"></strong>
                                <span class="block truncate text-xs" :class="p.tarefas_em_curso ? 'text-brand-text' : 'text-ink-faint'"
                                      x-text="(p.tarefas_em_curso ? p.tarefas_em_curso + ' em curso' : 'nada em curso') + ' · ' + (p.git?.branch || 'sem git')"></span>
                            </button>
                            <label class="flex flex-none items-center gap-1 px-3 pb-2 text-[11px] text-ink-faint" :class="modoProjetos === 'lista' ? 'pb-0 px-0' : ''">
                                <span>Grupo</span>
                                <select :value="p.grupo_id || ''" @change="moverParaGrupo(p.nome, $event.target.value || null)" :aria-label="'Grupo de ' + p.nome"
                                        class="min-w-0 flex-1 cursor-pointer rounded-control border-transparent bg-transparent py-0 pl-1 pr-6 text-[11px] text-ink-dim hover:border-line focus:border-brand focus:ring-brand">
                                    <option value="">Sem grupo</option>
                                    <template x-for="gr in grupos" :key="gr.id"><option :value="gr.id" :selected="p.grupo_id === gr.id" x-text="gr.nome"></option></template>
                                    <option value="novo">+ Novo grupo…</option>
                                </select>
                            </label>
                        </div>
                    </template>
                </div>
            </section></template>
            <p x-show="!projetos.length" class="text-ink-mute">Nenhum projeto informado para sua conta. Verifique a conexão da máquina.</p>
        </div>
        {{-- ================= PROJETOS ================= --}}
        <div x-show="['chats', 'business'].includes(tela)" class="relative grid min-h-0 flex-1 grid-cols-1 gap-3"
             :class="projetosRecolhidos ? 'lg:grid-cols-1' : 'lg:grid-cols-[260px_minmax(0,1fr)]'">

            {{-- Barra de projetos: busca + grupos (no celular, gaveta sobre o conteúdo) --}}
            <aside id="central-projetos" x-show="tela === 'projetos'" aria-label="Projetos"
                   class="min-h-0 flex-col gap-2 overflow-hidden rounded-panel border border-line bg-panel p-2"
                   :class="menuProjetosMovel ? 'absolute inset-0 z-10 flex' : (projetosRecolhidos ? 'hidden' : 'hidden lg:flex')">
                <div class="flex flex-none gap-1.5">
                    <label class="sr-only" for="central-busca">Buscar projeto</label>
                    <input id="central-busca" type="search" x-model="busca" placeholder="Buscar projeto…" autocomplete="off"
                           class="min-w-0 flex-1 rounded-control border-line bg-input py-1.5 text-sm text-ink placeholder-ink-faint focus:border-brand focus:ring-brand">
                    <button type="button" @click="menuProjetosMovel = false" class="rounded-control border border-line px-2 text-xs text-ink-mute lg:hidden">Fechar</button>
                </div>

                <div class="min-h-0 flex-1 space-y-2 overflow-y-auto pr-0.5">
                    <button type="button" @click="abrir('')" x-show="!busca"
                            class="flex w-full items-center gap-2 rounded-control border px-2.5 py-1.5 text-left text-sm"
                            :class="atual === '' ? 'border-brand-mute bg-nav-active text-ink' : 'border-line text-ink-dim hover:bg-chip'">
                        <span class="h-4 w-4 flex-none text-brand-text"><x-nav-icon name="chat" /></span>
                        <span class="font-semibold">Geral</span>
                        <span class="ml-auto text-[11px] text-ink-faint">fora de projeto</span>
                    </button>

                    <template x-for="sec in secoes" :key="sec.id">
                        <div>
                            <button type="button" @click="recolher(sec.id)" class="flex w-full min-w-0 items-center gap-1 px-1 py-1 text-left font-mono text-[11px] uppercase tracking-caps text-ink-faint hover:text-ink">
                                <span x-text="recolhidos.includes(sec.id) && !busca ? '▸' : '▾'"></span>
                                <span class="overflow-hidden text-ellipsis whitespace-nowrap" x-text="sec.nome"></span>
                                <span x-text="'(' + sec.projetos.length + ')'"></span>
                                <span x-show="sec.projetos.some(p => p.atencao)" class="h-1.5 w-1.5 rounded-full bg-pergunta"></span>
                            </button>
                            <div class="space-y-1" x-show="!recolhidos.includes(sec.id) || busca">
                                <p x-show="!sec.projetos.length" class="px-2 py-1 text-xs text-ink-faint">Vazio — escolha o grupo no cabeçalho de um projeto.</p>
                                <template x-for="p in sec.projetos" :key="p.nome">
                                    <button type="button" @click="abrir(p.nome)"
                                            class="relative w-full rounded-control border px-2.5 py-1.5 text-left"
                                            :class="atual === p.nome ? 'border-brand-mute bg-nav-active' : 'border-line hover:bg-chip'">
                                        <span x-show="p.atencao" class="absolute right-2 top-2 h-2 w-2 rounded-full" :class="p.estado === 'falhou' ? 'bg-crit' : 'bg-pergunta'"></span>
                                        <span class="block overflow-hidden text-ellipsis whitespace-nowrap pr-3 text-sm font-semibold text-ink" x-text="p.nome"></span>
                                        <span class="mt-0.5 flex flex-wrap items-center gap-1 text-[11px]">
                                            <span class="rounded-badge border px-1 py-px" :class="corDoEstado(p.estado)" x-text="estado(p.estado)"></span>
                                            <span class="text-ink-faint" x-text="(p.agente ? p.agente + ' · ' : '') + haQuanto(p.ultima_atividade)"></span>
                                            <span x-show="p.git?.pendentes" class="text-warn" x-text="p.git?.pendentes + ' pend.'"></span>
                                        </span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>

                    <div x-show="achadosNaMaquina.length" class="space-y-1">
                        <p class="px-1 font-mono text-[11px] uppercase tracking-caps text-ink-faint">Na máquina, fora do cockpit</p>
                        <template x-for="p in achadosNaMaquina" :key="p">
                            <button type="button" @click="abrir(p)" class="w-full rounded-control border border-dashed border-line px-2.5 py-1.5 text-left text-sm text-ink-dim hover:bg-chip" x-text="p"></button>
                        </template>
                    </div>
                    <p x-show="busca && !secoes.length && !achadosNaMaquina.length" class="px-2 py-2 text-xs text-ink-faint">Nenhum projeto com esse nome.</p>
                </div>
            </aside>

            <aside id="central-chats" x-show="tela === 'chats'" x-cloak aria-label="Chats ativos"
                   class="min-h-0 flex-col gap-2 overflow-hidden rounded-panel border border-line bg-panel p-2"
                   :class="menuProjetosMovel ? 'absolute inset-0 z-10 flex' : (projetosRecolhidos ? 'hidden' : 'hidden lg:flex')">
                <div class="flex items-center justify-between gap-2 px-1 text-sm font-semibold text-ink">
                    <span>Chats Ativos <span class="text-ink-mute" x-text="'(' + chatsFiltrados.length + ')'"></span></span>
                    <button type="button" @click="menuProjetosMovel = false" class="text-xs text-ink-mute lg:hidden">Fechar</button>
                </div>
                {{-- Novo chat, sem projeto (Geral) ou num projeto. O primeiro pedido cria o chat. --}}
                <div class="flex gap-1.5">
                    <button type="button" @click="novoChat(novoChatEm)" class="flex-none rounded-control bg-brand px-3 py-1.5 text-xs font-semibold text-on-brand">+ Novo chat</button>
                    <label class="sr-only" for="central-novo-chat-em">Onde</label>
                    <select id="central-novo-chat-em" x-model="novoChatEm" class="min-w-0 flex-1 rounded-control border-line bg-input py-1.5 text-xs text-ink">
                        <option value="">Geral (sem projeto)</option>
                        <template x-for="p in maquina.projetos" :key="p"><option :value="p" x-text="p"></option></template>
                    </select>
                </div>
                <label class="sr-only" for="central-busca-chats">Buscar assunto ou projeto</label>
                @if ($podeParear)
                    <button type="button" @click="arquivarChats()" :disabled="arquivando || !chats.length" class="rounded-control border border-btn-line px-3 py-1.5 text-xs font-semibold text-ink disabled:opacity-50">Arquivar chats antigos</button>
                @endif
                <input id="central-busca-chats" type="search" x-model="buscaChats" placeholder="Buscar assunto ou projeto…" autocomplete="off"
                       class="rounded-control border-line bg-input py-1.5 text-sm text-ink placeholder-ink-faint focus:border-brand focus:ring-brand">
                <label class="sr-only" for="central-filtro-chat-projeto">Projeto dos chats</label>
                <select id="central-filtro-chat-projeto" x-model="filtroChatProjeto" class="rounded-control border-line bg-input py-1.5 text-xs text-ink">
                    <option value="">Todos os projetos</option>
                    <template x-for="p in projetosDosChats" :key="p"><option :value="p" x-text="p || 'Geral'"></option></template>
                </select>
                <label class="sr-only" for="central-filtro-chat-estado">Estado dos chats</label>
                <select id="central-filtro-chat-estado" x-model="filtroChatEstado" class="rounded-control border-line bg-input py-1.5 text-xs text-ink">
                    <option value="ativos">Em andamento</option><option value="concluida">Concluídas (últimas 24 h)</option><option value="executando">Executando</option>
                    <option value="aguardando_voce">Aguardando você</option><option value="falhou">Com falha</option>
                    <option value="aberta">Abertos / na fila</option><option value="pausada">Pausados</option><option value="todos">Todos (pausados e concluídas)</option>
                </select>
                <div class="min-h-0 flex-1 space-y-2 overflow-y-auto">
                    <p x-show="!chatsFiltrados.length" class="p-2 text-xs text-ink-mute">Nenhum chat corresponde ao filtro. Inicie outro assunto pela aba Projetos.</p>
                    <template x-for="c in chatsFiltrados" :key="c.id">
                        {{-- Chat com novidade pisca até ser aberto; esperando você, na cor da pergunta. --}}
                        <button type="button" @click="abrirChat(c); pedirNotificacoes()" :aria-pressed="(chatAtual === c.id).toString()"
                                class="w-full rounded-control border px-3 py-2 text-left hover:bg-chip"
                                :class="(chatAtual === c.id ? 'border-brand-mute bg-nav-active' : (c.status === 'aguardando_voce' ? 'border-pergunta-line bg-pergunta-tint' : 'border-line'))
                                    + (novidades[c.id] ? ' animate-pulse ring-2 ring-brand' : '')">
                            <span x-show="novidades[c.id]" class="float-right rounded-badge px-1.5 text-[10px] font-semibold text-on-brand"
                                  :class="novidades[c.id] === 'falhou' ? 'bg-crit' : (novidades[c.id] === 'aguardando' ? 'bg-pergunta' : 'bg-brand')"
                                  x-text="{ terminou: '✓ terminou', aguardando: '❓ espera você', falhou: '✕ falhou' }[novidades[c.id]]"></span>
                            <span class="block text-[11px] text-ink-mute" x-text="(c.projeto || 'Geral') + ' · #' + c.id"></span>
                            <span class="mt-1 block break-words text-sm font-semibold text-ink" x-text="c.titulo"></span>
                            <span class="mt-1 flex flex-wrap gap-1 text-[11px]">
                                <span class="rounded-badge border px-1" :class="corDoEstado(c.status)" x-text="estado(c.status)"></span>
                                <span class="text-ink-mute" x-text="(c.agente || 'Sem agente') + ' · ' + haQuanto(c.ultima_atividade)"></span>
                            </span>
                        </button>
                    </template>
                </div>
            </aside>

            {{-- Chats Business: um chat contínuo por assunto, nos grupos da conta (docs/specs/chats-business). --}}
            <aside id="central-business" x-show="tela === 'business'" x-cloak aria-label="Chats Business"
                   class="min-h-0 flex-col gap-2 overflow-hidden rounded-panel border border-line bg-panel p-2"
                   :class="menuProjetosMovel ? 'absolute inset-0 z-10 flex' : (projetosRecolhidos ? 'hidden' : 'hidden lg:flex')">
                <div class="flex items-center justify-between gap-2 px-1 text-sm font-semibold text-ink">
                    <span>Business <span class="text-ink-mute" x-text="'(' + assuntos.length + ')'"></span></span>
                    <button type="button" @click="menuProjetosMovel = false" class="text-xs text-ink-mute lg:hidden">Fechar</button>
                </div>
                <p class="px-1 text-[11px] text-ink-faint">Um chat por assunto. Qualquer IA que atender já sabe o histórico e a memória dele.</p>
                <button type="button" x-show="!novoAssunto" @click="novoAssunto = { titulo: '', grupo_id: '', projeto: '', salvando: false }; $nextTick(() => document.getElementById('central-novo-assunto')?.focus())"
                        class="rounded-control bg-brand px-3 py-1.5 text-xs font-semibold text-on-brand">+ Novo assunto</button>
                <button type="button" x-show="!novoAssunto" @click="abrirTrazer()" class="rounded-control border border-btn-line px-3 py-1.5 text-xs font-semibold text-ink">Trazer do VS Code</button>
                <form x-show="novoAssunto" x-cloak @submit.prevent="criarAssunto()" class="space-y-1.5 rounded-control border border-line p-2">
                    <label class="sr-only" for="central-novo-assunto">Nome do assunto</label>
                    <input id="central-novo-assunto" type="text" maxlength="120" x-bind:value="novoAssunto?.titulo ?? ''" @input="novoAssunto.titulo = $event.target.value" placeholder="Ex.: Comercial VixCard"
                           class="w-full rounded-control border-line bg-input py-1.5 text-sm text-ink placeholder-ink-faint focus:border-brand focus:ring-brand">
                    <label class="sr-only" for="central-novo-assunto-grupo">Grupo</label>
                    <select id="central-novo-assunto-grupo" @change="novoAssunto.grupo_id = $event.target.value" class="w-full rounded-control border-line bg-input py-1.5 text-xs text-ink">
                        <option value="">Sem grupo</option>
                        <template x-for="g in grupos" :key="g.id"><option :value="g.id" x-text="g.nome"></option></template>
                    </select>
                    <label class="sr-only" for="central-novo-assunto-projeto">Projeto</label>
                    <select id="central-novo-assunto-projeto" @change="novoAssunto.projeto = $event.target.value" class="w-full rounded-control border-line bg-input py-1.5 text-xs text-ink">
                        <option value="">Sem projeto (pastas de ~/projetos)</option>
                        <template x-for="p in maquina.projetos" :key="p"><option :value="p" x-text="'Projeto: ' + p"></option></template>
                    </select>
                    <div class="flex gap-1.5">
                        <button type="submit" :disabled="!novoAssunto?.titulo.trim() || novoAssunto?.salvando" class="flex-1 rounded-control bg-brand px-3 py-1.5 text-xs font-semibold text-on-brand disabled:opacity-50">Criar</button>
                        <button type="button" @click="novoAssunto = null" class="rounded-control border border-line px-3 py-1.5 text-xs text-ink-mute">Cancelar</button>
                    </div>
                </form>
                <label class="sr-only" for="central-busca-assuntos">Buscar assunto</label>
                <input id="central-busca-assuntos" type="search" x-model="buscaAssuntos" placeholder="Buscar assunto…" autocomplete="off"
                       class="rounded-control border-line bg-input py-1.5 text-sm text-ink placeholder-ink-faint focus:border-brand focus:ring-brand">
                <div class="min-h-0 flex-1 space-y-2 overflow-y-auto pr-0.5">
                    <p x-show="!assuntos.length" class="p-2 text-xs text-ink-mute">Nenhum assunto ainda. Crie o primeiro: "Comercial VixCard", "Andamento de obras"…</p>
                    <template x-for="sec in secoesAssuntos" :key="sec.id">
                        <div>
                            <button type="button" @click="recolher(sec.id)" class="flex w-full min-w-0 items-center gap-1 px-1 py-1 text-left font-mono text-[11px] uppercase tracking-caps text-ink-faint hover:text-ink">
                                <span x-text="recolhidos.includes(sec.id) && !buscaAssuntos ? '▸' : '▾'"></span>
                                <span class="overflow-hidden text-ellipsis whitespace-nowrap" x-text="sec.nome"></span>
                                <span x-text="'(' + sec.assuntos.length + ')'"></span>
                                <span x-show="sec.assuntos.some(a => novidades[a.id] || a.status === 'aguardando_voce')" class="h-1.5 w-1.5 rounded-full bg-pergunta"></span>
                            </button>
                            <div class="space-y-1" x-show="!recolhidos.includes(sec.id) || buscaAssuntos">
                                <p x-show="!sec.assuntos.length" class="px-2 py-1 text-xs text-ink-faint">Vazio.</p>
                                <template x-for="a in sec.assuntos" :key="a.id">
                                    <div class="group relative">
                                        <button type="button" @click="abrirAssunto(a)" :aria-pressed="(chatAtual === a.id).toString()"
                                                class="w-full rounded-control border px-2.5 py-1.5 pr-14 text-left hover:bg-chip"
                                                :class="(chatAtual === a.id ? 'border-brand-mute bg-nav-active' : (a.status === 'aguardando_voce' ? 'border-pergunta-line bg-pergunta-tint' : 'border-line'))
                                                    + (novidades[a.id] ? ' animate-pulse ring-2 ring-brand' : '')">
                                            <span class="block overflow-hidden text-ellipsis whitespace-nowrap text-sm font-semibold text-ink" x-text="a.titulo"></span>
                                            <span class="mt-0.5 flex flex-wrap items-center gap-1 text-[11px]">
                                                <span x-show="a.status !== 'aberta'" class="rounded-badge border px-1 py-px" :class="corDoEstado(a.status)" x-text="estado(a.status)"></span>
                                                <span x-show="novidades[a.id]" class="rounded-badge px-1 font-semibold text-on-brand"
                                                      :class="novidades[a.id] === 'falhou' ? 'bg-crit' : (novidades[a.id] === 'aguardando' ? 'bg-pergunta' : 'bg-brand')"
                                                      x-text="{ terminou: '✓ respondeu', aguardando: '❓ espera você', falhou: '✕ falhou' }[novidades[a.id]]"></span>
                                                <span class="text-ink-faint" x-text="(a.projeto ? a.projeto + ' · ' : '') + haQuanto(a.ultima_atividade)"></span>
                                            </span>
                                        </button>
                                        <span class="absolute right-1.5 top-1.5 hidden gap-0.5 group-hover:flex group-focus-within:flex">
                                            <button type="button" @click="renomearAssunto(a)" class="rounded-control px-1 text-[11px] text-ink-mute hover:bg-chip hover:text-ink" :aria-label="'Renomear ' + a.titulo">✎</button>
                                            <button type="button" @click="arquivarAssunto(a)" class="rounded-control px-1 text-[11px] text-ink-mute hover:bg-chip hover:text-ink" :aria-label="'Arquivar ' + a.titulo">⌫</button>
                                        </span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </aside>

            {{-- Projeto aberto --}}
            <main class="relative flex min-h-0 min-w-0 flex-col overflow-hidden rounded-panel border border-line bg-panel">
                {{-- Visualizador de imagem da prévia: fixo na tela, fecha no ✕, no Esc ou clicando fora. --}}
                <div x-show="imagemAberta" x-cloak @keydown.escape.window="imagemAberta = null" @click.self="imagemAberta = null"
                     class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-3 bg-black/70 p-4" role="dialog" aria-modal="true" :aria-label="imagemAberta?.caminho">
                    <div class="flex w-full max-w-6xl items-center justify-between gap-2">
                        <span class="truncate text-sm text-ink" x-text="imagemAberta?.caminho.split('/').pop()"></span>
                        <span class="flex flex-none gap-2">
                            <a :href="imagemAberta?.url" target="_blank" rel="noopener noreferrer" class="rounded-control border border-line bg-panel px-3 py-1.5 text-xs font-semibold text-ink">Abrir em nova aba</a>
                            <button type="button" @click="imagemAberta = null" x-ref="fecharImagem" class="rounded-control bg-brand px-3 py-1.5 text-xs font-semibold text-on-brand">✕ Fechar</button>
                        </span>
                    </div>
                    <img :src="imagemAberta?.url" :alt="imagemAberta?.caminho" class="max-h-[85vh] max-w-full rounded-control border border-line bg-panel object-contain" @click.stop>
                </div>
                {{-- Trazer do VS Code (Chats Business): a última semana das conversas marcadas vira a memória do chat novo. --}}
                <div x-show="trazendo" x-cloak @keydown.escape.window="trazendo = null" @click.self="trazendo = null"
                     class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" role="dialog" aria-modal="true" aria-labelledby="central-trazer-titulo">
                    <form @submit.prevent="trazerDoVsCode()" class="flex max-h-[90vh] w-full max-w-2xl flex-col gap-3 rounded-panel border border-line bg-panel p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 id="central-trazer-titulo" class="font-display text-base font-semibold text-ink">Trazer do VS Code</h3>
                                <p class="text-xs text-ink-mute">Marque uma ou mais conversas do mesmo assunto. Uma IA no nível econômico lê a última semana delas e monta a memória do chat: onde fica cada coisa, em que etapa está, decisões e pendências. Nada é alterado no VS Code.</p>
                            </div>
                            <button type="button" @click="trazendo = null" class="flex-none rounded-control border border-line px-2 py-1 text-xs text-ink-mute">✕ Fechar</button>
                        </div>
                        <div class="min-h-0 flex-1 space-y-1 overflow-y-auto rounded-control border border-line p-1">
                            <p x-show="!conversasDoVsCode.length" class="p-2 text-xs text-ink-mute">Nenhuma conversa do Claude ou do Codex informada pela sua máquina.</p>
                            <template x-for="s in conversasDoVsCode" :key="s.agente + s.id">
                                <label class="flex cursor-pointer items-center gap-2 rounded-control px-2 py-1.5 hover:bg-chip" :class="trazendo?.ids.includes(s.id) ? 'bg-nav-active' : ''">
                                    <input type="checkbox" :checked="trazendo?.ids.includes(s.id)" @change="marcarConversa(s)" class="rounded border-line text-brand">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-semibold text-ink" x-text="s.titulo || '(sem nome) ' + s.id.slice(0, 8)"></span>
                                        <span class="block text-[11px] text-ink-faint" x-text="s.nome + ' · ' + (s.projeto || '~/projetos') + ' · ' + haQuanto(s.ultima_atividade) + (s.origem === 'mac_copia' ? ' · cópia do Mac' : '')"></span>
                                    </span>
                                    <span x-show="s.semeada_em" class="flex-none rounded-badge border border-line px-1 text-[11px] text-ink-mute" x-text="'já no chat #' + s.semeada_em"></span>
                                </label>
                            </template>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-3">
                            <label class="text-xs text-ink-mute sm:col-span-3">Nome do chat
                                <input type="text" maxlength="120" :value="trazendo?.titulo ?? ''" @input="trazendo.titulo = $event.target.value; trazendo.nomeTocado = true"
                                       class="mt-1 w-full rounded-control border-line bg-input py-1.5 text-sm text-ink focus:border-brand focus:ring-brand" placeholder="Ex.: Comercial VixCard">
                            </label>
                            <label class="text-xs text-ink-mute">Grupo
                                <select @change="trazendo.grupo_id = $event.target.value" class="mt-1 w-full rounded-control border-line bg-input py-1.5 text-xs text-ink">
                                    <option value="" :selected="!trazendo?.grupo_id">Sem grupo</option>
                                    <template x-for="g in grupos" :key="g.id"><option :value="g.id" :selected="String(trazendo?.grupo_id) === String(g.id)" x-text="g.nome"></option></template>
                                </select>
                            </label>
                            <label class="text-xs text-ink-mute sm:col-span-2">Projeto (opcional)
                                <select @change="trazendo.projeto = $event.target.value" class="mt-1 w-full rounded-control border-line bg-input py-1.5 text-xs text-ink">
                                    <option value="">Sem projeto (pastas de ~/projetos)</option>
                                    <template x-for="p in maquina.projetos" :key="p"><option :value="p" x-text="p"></option></template>
                                </select>
                            </label>
                        </div>
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="trazendo = null" class="rounded-control border border-line px-3 py-1.5 text-xs text-ink-mute">Cancelar</button>
                            <button type="submit" :disabled="!trazendo?.ids.length || !trazendo?.titulo.trim() || trazendo?.salvando"
                                    class="rounded-control bg-brand px-3 py-1.5 text-xs font-semibold text-on-brand disabled:opacity-50"
                                    x-text="trazendo?.salvando ? 'Criando…' : 'Criar chat e trazer a última semana'"></button>
                        </div>
                    </form>
                </div>
                {{-- Business sem assunto escolhido: nada de mostrar a conversa Geral por baixo. --}}
                <div x-show="tela === 'business' && !assuntoAtual" x-cloak class="absolute inset-0 z-[5] flex flex-col items-center justify-center gap-2 bg-panel p-6 text-center">
                    <span class="h-8 w-8 text-brand-text"><x-nav-icon name="chat" /></span>
                    <p class="font-semibold text-ink">Escolha um assunto à esquerda, ou crie um novo.</p>
                    <p class="max-w-sm text-sm text-ink-mute">Cada assunto é um chat contínuo: "Comercial VixCard", "Andamento de obras"… A IA que atender já recebe o histórico e a memória dele.</p>
                </div>
                <div class="flex flex-none flex-wrap items-center gap-x-3 gap-y-1 border-b border-line px-4 py-2">
                    <button type="button" @click="alternarProjetos()" :aria-expanded="(!projetosRecolhidos).toString()" :aria-controls="tela === 'chats' ? 'central-chats' : (tela === 'business' ? 'central-business' : 'central-projetos')"
                            class="hidden lg:flex items-center gap-2 rounded-control border border-line px-2 py-1 text-xs text-ink-mute hover:text-ink"
                            :title="projetosRecolhidos ? 'Mostrar lista' : 'Recolher lista'">
                        <span class="h-4 w-4"><x-nav-icon name="panel-left" /></span>
                        <span x-text="projetosRecolhidos ? 'Mostrar lista' : 'Recolher lista'"></span>
                    </button>
                    <button type="button" @click="menuProjetosMovel = true" class="rounded-control border border-line px-2 py-1 text-xs text-ink-mute lg:hidden" x-text="tela === 'chats' ? 'Chats' : (tela === 'business' ? 'Business' : 'Projetos')"></button>
                    <h2 class="flex-none font-display text-base font-semibold text-ink" x-text="assuntoAtual ? assuntoAtual.titulo : (atual || 'Geral')"></h2>
                    <span x-show="assuntoAtual" class="min-w-0 flex-1 truncate text-sm text-ink-dim"
                          x-text="'· ' + (grupos.find(g => g.id === assuntoAtual?.grupo_id)?.nome || 'Sem grupo') + (assuntoAtual?.projeto ? ' · projeto ' + assuntoAtual.projeto : '')"></span>
                    {{-- Projeto › tarefa › abas numa linha só: eram três, e a
                         resposta ficava com um terço da altura (12/10/2026). --}}
                    <span x-show="chatAtual && !assuntoAtual" class="min-w-0 flex-1 truncate text-sm text-ink-dim"
                          :title="chats.find(c => c.id === chatAtual)?.titulo || tarefaVista?.titulo || ''"
                          x-text="'› #' + chatAtual + ' ' + (chats.find(c => c.id === chatAtual)?.titulo || tarefaVista?.titulo || '')"></span>
                    <nav x-show="atual && !assuntoAtual" class="ml-auto flex flex-none gap-1 text-xs" aria-label="Seções do projeto">
                        <template x-for="[chave, rotulo] in [['conversa', 'Chat'], ['tarefas', 'Tarefas'], ['contexto', 'Contexto']]" :key="chave">
                            <button type="button" @click="sub = chave" class="rounded-ctl px-2 py-1 font-semibold"
                                    :class="sub === chave ? 'bg-nav-active text-ink' : 'text-ink-mute hover:text-ink'">
                                <span x-text="rotulo"></span>
                                <span x-show="chave === 'tarefas' && tarefasEmCurso.length" class="ml-0.5 text-ink-faint" x-text="'(' + tarefasEmCurso.length + ')'"></span>
                                <span x-show="chave === 'contexto' && tarefaVista?.duvida" class="ml-0.5 inline-block h-1.5 w-1.5 rounded-full bg-pergunta"></span>
                            </button>
                        </template>
                    </nav>
                    <template x-if="atual">
                        <p x-show="detalhesChat" class="flex w-full min-w-0 flex-wrap gap-x-3 text-xs text-ink-mute">
                            <span x-show="projetoAtual?.git?.branch" x-text="projetoAtual?.git?.branch"></span>
                            <span x-show="projetoAtual?.git?.git" :class="projetoAtual?.git?.pendentes ? 'text-warn' : ''" x-text="(projetoAtual?.git?.pendentes || 0) + ' pendentes'"></span>
                            <span x-show="projetoAtual?.git?.ultimo_commit" :title="projetoAtual?.git?.ultimo_commit_msg" x-text="'commit ' + haQuanto(projetoAtual?.git?.ultimo_commit)"></span>
                            <span x-show="projetoAtual?.git?.fase" x-text="projetoAtual?.git?.fase"></span>
                            <span x-show="projetoAtual?.git?.sessoes?.length" class="text-exame" x-text="'sessão aberta: ' + (projetoAtual?.git?.sessoes || []).join(', ')"></span>
                        </p>
                    </template>
                    <template x-if="atual">
                        <div x-show="detalhesChat" class="flex items-center gap-2 text-xs">
                            <label class="sr-only" for="central-grupo">Grupo do projeto</label>
                            <select id="central-grupo" @change="moverParaGrupo(atual, $event.target.value || null)"
                                    class="rounded-control border-line bg-input py-1 text-xs text-ink focus:border-brand focus:ring-brand">
                                <option value="" :selected="!projetoAtual?.grupo_id">Sem grupo</option>
                                <template x-for="g in grupos" :key="g.id"><option :value="g.id" x-text="g.nome" :selected="projetoAtual?.grupo_id === g.id"></option></template>
                                <option value="novo">+ Novo grupo…</option>
                            </select>
                            <button type="button" @click="fixar(atual)" class="font-semibold text-brand-text" x-text="projetoAtual?.fixado ? 'Desafixar' : 'Fixar'"></button>
                        </div>
                    </template>
                </div>


                {{-- Conversa. Sem chat escolhido, é a Geral — com a caixa de
                     mensagem pronta, em vez de uma tela vazia pedindo um clique. --}}
                <div class="flex min-h-0 flex-1 flex-col" x-show="!atual || sub === 'conversa'">
                    <div class="flex flex-none items-center justify-between px-4 pt-2 text-xs text-ink-faint" x-show="verTarefa && detalhesChat">
                        <span x-text="'Só a tarefa #' + verTarefa"></span>
                        <button type="button" @click="selecionarTarefa(null)" class="font-semibold text-brand-text">Ver o projeto todo</button>
                    </div>
                    <div class="flex flex-none flex-wrap items-center justify-between gap-2 px-4 py-2 text-xs" x-show="atual && detalhesChat">
                        @if ($podeAcionar)
                            <button type="button" @click="salvarPonto(tarefaVista || tarefas.find(t => t.id === aba.tarefa))"
                                    :disabled="!(tarefaVista || tarefas.find(t => t.id === aba.tarefa)) || ocupado"
                                    class="font-semibold text-brand-text disabled:opacity-50">💾 Salvar ponto</button>
                        @endif
                        <div class="flex flex-wrap gap-2">
                            <template x-for="t in tarefas.filter(t => t.ponto?.mensagem_id && (!verTarefa || t.id === verTarefa))" :key="t.id">
                                <button type="button" @click="historicosAbertos[t.id] = !historicosAbertos[t.id]"
                                        class="font-semibold text-brand-text"
                                        x-text="(historicosAbertos[t.id] ? 'Ocultar histórico' : 'Ver histórico salvo') + ' · tarefa #' + t.id"></button>
                            </template>
                        </div>
                    </div>
                    <div x-ref="feed" class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-3" role="log" aria-label="Histórico da conversa" aria-live="polite" aria-relevant="additions">
                        <div class="mb-3 text-center" x-show="aba.haAnteriores">
                            <button type="button" @click="anteriores()" :disabled="ocupado || bloqueado" class="text-xs font-semibold text-brand-text disabled:opacity-50">Carregar mensagens anteriores</button>
                        </div>
                        <p x-show="!aba.carregado" class="py-6 text-center text-sm text-ink-faint">Carregando…</p>
                        <div x-show="aba.carregado && !mensagensVisiveis.length && !bloqueado" class="flex h-full flex-col items-center justify-center gap-2 text-center">
                            <h3 class="font-display text-base font-semibold text-ink" x-text="assuntoAtual ? 'Nada ainda em ' + assuntoAtual.titulo : (atual ? 'Nada ainda em ' + atual : 'Conversa geral')"></h3>
                            <p class="max-w-sm text-sm text-ink-mute" x-text="assuntoAtual ? 'Escreva abaixo. Toda mensagem vai a uma IA, que recebe o histórico e a memória deste assunto e trabalha nas pastas de ' + (assuntoAtual.projeto || '~/projetos') + '.' : atual ? 'Escreva o pedido abaixo. Em automático, a Central escolhe a IA e o nível de custo, e mostra o porquê.' : 'Perguntas e pedidos de resumo, relatório ou agenda vão a uma IA — ou a nenhuma, quando os dados bastam. O resto fica anotado.'"></p>
                            @if ($podeAcionar)
                                {{-- Os pedidos de todo dia, no nível mais barato que resolve. --}}
                                <div x-show="!atual && !assuntoAtual" class="mt-2 flex flex-wrap justify-center gap-2 text-xs">
                                    <button type="button" @click="atalho('Quais são meus compromissos de hoje?')" class="rounded-badge border border-line px-3 py-1.5 text-ink-dim hover:bg-chip">📅 Agenda de hoje <span class="text-ink-faint">· sem IA</span></button>
                                    <button type="button" @click="atalho('Quais são meus compromissos de amanhã?')" class="rounded-badge border border-line px-3 py-1.5 text-ink-dim hover:bg-chip">📅 Amanhã <span class="text-ink-faint">· sem IA</span></button>
                                    <button type="button" @click="atalho('Faça um resumo da minha agenda desta semana, com o que exige preparo.')" class="rounded-badge border border-line px-3 py-1.5 text-ink-dim hover:bg-chip">🗓 Resumo da semana <span class="text-ink-faint">· econômico</span></button>
                                </div>
                            @endif
                        </div>
                        <div class="space-y-3">
                            <template x-for="m in mensagensVisiveis" :key="m.id">
                                {{-- Aviso da Central (escalada, fila, confirmação) é uma linha
                                     discreta, não um balão: era barulho no meio da conversa. --}}
                                <article class="min-w-0 rounded-control border"
                                         :class="m.autor_tipo === 'usuario' ? 'border-line bg-input px-4 py-2.5' : (m.autor_tipo === 'sistema' ? 'border-transparent px-2 py-0.5' : 'border-line bg-canvas px-4 py-2.5')" data-mensagem>
                                    <div class="mb-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs" x-show="m.autor_tipo !== 'sistema'">
                                        <span class="font-semibold text-ink" x-text="autor(m)"></span>
                                        <span class="text-ink-faint" x-show="m.origem !== 'agente'" x-text="canal(m.origem)"></span>
                                        <button type="button" class="text-ink-faint hover:text-brand-text" x-show="m.tarefa_id && !verTarefa" @click="selecionarTarefa(m.tarefa_id)" x-text="'tarefa #' + m.tarefa_id"></button>
                                        <time class="text-ink-faint" :datetime="m.criada_em" x-text="data(m.criada_em)"></time>
                                    </div>
                                    <p class="mb-1 text-xs" x-show="m.pedido">
                                        <span class="font-semibold" :class="corDaExecucao(m.pedido?.status)"
                                              x-text="m.pedido && ('Para ' + m.pedido.agente + ' · ' + m.pedido.modo + ' — ' + execucao(m.pedido.status))"></span>
                                        <span class="text-ink-faint" x-show="m.pedido?.handoff_de" x-text="' · assumiu de ' + m.pedido?.handoff_de"></span>
                                        <span class="text-ink-faint" x-text="m.pedido?.motivo ? ' · ' + m.pedido.motivo : ''"></span>
                                    </p>
                                    <template x-if="m.visual?.relatorio">
                                        <section class="mb-3" aria-label="Resumo financeiro">
                                            <h3 class="font-semibold text-ink" x-text="m.visual.relatorio.titulo"></h3>
                                            <p class="text-xs text-ink-muted" x-text="m.visual.relatorio.periodo"></p>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2 mt-3">
                                                <template x-for="(k, i) in m.visual.relatorio.metricas" :key="i">
                                                    <div class="rounded-lg border border-line p-3">
                                                        <p class="text-xs text-ink-muted" x-text="k.rotulo"></p>
                                                        <p class="text-xl font-semibold text-ink" x-text="new Intl.NumberFormat('pt-BR', {style:'currency',currency:'BRL'}).format(k.centavos / 100)"></p>
                                                        <p class="text-xs text-ink-muted" x-text="k.criterio"></p>
                                                    </div>
                                                </template>
                                            </div>
                                            <template x-if="m.visual.relatorio.contagens"><div class="flex flex-wrap gap-4 mt-3"><template x-for="(p,j) in m.visual.relatorio.contagens" :key="j"><p class="text-xs"><strong class="text-lg" x-text="p.quantidade"></strong> <span x-text="p.rotulo"></span></p></template></div></template>
                                            <template x-if="m.visual.relatorio.alertas"><ul class="mt-3 text-sm"><template x-for="(v,j) in m.visual.relatorio.alertas" :key="j"><li x-text="'Atenção: ' + v"></li></template></ul></template>
                                            <template x-if="m.visual.relatorio.acoes"><ul class="mt-3 text-sm"><template x-for="(v,j) in m.visual.relatorio.acoes" :key="j"><li x-text="'Próxima ação: ' + v"></li></template></ul></template>
                                            <template x-if="m.visual.relatorio.grafico">
                                                <figure class="mt-4 rounded-lg border border-line p-3">
                                                    <figcaption class="text-sm font-semibold mb-3" x-text="m.visual.relatorio.grafico.titulo"></figcaption>
                                                    <template x-if="m.visual.relatorio.grafico.tipo === 'linha'"><div><svg viewBox="0 0 400 140" class="w-full h-40" role="img" :aria-label="m.visual.relatorio.grafico.titulo"><polyline fill="none" stroke="currentColor" stroke-width="2" :points="m.visual.relatorio.grafico.pontos.map((p,j,a)=> (10+j*380/(a.length-1)) + ',' + (130-(p.centavos-Math.min(0,...a.map(v=>v.centavos)))*120/Math.max(1,Math.max(0,...a.map(v=>v.centavos))-Math.min(0,...a.map(v=>v.centavos))))).join(' ')"></polyline></svg><ul class="flex flex-wrap gap-3 text-xs"><template x-for="(p,j) in m.visual.relatorio.grafico.pontos" :key="j"><li><span x-text="p.rotulo"></span>: <span x-text="new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(p.centavos/100)"></span></li></template></ul></div></template>
                                                    <template x-for="(p, j) in m.visual.relatorio.grafico.pontos" :key="j">
                                                        <div class="mb-2" x-show="m.visual.relatorio.grafico.tipo === 'barras'">
                                                            <div class="flex justify-between gap-3 text-xs"><span x-text="p.rotulo"></span><span x-text="new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(p.centavos/100)"></span></div>
                                                            <div class="h-3 rounded bg-panel mt-1"><div class="h-3 rounded bg-brand" :style="'width:' + (100 * Math.abs(p.centavos) / Math.max(1,...m.visual.relatorio.grafico.pontos.map(v=>Math.abs(v.centavos)))) + '%'" :class="p.centavos < 0 ? 'opacity-50' : ''"></div></div>
                                                        </div>
                                                    </template>
                                                </figure>
                                            </template>
                                            <template x-if="m.visual.relatorio.tabela">
                                                <details class="mt-3 text-sm"><summary>Ver contas detalhadas</summary><x-tabela min="480px" class="mt-2 text-xs"><thead><tr><th class="text-left">Data</th><th class="text-left">Descrição</th><th class="text-left">Estado</th><th class="text-right">Valor</th></tr></thead><tbody><template x-for="(p,j) in m.visual.relatorio.tabela" :key="j"><tr><td class="py-2" x-text="/^\d{4}-\d{2}-\d{2}$/.test(p.data) ? p.data.slice(8,10)+'/'+p.data.slice(5,7)+'/'+p.data.slice(0,4) : p.data"></td><td x-text="p.descricao"></td><td x-text="p.estado || 'Não informado'"></td><td class="text-right" x-text="new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(p.centavos/100)"></td></tr></template></tbody><tfoot><tr class="font-semibold"><td colspan="3" class="py-2">Subtotal das contas listadas</td><td class="text-right" x-text="new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(m.visual.relatorio.tabela.reduce((s,p)=>s+p.centavos,0)/100)"></td></tr></tfoot></x-tabela></details>
                                            </template>
                                            <details class="mt-2 text-xs text-ink-muted"><summary>Fonte da consulta</summary><p x-text="m.visual.relatorio.fonte"></p></details>
                                        </section>
                                    </template>
                                    <div class="central-resposta text-sm leading-relaxed text-ink" x-show="m.visual" x-html="m.visual?.resumo_html || ''"></div>
                                    <template x-if="m.visual?.longo"><details class="mt-3 text-sm"><summary class="cursor-pointer text-brand-text">Ler resposta completa</summary><div class="central-resposta mt-2" x-html="m.visual.html"></div></details></template>
                                    <p class="whitespace-pre-wrap break-words" x-show="!m.visual"
                                       :class="m.autor_tipo === 'sistema' ? 'text-xs text-ink-mute' : 'text-sm leading-relaxed text-ink'"
                                       x-text="m.autor_tipo === 'sistema' ? 'Central · ' + data(m.criada_em) + ' — ' + m.conteudo : m.conteudo"></p>
                                    @if ($podeAcionar)
                                        {{-- Ações discretas: responder é escrever na caixa, como no
                                             VS Code. Aqui ficam os atalhos para delegar ou implementar. --}}
                                        <div x-show="m.autor_tipo === 'agente' && m.tarefa_id" class="mt-1 text-xs">
                                            <button type="button" @click="acoesAbertas[m.id] = !acoesAbertas[m.id]" class="text-ink-faint hover:text-ink" :aria-expanded="(!!acoesAbertas[m.id]).toString()">⋯</button>
                                            <span x-show="acoesAbertas[m.id]" class="ml-2 inline-flex flex-wrap items-center gap-x-3 gap-y-1">
                                                <button type="button" x-show="atual" @click="agir(m, 'aprovar')" class="font-semibold text-good">Implementar o que foi proposto</button>
                                                <span class="text-ink-faint" x-show="delegaveis(m).length">Passar para:</span>
                                                <template x-for="[chave, nome] in delegaveis(m)" :key="chave">
                                                    <button type="button" @click="agir(m, 'delegar', chave)" class="font-semibold text-ink-dim hover:text-ink" x-text="nome"></button>
                                                </template>
                                            </span>
                                        </div>
                                    @endif
                                    {{-- Prévia visual do agente (FR-034): imagem em miniatura, PDF e página
                                         para abrir no navegador — no Mac ou no Windows. --}}
                                    <div x-show="(m.previas || []).length" class="mt-2 flex flex-wrap items-end gap-2">
                                        <template x-for="p in m.previas || []" :key="p.url">
                                            {{-- Imagem abre aqui mesmo, num visualizador com Fechar (dono, 13/10/2026:
                                                 "sobe em tela cheia e não tem botão de sair ou voltar"). --}}
                                            <a :href="p.url" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-text" :title="p.caminho"
                                               @click="if (p.tipo === 'imagem') { $event.preventDefault(); imagemAberta = p; }">
                                                <img x-show="p.tipo === 'imagem'" :src="p.url" :alt="p.caminho" loading="lazy" class="h-24 max-w-[10rem] rounded-control border border-line object-cover">
                                                <span x-show="p.tipo === 'pdf'" class="rounded-badge border border-line px-2 py-1" x-text="'📄 ' + p.caminho.split('/').pop()"></span>
                                                <span x-show="p.tipo === 'pagina'" class="rounded-control bg-brand px-3 py-1.5 text-on-brand" x-text="'🔗 Abrir prévia' + (p.caminho.endsWith('index.html') ? '' : ' · ' + p.caminho.split('/').pop())"></span>
                                            </a>
                                        </template>
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <template x-for="a in m.anexos || []" :key="a.id">
                                            <a :href="a.url" class="text-xs font-semibold text-brand-text" x-text="'📎 ' + a.nome" download></a>
                                        </template>
                                    </div>
                                    <p class="mt-1 text-xs text-ink-faint" x-show="m.entrega && detalhesChat" x-text="entrega(m.entrega)"></p>
                                </article>
                            </template>
                            {{-- A pergunta do agente, com o nome de quem pergunta, no fim
                                 da conversa. Responder é escrever na caixa abaixo. --}}
                            <article x-show="perguntaPendente && !aba.pendente" x-cloak class="rounded-control border border-pergunta-line bg-pergunta-tint px-4 py-2.5" data-pergunta>
                                <p class="mb-1 text-xs font-semibold text-pergunta" x-text="'❓ ' + (perguntaPendente?.agente || 'O agente') + ' pergunta'"></p>
                                <p class="whitespace-pre-wrap break-words text-sm text-ink" x-text="perguntaPendente?.duvida"></p>
                                <div class="mt-2 flex flex-wrap gap-2" x-show="(perguntaPendente?.opcoes_duvida || []).length">
                                    <template x-for="(o, i) in perguntaPendente?.opcoes_duvida || []" :key="i">
                                        <button type="button" @click="responderCom(o)" class="rounded-badge border border-line bg-panel px-3 py-1 text-xs text-ink hover:bg-chip" x-text="o"></button>
                                    </template>
                                </div>
                                <p class="mt-1 text-xs text-ink-faint">Responda na caixa abaixo — ela segue para quem perguntou.</p>
                            </article>
                            <article x-show="aba.pendente" x-cloak class="rounded-control border border-line bg-input px-4 py-2.5" data-pendente>
                                <p class="mb-1 text-xs font-semibold text-ink-mute">Você · aguardando confirmação</p>
                                <p class="whitespace-pre-wrap break-words text-sm text-ink" x-text="aba.pendente?.conteudo"></p>
                            </article>
                        </div>
                    </div>

                    <div class="flex-none space-y-1 border-t border-line px-4 py-2 text-xs" role="status" aria-live="polite" x-show="pedidosAtivos.length || aba.recibo">
                        <p x-show="aba.recibo && !pedidosAtivos.length" x-text="aba.recibo" class="text-ink-mute"></p>
                        <template x-for="a in pedidosAtivos" :key="a.id">
                            <p class="flex items-center gap-2 text-brand-text"><span class="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-brand"></span><span x-text="andamento(a)"></span></p>
                        </template>
                    </div>
                    <div x-show="atendimento.disponivel && atendimento.ativo === 'telegram'" x-cloak class="flex-none border-t border-line px-4 py-3 text-sm text-ink-mute">
                        A conversa está sendo conduzida pelo Telegram. O histórico continua disponível aqui.
                        <button type="button" @click="transferirCanal('web')" :disabled="transferindoCanal" class="ml-2 font-semibold text-brand-text">Retomar no AlfaMatriz</button>
                    </div>
                    {{-- Compositor, sempre visível --}}
                    <form x-show="!atendimento.disponivel || atendimento.ativo === 'web'" @submit.prevent="enviar()" @dragover.prevent @drop.prevent="escolherArquivos($event.dataTransfer.files)" class="flex-none space-y-2 border-t border-line p-3" aria-label="Enviar mensagem">
                        @if ($podeAcionar)
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <label class="sr-only" for="central-tarefa">Tarefa</label>
                                <select id="central-tarefa" x-model="aba.tarefa" x-show="atual && detalhesChat" :disabled="!!aba.pendente || !!chatAtual"
                                        class="max-w-[16rem] rounded-control border-line bg-input py-1 text-xs text-ink focus:border-brand focus:ring-brand">
                                    <option value="nova" :selected="aba.tarefa === 'nova'">Nova tarefa</option>
                                    {{-- :selected em cada opção: as tarefas chegam DEPOIS do seletor, e só o x-model perdia a escolha --}}
                                    <template x-for="t in tarefasEmCurso" :key="t.id"><option :value="t.id" :selected="String(aba.tarefa) === String(t.id)" x-text="'#' + t.id + ' ' + t.titulo"></option></template>
                                </select>
                                <label class="sr-only" for="central-agente">Agente</label>
                                <select id="central-agente" x-model="aba.agente" :disabled="!!aba.pendente"
                                        class="rounded-control border-line bg-input py-1 text-xs text-ink focus:border-brand focus:ring-brand">
                                    <option value="auto">IA automática</option>
                                    @foreach ($agentes as $chave => $nome)
                                        <option value="{{ $chave }}" :disabled="!maquina.agentes.includes('{{ $chave }}')">{{ $nome }}</option>
                                    @endforeach
                                    <option value="nota">Só anotar (sem agente)</option>
                                </select>
                                <label class="sr-only" for="central-modo">Modo</label>
                                <label class="sr-only" for="central-nivel">Nível de custo</label>
                                <select id="central-nivel" x-model="aba.nivel" :disabled="!!aba.pendente || aba.agente === 'nota'"
                                        title="Automático: a Central usa o nível mais barato que resolve. Sem IA só vale para a agenda."
                                        class="rounded-control border-line bg-input py-1 text-xs text-ink focus:border-brand focus:ring-brand">
                                    <option value="auto">Custo automático</option>
                                    <option value="sem_ia">Sem IA (só dados)</option>
                                    <option value="leve">Econômico</option>
                                    <option value="medio">Médio</option>
                                    <option value="forte">Máximo</option>
                                </select>
                                <select id="central-modo" x-model="aba.modo" x-show="atual && detalhesChat" :disabled="!!aba.pendente || aba.agente === 'nota'"
                                        class="rounded-control border-line bg-input py-1 text-xs text-ink focus:border-brand focus:ring-brand">
                                    <option value="auto">Modo automático</option>
                                    @foreach ($modos as $chave => $nome)
                                        <option value="{{ $chave }}">{{ $nome }}</option>
                                    @endforeach
                                </select>
                                <button type="button" x-show="atual" @click="detalhesChat = !detalhesChat" class="text-ink-mute hover:text-ink" x-text="detalhesChat ? 'Menos opções' : 'Mais opções'"></button>
                                <span class="text-warn" x-show="aba.agente !== 'auto' && aba.agente !== 'nota' && consumo.limites?.esgotados?.[aba.agente]"
                                      x-text="'⚠ ' + (consumo.limites?.esgotados?.[aba.agente] || '')"></span>
                            </div>
                        @endif
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <label class="cursor-pointer font-semibold text-brand-text" title="Até 20 MB por arquivo · cole ou arraste uma captura">📎 Anexar
                                <input type="file" class="sr-only" multiple accept="image/jpeg,image/png,image/webp,application/pdf,video/mp4,video/webm,video/quicktime"
                                       :disabled="!!aba.pendente || bloqueado || !podeEnviar" @change="escolherArquivos($event.target.files); $event.target.value = ''">
                            </label>
                            {{-- Para onde vai a mensagem: chat da tela ou novo (o #19 nasceu sem ninguém ver). --}}
                            <span class="min-w-0 truncate text-ink-faint" :class="destinoDaMensagem ? '' : 'font-semibold text-warn'" x-text="rotuloDoDestino" :title="rotuloDoDestino"></span>

                            {{-- Imagem colada ou anexada aparece em miniatura (como no VS Code);
                                 PDF e vídeo, pelo nome. O × tira antes de enviar. --}}
                            <template x-for="(a, i) in aba.arquivos" :key="a.id">
                                <span class="relative inline-flex items-center">
                                    <img x-show="a.miniatura" :src="a.miniatura" :alt="a.file.name" :title="a.file.name"
                                         class="h-14 w-14 rounded-control border border-line object-cover">
                                    <span x-show="!a.miniatura" class="rounded-badge border border-line px-2 py-1 text-ink-dim" x-text="a.file.name"></span>
                                    <button type="button" :disabled="!!aba.pendente" @click="removerArquivo(i)" :aria-label="'Remover ' + a.file.name"
                                            class="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full border border-line bg-panel text-[11px] leading-none text-ink-mute hover:text-ink disabled:hidden">×</button>
                                </span>
                            </template>
                            <span role="status" x-text="aba.progresso" class="text-brand-text"></span>
                        </div>
                        <div class="flex items-end gap-2">
                            <label for="central-rascunho" class="sr-only">Sua mensagem</label>
                            {{-- Cresce com o texto até 40% da tela; depois rola por dentro. --}}
                            <textarea @paste="colar($event)" id="central-rascunho" x-model="aba.rascunho" maxlength="{{ config('central_ia.max_texto') }}" rows="2"
                                      @input="crescer($el)" x-effect="aba.rascunho; $nextTick(() => crescer($el))"
                                      :readonly="!!aba.pendente" :disabled="bloqueado || !podeEnviar"
                                      @keydown.enter="if (!$event.shiftKey && !$event.isComposing && !window.matchMedia('(pointer: coarse)').matches) { $event.preventDefault(); enviar(); }"
                                      :placeholder="perguntaPendente ? 'Responda a ' + (perguntaPendente.agente || 'quem perguntou') + '… (Enter envia)' : (assuntoAtual ? 'Fale sobre ' + assuntoAtual.titulo + '… (Enter envia, Shift+Enter quebra linha)' : (atual ? 'Peça algo para ' + atual + '… (Enter envia, Shift+Enter quebra linha)' : 'Pergunte ou anote… (Enter envia)'))"
                                      class="min-w-0 flex-1 resize-none overflow-y-auto rounded-control border-line bg-input text-sm text-ink placeholder-ink-faint focus:border-brand focus:ring-brand disabled:opacity-50"></textarea>
                            <button type="submit" :disabled="aba.enviando || bloqueado || !podeEnviar || (!aba.pendente && !aba.rascunho.trim() && !aba.arquivos.length)"
                                    class="flex-none rounded-control bg-brand px-4 py-2 text-sm font-semibold text-on-brand transition hover:bg-brand-bright disabled:opacity-50"
                                    x-text="aba.enviando ? 'Enviando…' : (aba.pendente ? 'Reenviar' : 'Enviar')"></button>
                        </div>
                    </form>
                </div>

                {{-- Tarefas --}}
                <div class="min-h-0 flex-1 overflow-y-auto p-3" x-show="atual && sub === 'tarefas'" x-cloak>
                    {{-- Chat novo já no projeto: começa com o contexto dele (CLAUDE.md, POPs, chats recentes). --}}
                    <div class="mb-3 flex items-center gap-2">
                        <button type="button" @click="novoChat(atual)" class="rounded-control bg-brand px-3 py-1.5 text-xs font-semibold text-on-brand" x-text="'+ Novo chat em ' + atual"></button>
                        <span class="text-xs text-ink-faint">O agente começa sabendo do projeto e dos chats recentes dele.</span>
                    </div>
                    <p x-show="!tarefas.length" class="px-1 py-2 text-sm text-ink-faint">Nenhuma tarefa ainda. Um pedido na Conversa abre a primeira.</p>
                    <ul class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                        <template x-for="t in tarefas" :key="t.id">
                            <li class="flex flex-col rounded-control border border-line hover:bg-chip">
                                <button type="button" @click="selecionarTarefa(t.id, true)" class="w-full flex-1 px-3 py-2 text-left">
                                    <span class="flex items-center gap-1.5 text-[11px]">
                                        <span class="rounded-badge border px-1.5 py-0.5" :class="corDoEstado(t.status)" x-text="estado(t.status)"></span>
                                        <span class="text-ink-faint" x-text="'#' + t.id"></span>
                                        <span x-show="t.duvida" class="text-pergunta">● dúvida</span>
                                    </span>
                                    <span class="mt-1 block overflow-hidden text-ellipsis whitespace-nowrap text-sm text-ink" x-text="t.titulo"></span>
                                    <span class="mt-0.5 block overflow-hidden text-ellipsis whitespace-nowrap text-xs text-ink-faint"
                                          x-text="(t.agente || 'sem agente') + (t.branch ? ' · ' + t.branch : '') + ' · ' + haQuanto(t.ultima_atividade)"></span>
                                </button>
                                <button type="button" x-show="t.status === 'arquivada'" @click="mudarTarefa(t.id, 'aberta')" class="border-t border-line px-3 py-1.5 text-left text-xs font-semibold text-brand-text">Reabrir chat e liberar pedidos suspensos</button>
                            </li>
                        </template>
                    </ul>
                </div>

                {{-- Contexto (dossiê) --}}
                <div class="min-h-0 flex-1 overflow-y-auto p-4 text-sm" x-show="atual && sub === 'contexto'" x-cloak>
                    <p x-show="!tarefaVista" class="text-ink-faint">Escolha uma tarefa (aba Tarefas, ou o "tarefa #" de uma mensagem) para ver o contexto dela.</p>
                    <template x-if="tarefaVista">
                        <div class="space-y-3">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-semibold text-ink" x-text="'#' + tarefaVista.id + ' ' + tarefaVista.titulo"></p>
                                    <p class="text-xs text-ink-mute" x-text="'Agente atual: ' + (tarefaVista.agente || '—') + (tarefaVista.agentes.length > 1 ? ' · já passaram: ' + tarefaVista.agentes.join(', ') : '')"></p>
                                    <p class="text-xs text-ink-faint" x-show="tarefaVista.ultima_execucao?.motivo" x-text="tarefaVista.ultima_execucao?.motivo"></p>
                                    <p class="text-xs text-ink-faint" x-show="tarefaVista.ponto" x-text="'💾 Ponto salvo ' + haQuanto(tarefaVista.ponto?.em) + ' por ' + tarefaVista.ponto?.agente + ' — a conversa segue enxuta a partir dele'"></p>
                                </div>
                                <div class="flex flex-wrap gap-3 text-xs">
                                    <button type="button" @click="salvarPonto(tarefaVista)" class="font-semibold text-brand-text"
                                            title="O agente resume o estado da tarefa; os próximos pedidos começam uma conversa nova, enxuta, a partir do resumo">💾 Salvar ponto</button>
                                    <button type="button" x-show="tarefaVista.status !== 'concluida'" @click="mudarTarefa(tarefaVista.id, 'concluida')" class="font-semibold text-good">Concluir</button>
                                    <button type="button" x-show="!['pausada','concluida'].includes(tarefaVista.status)" @click="mudarTarefa(tarefaVista.id, 'pausada')" class="font-semibold text-ink-mute">Pausar</button>
                                    <button type="button" x-show="['pausada','concluida','falhou'].includes(tarefaVista.status)" @click="mudarTarefa(tarefaVista.id, 'aberta')" class="font-semibold text-ink-mute">Reabrir</button>
                                </div>
                            </div>
                            <p x-show="tarefaVista.duvida" class="rounded-control border border-pergunta-line bg-pergunta-tint px-3 py-2 text-ink">
                                <span class="font-semibold text-pergunta">O agente precisa de você: </span><span x-text="tarefaVista.duvida"></span>
                            </p>
                            <div class="grid gap-3 text-xs md:grid-cols-2">
                                <div class="space-y-1">
                                    <p class="font-mono uppercase tracking-caps text-ink-faint">Git</p>
                                    <p class="text-ink-dim" x-text="tarefaVista.branch ? 'Branch ' + tarefaVista.branch : (tarefaVista.worktree ? 'Na pasta da sessão do VS Code' : 'Na pasta do projeto')"></p>
                                    <p class="break-all text-ink-faint" x-show="tarefaVista.worktree" x-text="tarefaVista.worktree"></p>
                                    <p class="text-ink-faint" x-show="tarefaVista.commit_base || tarefaVista.git?.head" x-text="(tarefaVista.commit_base ? 'Base ' + tarefaVista.commit_base.slice(0, 10) + ' · ' : '') + (tarefaVista.git?.head ? 'HEAD ' + tarefaVista.git.head : '')"></p>
                                    <p class="text-ink-dim" x-show="tarefaVista.git?.arquivos?.length" x-text="(tarefaVista.git?.arquivos || []).length + ' arquivo(s) alterado(s): ' + (tarefaVista.git?.arquivos || []).slice(0, 10).join(', ')"></p>
                                </div>
                                <div class="space-y-1">
                                    <p class="font-mono uppercase tracking-caps text-ink-faint">Testes</p>
                                    <p x-show="!tarefaVista.testes.length" class="text-ink-faint">Nenhum registrado.</p>
                                    <template x-for="t in tarefaVista.testes" :key="t.em + t.comando">
                                        <p class="text-ink-dim"><span class="font-mono" x-text="t.comando"></span> → <span :class="/pass|ok|verde/i.test(t.resultado) ? 'text-good' : (/falh|fail|erro/i.test(t.resultado) ? 'text-crit' : 'text-ink-mute')" x-text="t.resultado"></span></p>
                                    </template>
                                </div>
                                <div class="space-y-1" x-show="tarefaVista.resumo">
                                    <p class="font-mono uppercase tracking-caps text-ink-faint">Último resultado</p>
                                    <p class="text-ink-dim" x-text="tarefaVista.resumo"></p>
                                </div>
                                <div class="space-y-1" x-show="tarefaVista.proximos_passos.length">
                                    <p class="font-mono uppercase tracking-caps text-ink-faint">Próximos passos</p>
                                    <template x-for="x in tarefaVista.proximos_passos" :key="x"><p class="text-ink-dim" x-text="'• ' + x"></p></template>
                                </div>
                                <div class="space-y-1" x-show="tarefaVista.decisoes.length">
                                    <p class="font-mono uppercase tracking-caps text-ink-faint">Decisões</p>
                                    <template x-for="x in tarefaVista.decisoes" :key="x"><p class="text-ink-dim" x-text="'• ' + x"></p></template>
                                </div>
                                <div class="space-y-1" x-show="tarefaVista.pendencias.length || tarefaVista.riscos.length">
                                    <p class="font-mono uppercase tracking-caps text-ink-faint">Pendências e riscos</p>
                                    <template x-for="x in tarefaVista.pendencias" :key="'p' + x"><p class="text-ink-dim" x-text="'• ' + x"></p></template>
                                    <template x-for="x in tarefaVista.riscos" :key="'r' + x"><p class="text-warn" x-text="'⚠ ' + x"></p></template>
                                </div>
                                {{-- O rastro de cada execução: com acesso total, quem decide é a
                                     IA, e aqui se confere o que ela viu e o que alterou. --}}
                                <div class="space-y-2 md:col-span-2" x-show="(tarefaVista.execucoes || []).length">
                                    <p class="font-mono uppercase tracking-caps text-ink-faint">Quem viu o quê e quem alterou o quê</p>
                                    <template x-for="e in tarefaVista.execucoes || []" :key="e.id">
                                        <details class="rounded-control border border-line px-3 py-2">
                                            <summary class="cursor-pointer text-ink-dim">
                                                <span class="font-semibold text-ink" x-text="'#' + e.id + ' ' + e.agente"></span>
                                                <span x-text="' · ' + e.modo + (e.nivel ? ' · ' + e.nivel : '') + (e.modelo ? ' (' + e.modelo + ')' : '') + (e.acesso ? ' · acesso ' + e.acesso : '') + ' · ' + execucao(e.status) + ' · ' + haQuanto(e.em)"></span>
                                                <span x-show="e.arquivos.length || e.commits.length" class="ml-1 font-semibold" :class="e.modo === 'implementar' ? 'text-brand-text' : 'text-warn'"
                                                      x-text="'· alterou ' + e.arquivos.length + ' arquivo(s)' + (e.commits.length ? ', ' + e.commits.length + ' commit(s)' : '')"></span>
                                                <span x-show="!e.arquivos.length && !e.commits.length && e.status === 'concluida'" class="ml-1 text-ink-faint">· não alterou nada</span>
                                            </summary>
                                            <div class="mt-2 space-y-1">
                                                <p x-show="e.arquivos.length" class="break-all text-ink-dim" x-text="'Alterados pelo agente: ' + e.arquivos.join(', ')"></p>
                                                <p x-show="(e.na_pasta || []).length" class="break-all text-ink-faint" x-text="'Mudaram na pasta durante a execução, sem edição do agente (outra sessão aberta ou comando de shell): ' + (e.na_pasta || []).join(', ')"></p>
                                                <template x-for="c in e.commits" :key="c"><p class="font-mono text-ink-dim" x-text="'commit ' + c"></p></template>
                                                <p x-show="e.informado === false" class="text-ink-faint">O Copilot não informa as ferramentas que usou; só o que mudou no git aparece.</p>
                                                <p x-show="e.total_acessos" class="text-ink-faint" x-text="'Consultou e executou (' + e.total_acessos + '):'"></p>
                                                <template x-for="(a, i) in e.acessos" :key="i">
                                                    <p class="break-all font-mono text-[11px] text-ink-faint" x-text="a.ferramenta + ' · ' + a.alvo"></p>
                                                </template>
                                            </div>
                                        </details>
                                    </template>
                                </div>
                                <div class="space-y-1 md:col-span-2" x-show="tarefaVista.lacunas.length">
                                    <p class="font-mono uppercase tracking-caps text-ink-faint">Lacunas do contexto</p>
                                    <template x-for="x in tarefaVista.lacunas" :key="x"><p class="text-ink-faint" x-text="x"></p></template>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

            </main>
        </div>

        {{-- ================= ATIVIDADE ================= --}}
        <div x-show="tela === 'atividade'" x-cloak class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-panel border border-line bg-panel">
            <div class="flex flex-none flex-wrap items-center gap-2 border-b border-line px-3 py-2 text-xs">
                <button type="button" @click="vistaAtividade = 'log'" class="rounded-ctl px-2.5 py-1 font-semibold" :class="vistaAtividade === 'log' ? 'bg-nav-active text-ink' : 'text-ink-mute'">Log de eventos</button>
                <button type="button" @click="vistaAtividade = 'execucoes'" class="rounded-ctl px-2.5 py-1 font-semibold" :class="vistaAtividade === 'execucoes' ? 'bg-nav-active text-ink' : 'text-ink-mute'">Execuções</button>
                <span class="mx-1 h-4 w-px bg-line"></span>
                <label x-show="administracao.permitida" class="flex items-center gap-2">Usuário
                    <select x-model="usuarioAtividade" @change="carregarRelatorio('atividade')" class="rounded-control border-line bg-input py-0.5 text-xs text-ink">
                        <option value="">Minha atividade</option>
                        <template x-for="u in administracao.usuarios" :key="u.id"><option :value="u.id" x-text="u.nome + (u.ativo ? '' : ' (inativo)')"></option></template>
                    </select>
                </label>
                <label class="flex items-center gap-1 text-ink-dim" x-show="vistaAtividade === 'log' && !usuarioAtividade">
                    <input type="checkbox" x-model="soProblemas" class="rounded border-line bg-input text-brand focus:ring-brand"> só problemas
                </label>
                <select x-model="projetoLog" x-show="vistaAtividade === 'log' && !usuarioAtividade" class="rounded-control border-line bg-input py-0.5 text-xs text-ink focus:border-brand focus:ring-brand">
                    <option value="">Todos os projetos</option>
                    <template x-for="p in projetosDoLog" :key="p"><option :value="p" x-text="p"></option></template>
                </select>
            </div>

            <div class="flex flex-wrap gap-2 border-b border-line px-3 py-2 text-xs">
                <label>De <input type="date" x-model="inicioAtividade" @change="carregarRelatorio('atividade')" class="rounded-control border-line bg-input px-2 py-1 text-xs"></label>
                <label>Até <input type="date" x-model="fimAtividade" @change="carregarRelatorio('atividade')" class="rounded-control border-line bg-input px-2 py-1 text-xs"></label>
                <button @click="inicioAtividade = fimAtividade = diaHoje(); carregarRelatorio('atividade')" class="text-brand-text">Hoje</button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto px-3 py-2 text-xs leading-5">
                <p x-show="usuarioAtividade" class="text-ink-mute">Visão administrativa sem conteúdo privado.</p>
                <p x-show="relatorioAtividade.truncado" class="text-warn">Limite de 1.000 registros. Reduza o período.</p>
                <template x-for="e in (vistaAtividade === 'log' ? relatorioAtividade.eventos.filter(e => (!soProblemas || e.nivel !== 'info') && (!projetoLog || e.projeto === projetoLog)) : relatorioAtividade.execucoes)" :key="e.id">
                    <div class="flex flex-wrap gap-x-3 gap-y-1 border-b border-rule py-2"><span x-text="data(e.em || e.criada_em)"></span><strong x-text="e.tipo ? rotuloEvento(e.tipo) : execucao(e.status)"></strong><span x-text="e.agente"></span><span x-text="e.projeto"></span><span x-text="e.descricao || e.modo"></span></div>
                </template>
                <p x-show="!(vistaAtividade === 'log' ? relatorioAtividade.eventos : relatorioAtividade.execucoes).length" class="text-ink-mute">Nenhuma atividade no período selecionado.</p>
            </div>
        </div>

        {{-- ================= CONSUMO ================= --}}
        <div x-show="tela === 'consumo'" x-cloak class="min-h-0 flex-1 overflow-y-auto">
            <h3 class="mb-3 font-semibold text-ink">Minhas assinaturas agora e nos últimos 7 dias</h3>
            <div x-show="!usuarioConsumo && !iaConsumo">

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {{-- Limites: medidores, não gráfico --}}
                <div class="rounded-panel border border-line bg-panel p-4">
                    <p class="text-sm font-semibold text-ink">Central de IA</p>
                    <p class="text-xs text-ink-mute">Pedidos a agentes hoje</p>
                    <p class="mt-2 font-display text-2xl font-semibold text-ink" x-text="(consumo.limites?.central.usados ?? 0) + (consumo.limites?.central.limite == null ? '' : ' / ' + consumo.limites.central.limite)"></p>
                    <div x-show="consumo.limites?.central.limite != null" class="mt-1 h-1.5 rounded-full bg-bar-track"><div class="h-1.5 rounded-full" :class="corPct(100 * (consumo.limites?.central.usados ?? 0) / Math.max(1, consumo.limites?.central.limite ?? 1))" :style="'width:' + Math.min(100, 100 * (consumo.limites?.central.usados ?? 0) / Math.max(1, consumo.limites?.central.limite ?? 1)) + '%'"></div></div>
                    <p class="mt-1 text-xs text-ink-faint" x-text="consumo.limites?.central.limite == null ? 'Sem teto interno; franquias dos provedores continuam valendo.' : 'Restam ' + Math.max(0, consumo.limites.central.limite - (consumo.limites?.central.usados ?? 0)) + ' hoje'"></p>
                    <p class="text-xs text-ink-faint" x-text="Object.entries(consumo.limites?.por_agente_central || {}).map(([a, n]) => n + ' ' + a).join(' · ')"></p>
                </div>

                <div class="rounded-panel border border-line bg-panel p-4">
                    <p class="flex items-center gap-1.5 text-sm font-semibold text-ink"><span class="h-2.5 w-2.5 rounded-sm bg-brand"></span>Claude</p>
                    <p class="text-xs text-ink-mute">Assinatura (pelo /usage)</p>
                    <p x-show="!consumo.limites?.claude" class="mt-2 text-xs text-ink-faint">Aguardando a primeira leitura do runner.</p>
                    <template x-for="j in consumo.limites?.claude?.janelas || []" :key="j.rotulo">
                        <div class="mt-2">
                            <div class="flex justify-between text-xs"><span class="text-ink-dim" x-text="j.rotulo"></span><span class="font-semibold text-ink" x-text="j.usado_pct + '%'"></span></div>
                            <div class="mt-0.5 h-1.5 rounded-full bg-bar-track"><div class="h-1.5 rounded-full" :class="corPct(j.usado_pct)" :style="'width:' + Math.min(100, j.usado_pct) + '%'"></div></div>
                            <p class="text-[11px] text-ink-faint" x-text="(j.usado_pct >= 100 ? 'esgotado' : 'resta ' + Math.max(0, 100 - j.usado_pct) + '%') + (j.renova ? ' · renova ' + j.renova : '')"></p>
                        </div>
                    </template>
                </div>

                <div class="rounded-panel border border-line bg-panel p-4">
                    <p class="flex items-center gap-1.5 text-sm font-semibold text-ink"><span class="h-2.5 w-2.5 rounded-sm bg-chart-out"></span>GPT (Codex)</p>
                    <p class="text-xs text-ink-mute">Cota do ChatGPT</p>
                    <p x-show="!consumo.limites?.codex" class="mt-2 text-xs text-ink-faint">Sem leitura ainda — aparece depois do primeiro uso do Codex.</p>
                    <template x-for="[rotulo, j] in [['Janela de 5 h', consumo.limites?.codex?.primaria], ['Semana', consumo.limites?.codex?.semanal]]" :key="rotulo">
                        <div class="mt-2" x-show="j">
                            <div class="flex justify-between text-xs"><span class="text-ink-dim" x-text="rotulo"></span><span class="font-semibold text-ink" x-text="(j?.usado_pct ?? 0) + '%'"></span></div>
                            <div class="mt-0.5 h-1.5 rounded-full bg-bar-track"><div class="h-1.5 rounded-full" :class="corPct(j?.usado_pct ?? 0)" :style="'width:' + Math.min(100, j?.usado_pct ?? 0) + '%'"></div></div>
                            <p class="text-[11px] text-ink-faint" x-text="j?.renovou ? 'renovou desde o último uso' : ('resta ' + Math.max(0, 100 - (j?.usado_pct ?? 0)) + '%' + (j?.renova_em ? ' · renova ' + data(j.renova_em) : ''))"></p>
                        </div>
                    </template>
                </div>

                <div class="rounded-panel border border-line bg-panel p-4">
                    <p class="flex items-center gap-1.5 text-sm font-semibold text-ink"><span class="h-2.5 w-2.5 rounded-sm bg-retorno"></span>Copilot</p>
                    <p class="text-xs text-ink-mute">Saldo oficial ainda não verificado</p>
                    <p class="mt-2 text-sm font-semibold text-ink">Confirmar conta, plano e créditos no GitHub</p>
                    <p class="mt-2 text-xs text-ink-faint" x-text="'Soma de custo nos registros locais deste mês: ' + (consumo.limites?.copilot?.premium_mes ?? '—')"></p>
                    <p class="mt-1 text-xs text-ink-faint">Esse total não representa créditos disponíveis nem confirma a franquia da assinatura.</p>
                    <a href="https://github.com/settings/copilot" target="_blank" rel="noopener noreferrer" class="mt-2 inline-block text-xs font-semibold text-brand-text">Conferir plano e uso no GitHub</a>
                </div>
            </div>

            <div class="mt-3 grid gap-3 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                {{-- Tokens por dia, por IA: barras empilhadas --}}
                <div class="rounded-panel border border-line bg-panel p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-semibold text-ink">Tokens por dia — últimos 7 dias</p>
                        <div class="flex items-center gap-3 text-xs text-ink-dim">
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-brand"></span>Claude</span>
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-chart-out"></span>GPT (Codex)</span>
                            <span class="flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-retorno"></span>Copilot</span>
                            <button type="button" @click="mostrarTabela = !mostrarTabela" class="font-semibold text-brand-text" x-text="mostrarTabela ? 'Ver gráfico' : 'Ver tabela'"></button>
                        </div>
                    </div>

                    <div x-show="!mostrarTabela" class="relative mt-3">
                        {{-- HTML e não SVG: o Alpine não suporta <template x-for> dentro de <svg>. --}}
                        <div class="flex h-56 items-end gap-2 border-b border-rule pb-1" role="img" aria-label="Tokens de entrada e saída por dia e por IA, últimos 7 dias">
                            <template x-for="(d, i) in consumo.serie" :key="d.dia">
                                <div class="flex h-full flex-1 flex-col items-center justify-end" @mouseenter="barraHover = i" @mouseleave="barraHover = null">
                                    <span class="mb-1 text-[10px] text-ink-dim" x-show="barraHover === i || i === consumo.serie.length - 1" x-text="tokens(d.claude + d.codex + d.copilot)"></span>
                                    <div class="flex w-9 flex-col-reverse gap-[2px]">
                                        {{-- col-reverse: o primeiro fica embaixo — Claude, Codex, Copilot, na ordem da legenda --}}
                                        <div class="rounded-[2px] bg-brand" :style="'height:' + altura(d.claude) + 'px'" x-show="d.claude"></div>
                                        <div class="rounded-[2px] bg-chart-out" :style="'height:' + altura(d.codex) + 'px'" x-show="d.codex"></div>
                                        <div class="rounded-[2px] bg-retorno" :style="'height:' + altura(d.copilot) + 'px'" x-show="d.copilot"></div>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <div class="mt-1 flex gap-2">
                            <template x-for="d in consumo.serie" :key="'r' + d.dia">
                                <span class="flex-1 text-center text-[11px] text-ink-faint" x-text="diaCurto(d.dia)"></span>
                            </template>
                        </div>
                        <div x-show="barraHover !== null" x-cloak class="pointer-events-none absolute top-0 rounded-control border border-line bg-panel px-3 py-2 text-xs shadow"
                             :style="'left:' + Math.min(70, (barraHover ?? 0) * 14.3) + '%'">
                            <template x-if="barraHover !== null && consumo.serie[barraHover]">
                                <div>
                                    <p class="font-semibold text-ink" x-text="diaCurto(consumo.serie[barraHover].dia)"></p>
                                    <p class="text-ink-dim" x-text="'Claude: ' + tokens(consumo.serie[barraHover].claude)"></p>
                                    <p class="text-ink-dim" x-text="'GPT (Codex): ' + tokens(consumo.serie[barraHover].codex)"></p>
                                    <p class="text-ink-dim" x-text="'Copilot: ' + tokens(consumo.serie[barraHover].copilot)"></p>
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Grade de div: a regra do redesign reserva tabela de verdade ao componente x-tabela. --}}
                    <div x-show="mostrarTabela" x-cloak class="mt-3 text-xs" role="table" aria-label="Tokens por dia e por IA">
                        <div class="grid grid-cols-5 py-1 text-ink-faint" role="row"><span>Dia</span><span>Claude</span><span>GPT (Codex)</span><span>Copilot</span><span>Total</span></div>
                        <template x-for="d in consumo.serie" :key="'t' + d.dia">
                            <div class="grid grid-cols-5 border-t border-rule py-1 text-ink-dim" role="row">
                                <span x-text="diaCurto(d.dia)"></span><span x-text="tokens(d.claude)"></span><span x-text="tokens(d.codex)"></span><span x-text="tokens(d.copilot)"></span><span class="text-ink" x-text="tokens(d.claude + d.codex + d.copilot)"></span>
                            </div>
                        </template>
                    </div>
                    <p class="mt-2 text-[11px] text-ink-faint">Tokens de entrada e saída, sem cache, lidos dos registros das IAs nesta máquina (inclui o uso no VS Code, não só a Central).</p>
                </div>

                <div class="rounded-panel border border-line bg-panel p-4 text-xs">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-semibold text-ink">Por IA</p>
                        <div class="flex gap-2">
                            <button type="button" @click="periodo = 'hoje'" :class="periodo === 'hoje' ? 'font-semibold text-brand-text' : 'text-ink-mute'">Hoje</button>
                            <button type="button" @click="periodo = 'semana'" :class="periodo === 'semana' ? 'font-semibold text-brand-text' : 'text-ink-mute'">7 dias</button>
                        </div>
                    </div>
                    <template x-for="a in consumo.por_agente" :key="a.agente">
                        <div class="mt-2 flex items-baseline justify-between gap-2">
                            <span class="font-semibold text-ink" x-text="a.nome"></span>
                            <span class="text-right text-ink-dim">
                                <span x-text="tokens(a[periodo].entrada + a[periodo].saida) + ' tokens'"></span>
                                <span class="block text-ink-faint" x-text="tokens(a[periodo].cache_leitura) + ' em cache' + (a.agente === 'copilot' ? ' · ' + a[periodo].premium + ' premium' : '')"></span>
                            </span>
                        </div>
                    </template>
                    <div x-show="consumo.projetos_hoje.length" class="mt-3 space-y-0.5 border-t border-rule pt-2">
                        <p class="text-ink-mute">Projetos que mais gastaram hoje</p>
                        <template x-for="p in consumo.projetos_hoje" :key="p.projeto">
                            <div class="flex justify-between text-ink-faint"><span x-text="p.projeto"></span><span x-text="tokens(p.tokens)"></span></div>
                        </template>
                    </div>
                    <div x-show="recentes.length" class="mt-3 space-y-0.5 border-t border-rule pt-2">
                        <p class="text-ink-mute">Recentes no git</p>
                        <template x-for="r in recentes.slice(0, 8)" :key="'g' + r.nome">
                            <button type="button" @click="abrir(r.nome)" class="flex w-full justify-between text-left text-ink-faint hover:text-ink">
                                <span x-text="r.nome"></span><span x-text="haQuanto(r.ultimo_commit)"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
            </div>
            <div class="mt-3 rounded-panel border border-line bg-panel px-3 py-2 space-y-2">
                <h3 class="text-sm font-semibold text-ink">Histórico por período</h3>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-xs text-ink-mute">
                    <label class="inline-flex items-center gap-1.5">De <input type="date" x-model="inicioConsumo" @change="carregarRelatorio('consumo')" class="h-7 rounded-control border-line bg-input px-2 py-0 text-xs text-ink"></label>
                    <label class="inline-flex items-center gap-1.5">Até <input type="date" x-model="fimConsumo" @change="carregarRelatorio('consumo')" class="h-7 rounded-control border-line bg-input px-2 py-0 text-xs text-ink"></label>
                    <label x-show="administracao.permitida" class="inline-flex items-center gap-1.5">Usuário <select x-model="usuarioConsumo" @change="carregarRelatorio('consumo')" class="h-7 rounded-control border-line bg-input px-2 py-0 text-xs text-ink"><option value="">Meu consumo</option><template x-for="u in administracao.usuarios" :key="u.id"><option :value="u.id" x-text="u.nome"></option></template></select></label>
                    <label class="inline-flex items-center gap-1.5">IA <select x-model="iaConsumo" @change="carregarRelatorio('consumo')" class="h-7 rounded-control border-line bg-input px-2 py-0 text-xs text-ink"><option value="">Todas</option><option value="claude">Claude</option><option value="codex">Codex</option><option value="copilot">Copilot</option></select></label>
                </div>
                <p class="text-[11px] leading-4 text-ink-faint">Consumo informado pelos runners no período. Ausência de registros não comprova consumo zero; dados antigos podem não estar disponíveis.</p>
                <x-tabela min="520px" class="text-sm"><thead><tr><th>Dia</th><th>IA</th><th>Entrada + saída</th><th>Cache</th><th>Premium</th></tr></thead><tbody><template x-for="l in relatorioConsumo.consumo" :key="l.dia + l.agente"><tr class="border-t border-rule"><td x-text="l.dia"></td><td x-text="l.agente"></td><td x-text="tokens(l.entrada + l.saida)"></td><td x-text="tokens(l.cache)"></td><td x-text="l.premium"></td></tr></template></tbody></x-tabela>
                <p x-show="!relatorioConsumo.consumo.length" class="text-ink-mute">Sem registros para esses filtros.</p>
            </div>
        </div>

        {{-- ================= AJUSTES ================= --}}
        <div x-show="tela === 'ajustes'" x-cloak class="grid min-h-0 flex-1 content-start gap-3 overflow-y-auto lg:grid-cols-2">
            <div class="rounded-panel border border-line bg-panel p-4 text-sm lg:col-span-2">
                <h3 class="font-semibold text-ink">Minhas IAs e regras de uso</h3>
                <p class="mt-1 text-ink-mute">Escolha manual vence o padrão. Automático usa regras locais e informa o motivo, sem gastar uma chamada ao GPT para decidir.</p>
                <p x-show="ajustes?.execucao_bloqueada" class="mt-2 text-warn">A execução de agentes nesta conta depende da liberação do dono da Central. Seus ajustes não removem esse bloqueio.</p>
                <p x-show="!preferencias?.disponivel" class="mt-2 text-warn">A ativação local destes ajustes está pendente. Não foram salvos nem aplicados.</p>
                <template x-if="ajustes">
                    <fieldset :disabled="salvandoAjustes || !preferencias?.disponivel" class="mt-3 space-y-3" @input="ajustesAlterados = true" @change="ajustesAlterados = true">
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <template x-for="(rotulo, categoria) in {implementacao:'Desenvolvimento', investigacao:'Análise', revisao:'Revisão', planejamento:'Planejamento', relatorio:'Relatórios', apoio:'Rotinas e apoio'}" :key="categoria">
                                <label class="space-y-1"><span class="block text-ink-mute" x-text="rotulo"></span>
                                    <select x-model="ajustes.padroes[categoria]" class="w-full rounded-control border-line bg-input text-sm text-ink">
                                        <option value="claude">Claude</option><option value="codex">GPT (Codex)</option><option value="copilot">Copilot</option>
                                    </select>
                                </label>
                            </template>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <template x-for="(nome, agente) in {claude:'Claude', codex:'GPT (Codex)', copilot:'Copilot'}" :key="agente">
                                <div class="rounded-control border border-line p-3 space-y-2">
                                    <label class="flex gap-2 items-center"><input type="checkbox" x-model="ajustes.habilitados[agente]" class="rounded border-line text-brand"><span x-text="nome"></span></label>
                                    <label class="block text-ink-mute">Solicitações por dia<span class="block font-semibold text-ink" x-text="ajustes.pedidos_dia[agente] == null ? 'Sem teto interno' : ajustes.pedidos_dia[agente]"></span><input type="range" min="0" max="10000" step="1" :value="ajustes.pedidos_dia[agente] ?? 0" @input="ajustes.pedidos_dia[agente] = Number($event.target.value)" :disabled="ajustes.pedidos_dia[agente] == null" class="mt-2 w-full accent-brand"><span class="flex items-center gap-2 text-xs"><input type="checkbox" :checked="ajustes.pedidos_dia[agente] == null" @change="ajustes.pedidos_dia[agente] = $event.target.checked ? null : 20">Sem teto interno</span></label>
                                </div>
                            </template>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="text-ink-mute">Limite total diário<span class="block font-semibold text-ink" x-text="ajustes.limite_total == null ? 'Sem teto interno' : ajustes.limite_total"></span><input type="range" min="0" max="30000" step="1" :value="ajustes.limite_total ?? 0" @input="ajustes.limite_total = Number($event.target.value)" :disabled="ajustes.limite_total == null" class="mt-2 w-full accent-brand"><span class="flex items-center gap-2 text-xs"><input type="checkbox" :checked="ajustes.limite_total == null" @change="ajustes.limite_total = $event.target.checked ? null : 20">Sem teto interno</span></label>
                            <label class="text-ink-mute">Execuções simultâneas<span class="block font-semibold text-ink" x-text="ajustes.paralelo == null ? 'Sem teto interno' : ajustes.paralelo"></span><input type="range" min="1" max="{{ max(1, (int) config('central_ia.agentes.paralelo')) }}" step="1" :value="ajustes.paralelo ?? 0" @input="ajustes.paralelo = Number($event.target.value)"  class="mt-2 w-full accent-brand"></label>
                        </div>
                        {{-- Níveis de custo (FR-027): o modelo de cada IA em cada nível. Vazio = padrão da CLI. --}}
                        <div class="space-y-2" x-show="ajustes.niveis">
                            <p class="font-semibold text-ink">Modelos por nível de custo</p>
                            <p class="text-xs text-ink-faint">Em automático, cada pedido vai ao nível mais barato que resolve: agenda sem IA; ler e resumir no Econômico; analisar e revisar no Médio; implementar no Máximo. Deixe o modelo vazio para usar o padrão de cada IA.</p>
                            <div class="grid gap-2 sm:grid-cols-3">
                                <template x-for="(rotuloNivel, nivel) in {leve: 'Econômico', medio: 'Médio', forte: 'Máximo'}" :key="nivel">
                                    <div class="space-y-1.5 rounded-control border border-line p-3">
                                        <p class="text-xs font-semibold text-ink" x-text="rotuloNivel"></p>
                                        <template x-for="(nomeIa, ia) in {claude: 'Claude', codex: 'Codex', copilot: 'Copilot'}" :key="nivel + ia">
                                            <label class="flex items-center gap-2 text-xs text-ink-mute"><span class="w-14 flex-none" x-text="nomeIa"></span>
                                                <input type="text" :value="ajustes.niveis?.[nivel]?.[ia]?.modelo ?? ''" @input="ajustes.niveis[nivel][ia].modelo = $event.target.value.trim() || null"
                                                       placeholder="padrão" pattern="[A-Za-z0-9._-]+" maxlength="60" class="min-w-0 flex-1 rounded-control border-line bg-input py-1 text-xs text-ink">
                                                <select :value="ajustes.niveis?.[nivel]?.[ia]?.esforco ?? ''" @change="ajustes.niveis[nivel][ia].esforco = $event.target.value || null" :aria-label="'Esforço de ' + nomeIa + ' no nível ' + rotuloNivel" class="rounded-control border-line bg-input py-1 text-xs text-ink">
                                                    <option value="">padrão</option><option value="low">baixo</option><option value="medium">médio</option><option value="high">alto</option>
                                                </select>
                                            </label>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>
                        {{-- Confirmação pelo Telegram: por pessoa; a máquina dela impõe o mínimo. --}}
                        <div class="space-y-1">
                            <label class="block font-semibold text-ink" for="central-confirmacao">Código de confirmação no Telegram</label>
                            <select id="central-confirmacao" x-model="ajustes.confirmacao" class="w-full max-w-md rounded-control border-line bg-input text-sm text-ink">
                                <option value="inicio">No início de cada chat e em todo deploy (recomendado)</option>
                                <option value="deploy">Só em deploy, produção ou publicação</option>
                                <option value="implementar">Em toda implementação e em todo deploy</option>
                                <option value="sempre">Em todo pedido, até análise</option>
                                <option value="nunca">Nunca</option>
                            </select>
                            <p class="text-xs text-ink-faint" x-show="maquina.politica"
                               x-text="'Sua máquina: mínimo ' + ({nunca:'nunca', deploy:'só deploy', inicio:'início do chat e deploy', implementar:'toda implementação', sempre:'todo pedido'}[maquina.politica?.confirmar_minimo] || '?')
                                   + ' · Telegram ' + (maquina.politica?.telegram ? 'configurado' : 'NÃO configurado')
                                   + ' · acesso ' + (maquina.politica?.acesso_maximo || '?') + ' · análise ' + (maquina.politica?.analise === 'total' ? 'com acesso total' : 'só leitura')
                                   + '. Aqui você só deixa mais rígido que o mínimo da máquina; o mínimo muda no arquivo de configuração dela.'"></p>
                        </div>
                        <label class="flex gap-2 items-center"><input type="checkbox" x-model="ajustes.fallback" class="rounded border-line text-brand">Tentar outra IA habilitada quando o provedor estiver indisponível ou no limite</label>
                        <label class="flex gap-2 items-start"><input type="checkbox" x-model="ajustes.escalar" class="mt-0.5 rounded border-line text-brand">
                            <span>Escalar sozinho quando a IA não resolver: sobe um nível (econômico → médio → máximo) e, no máximo, passa para outra IA. Vale também quando você pede para refazer. Sempre com aviso na conversa; para depois de 3 trocas.</span></label>
                        <p class="text-xs text-ink-faint">Sem teto interno: sem limite administrativo. Zero: bloqueia novos pedidos. Tentativas alternativas contam nos limites. As franquias reais continuam sendo as de cada assinatura. Nenhum adaptador de API paga foi adicionado; a autenticação do executor precisa de validação própria. Desabilitar suspende novos pedidos e a fila dessa IA, sem interromper processos já iniciados.</p>
                        @if ($podeParear)
                            <button type="button" @click="salvarAjustes()" :disabled="salvandoAjustes || !preferencias?.disponivel" class="rounded-control bg-brand px-3 py-2 font-semibold text-on-brand disabled:opacity-50">Salvar meus ajustes</button>
                        @endif
                    </fieldset>
                </template>
            </div>
            {{-- POPs das IAs (FR-030..033): o que elas aprenderam de cada sistema.
                 Só desta conta: nenhum administrador vê, nada vai a repositório. --}}
            <div class="rounded-panel border border-line bg-panel p-4 text-sm lg:col-span-2">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="font-semibold text-ink">POPs das IAs <span class="font-normal text-ink-faint" x-text="'(' + pops.length + ')'"></span></h3>
                    @if ($podeParear)
                        <button type="button" @click="novoPop()" class="rounded-control border border-btn-line px-3 py-1 text-xs font-semibold text-ink">+ Novo POP</button>
                    @endif
                </div>
                <p class="mt-1 text-xs text-ink-mute">Procedimentos que as IAs aprendem ao operar cada sistema (como registrar, o que precisa saber, cuidados). Vão junto nos pedidos do mesmo sistema, e a IA os corrige quando precisa. Só você e as suas IAs veem.</p>
                <p x-show="!pops.length && !popEditando" class="mt-2 text-ink-faint">Nenhum POP ainda. Eles nascem quando uma IA descobre como fazer algo num sistema.</p>
                <template x-if="popEditando">
                    <form @submit.prevent="salvarPop()" class="mt-3 space-y-2 rounded-control border border-brand-mute p-3">
                        <div class="flex flex-wrap gap-2">
                            <label class="text-xs text-ink-mute">Sistema
                                <input x-model="popEditando.sistema" required pattern="[A-Za-z0-9._-]+" maxlength="128" class="mt-1 block w-40 rounded-control border-line bg-input py-1 text-sm text-ink"></label>
                            <label class="min-w-0 flex-1 text-xs text-ink-mute">Título
                                <input x-model="popEditando.titulo" required maxlength="160" class="mt-1 block w-full rounded-control border-line bg-input py-1 text-sm text-ink"></label>
                        </div>
                        <label class="block text-xs text-ink-mute">Conteúdo (markdown)
                            <textarea x-model="popEditando.conteudo" required rows="10" class="mt-1 block w-full rounded-control border-line bg-input font-mono text-xs text-ink"></textarea></label>
                        <div class="flex justify-end gap-3">
                            <button type="button" @click="popEditando = null" class="text-xs text-ink-mute">Cancelar</button>
                            <button type="submit" :disabled="salvandoPop" class="rounded-control bg-brand px-3 py-1.5 text-xs font-semibold text-on-brand disabled:opacity-50">Salvar POP</button>
                        </div>
                    </form>
                </template>
                <template x-for="[sistema, lista] in popsPorSistema" :key="sistema">
                    <div class="mt-3">
                        <p class="font-mono text-xs uppercase tracking-caps text-ink-faint" x-text="sistema"></p>
                        <template x-for="p in lista" :key="p.id">
                            <details class="mt-1 rounded-control border border-line px-3 py-2">
                                <summary class="cursor-pointer">
                                    <span class="font-semibold text-ink" x-text="p.titulo"></span>
                                    <span class="text-xs text-ink-faint" x-text="' · versão ' + p.versao + ' · ' + p.autor + ' · ' + haQuanto(p.atualizado_em)"></span>
                                </summary>
                                <div class="central-resposta mt-2 whitespace-pre-wrap text-xs text-ink-dim" x-text="p.conteudo"></div>
                                @if ($podeParear)
                                    <div class="mt-2 flex gap-3 text-xs">
                                        <button type="button" @click="editarPop(p)" class="font-semibold text-brand-text">Editar</button>
                                        <button type="button" @click="apagarPop(p)" class="font-semibold text-crit">Apagar</button>
                                    </div>
                                @endif
                            </details>
                        </template>
                    </div>
                </template>
            </div>

            <div class="rounded-panel border border-line bg-panel p-4 text-sm">
                <div class="flex items-center justify-between">
                    <h3 class="font-semibold text-ink">Grupos de projetos</h3>
                    <button type="button" @click="criarGrupo()" class="rounded-control border border-btn-line px-3 py-1 text-xs font-semibold text-ink">+ Novo grupo</button>
                </div>
                <p x-show="!grupos.length" class="mt-2 text-ink-faint">Nenhum grupo ainda.</p>
                <ul class="mt-2 divide-y divide-rule">
                    <template x-for="g in grupos" :key="g.id">
                        <li class="flex items-center justify-between py-1.5">
                            <span class="text-ink" x-text="g.nome + ' (' + projetos.filter(p => p.grupo_id === g.id).length + ')'"></span>
                            <span class="flex gap-3 text-xs">
                                <button type="button" @click="renomearGrupo(g)" class="font-semibold text-brand-text">Renomear</button>
                                <button type="button" @click="apagarGrupo(g)" class="font-semibold text-crit">Apagar</button>
                            </span>
                        </li>
                    </template>
                </ul>
                <p class="mt-2 text-xs text-ink-faint">Para pôr um projeto num grupo, use o seletor "Grupo" no card dele, na aba Projetos.</p>
            </div>

            <div class="rounded-panel border border-line bg-panel p-4 text-sm">
                <h3 class="font-semibold text-ink">Telegram</h3>
                <template x-if="telegram.vinculado">
                    <div class="mt-1 space-y-1 text-ink-mute">
                        <p>A conversa também está no seu Telegram<span x-show="telegram.usuario" x-text="' (@' + telegram.usuario + ')'"></span>; cada mensagem diz o projeto.</p>
                        @if ($podeParear)
                            <button type="button" @click="desvincularTelegram()" class="text-xs font-semibold text-brand-text">Desvincular</button>
                        @endif
                    </div>
                </template>
                <template x-if="!telegram.vinculado">
                    <div class="mt-1 space-y-2 text-ink-mute">
                        <p>Converse pelo celular com <span class="font-semibold text-ink">{{ '@'.config('central_ia.telegram.bot') }}</span>.</p>
                        @if ($podeParear)
                            <button type="button" x-show="!pareamento" @click="parearTelegram()" class="rounded-control border border-btn-line px-3 py-1.5 text-xs font-semibold text-ink">Vincular Telegram</button>
                            <div x-show="pareamento" x-cloak class="space-y-1">
                                <a :href="pareamento?.link" target="_blank" rel="noopener" class="inline-block rounded-control bg-brand px-3 py-1.5 text-xs font-semibold text-on-brand">Abrir no Telegram</a>
                                <p class="text-xs">Ou envie: <code class="break-all font-mono text-ink" x-text="pareamento?.comando"></code></p>
                            </div>
                        @endif
                    </div>
                </template>
                <p class="mt-3 text-xs text-ink-faint">Comandos: <code class="font-mono">/projetos</code>, <code class="font-mono">/status</code>, <code class="font-mono">/usar gestor</code>, <code class="font-mono">/continuar gestor</code>, <code class="font-mono">/nova</code>, <code class="font-mono">/geral</code>, <code class="font-mono">/trocar codex</code>, <code class="font-mono">/salvar</code>, <code class="font-mono">/claude</code> · <code class="font-mono">/gpt</code> · <code class="font-mono">/copilot</code> · <code class="font-mono">/auto</code>. Também vale escrever "continuar gestor de onde paramos".</p>
            </div>

            <div class="rounded-panel border border-line bg-panel p-4 text-sm lg:col-span-2">
                <h3 class="font-semibold text-ink">Acesso e limites</h3>
                <p class="mt-1 text-ink-mute" x-text="'Pedidos a agentes por dia: ' + (consumo.limites?.central.limite ?? 'sem teto interno') + ' · ao mesmo tempo: ' + paralelo"></p>
                <p class="mt-1 text-ink-mute">Acesso amplo inicial autorizado pelo dono. O executor atual usa o usuário Linux e suas conexões locais. Selecionar uma pasta não impede tecnicamente acesso a outras pastas.</p>
                <p class="mt-2 text-warn">Permissões por pasta, ferramentas, agenda e credenciais isoladas exigem um executor separado por usuário. Não habilite outro usuário no mesmo executor ou HOME. O bloqueio técnico de commit, push e deploy ainda não está comprovado; instruções ao agente não substituem esse bloqueio.</p>
                <p class="mt-2 text-ink-mute">Tokens e franquias aparecem em Consumo quando informados pelo provedor. Um teto exato por execução não está implementado; solicitações não equivalem a tokens.</p>
                <p class="mt-1 text-xs text-ink-faint">A franquia mensal do Copilot (Pro: 300, Pro+: 1500) é configurada no servidor (<code class="font-mono">CENTRAL_IA_COPILOT_PREMIUM_MES</code>) — o plano não pode ser lido da máquina.</p>
            </div>
            <div x-show="administracao.permitida" class="rounded-panel border border-line bg-panel p-4 text-sm lg:col-span-2">
                <h3 class="font-semibold text-ink">Outras contas (você é o dono da Central)</h3>
                <p class="mt-1 text-xs text-ink-mute">Só contagens e limites — nunca conversas, arquivos ou credenciais. Ninguém administra a sua conta.</p>
                <label class="mt-2 block text-ink-mute">Usuário
                    <select x-model="usuarioAtividade" @change="carregarUsuario()" :disabled="salvandoPolitica" class="mt-1 rounded-control border-line bg-input text-sm text-ink">
                        <option value="">Selecione outra conta</option>
                        <template x-for="u in administracao.usuarios" :key="u.id"><option :value="u.id" x-text="u.nome + ' · #' + u.id"></option></template>
                    </select>
                </label>
                <template x-if="politicaUsuario && resumoUsuario">
                    <fieldset :disabled="salvandoPolitica" @input="politicaAlterada = true" @change="politicaAlterada = true" class="mt-3 space-y-3">
                        <label class="flex items-center gap-2"><input type="checkbox" :checked="!politicaUsuario.execucao_bloqueada" @change="politicaUsuario.execucao_bloqueada = !$event.target.checked" class="rounded border-line text-brand">Liberar a execução de agentes para esta conta</label>
                        <p class="text-xs text-ink-faint">A pessoa executa na máquina DELA, com as IAs dela; o runner dela impõe os próprios limites e pede o código dela antes de modificar algo. Deploy e OneDrive continuam fora do alcance da tela.</p>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <template x-for="(nome, a) in {claude:'Claude', codex:'Codex', copilot:'Copilot'}" :key="a">
                                <div class="rounded-control border border-line p-3 space-y-2"><label class="flex gap-2"><input type="checkbox" x-model="politicaUsuario.habilitados[a]" class="rounded border-line text-brand"><span x-text="nome"></span></label>
                                    <label class="block text-ink-mute">Máximo por dia<span class="block font-semibold text-ink" x-text="politicaUsuario.pedidos_dia[a] == null ? 'Sem teto interno' : politicaUsuario.pedidos_dia[a]"></span><input type="range" min="0" max="10000" step="1" :value="politicaUsuario.pedidos_dia[a] ?? 0" @input="politicaUsuario.pedidos_dia[a] = Number($event.target.value)" :disabled="politicaUsuario.pedidos_dia[a] == null" class="mt-2 w-full accent-brand"><span class="flex items-center gap-2 text-xs"><input type="checkbox" :checked="politicaUsuario.pedidos_dia[a] == null" @change="politicaUsuario.pedidos_dia[a] = $event.target.checked ? null : 20">Sem teto interno</span></label>
                                </div>
                            </template>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2"><label class="text-ink-mute">Máximo total diário<span class="block font-semibold text-ink" x-text="politicaUsuario.limite_total == null ? 'Sem teto interno' : politicaUsuario.limite_total"></span><input type="range" min="0" max="30000" step="1" :value="politicaUsuario.limite_total ?? 0" @input="politicaUsuario.limite_total = Number($event.target.value)" :disabled="politicaUsuario.limite_total == null" class="mt-2 w-full accent-brand"><span class="flex items-center gap-2 text-xs"><input type="checkbox" :checked="politicaUsuario.limite_total == null" @change="politicaUsuario.limite_total = $event.target.checked ? null : 20">Sem teto interno</span></label>
                            <label class="text-ink-mute">Simultâneas<span class="block font-semibold text-ink" x-text="politicaUsuario.paralelo == null ? 'Sem teto interno' : politicaUsuario.paralelo"></span><input type="range" min="1" max="{{ max(1, (int) config('central_ia.agentes.paralelo')) }}" step="1" :value="politicaUsuario.paralelo ?? 0" @input="politicaUsuario.paralelo = Number($event.target.value)"  class="mt-2 w-full accent-brand"></label></div>
                        <button type="button" @click="salvarPolitica()" class="rounded-control bg-brand px-3 py-2 font-semibold text-on-brand">Salvar política administrativa</button>
                    </fieldset>
                </template>
            </div>
        </div>
    <div x-show="perguntaAberta" x-cloak @keydown.escape.window="perguntaAberta = null" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <section x-ref="dialogoPergunta" @keydown.tab="conterFocoPergunta($event)" role="dialog" aria-modal="true" aria-labelledby="titulo-pergunta-central" class="w-full max-w-lg rounded-panel border border-line bg-panel p-5 shadow-xl">
            <h3 id="titulo-pergunta-central" class="font-semibold text-ink">Preciso de uma informação</h3>
            <p class="mt-3 max-h-60 overflow-y-auto whitespace-pre-wrap text-sm text-ink" x-text="perguntaAberta?.texto"></p>
            <div class="mt-3 flex flex-wrap gap-2"><template x-for="(o, i) in perguntaAberta?.opcoes || []" :key="i"><button type="button" @click="respostaPergunta = o" :aria-pressed="respostaPergunta === o" class="rounded-control border border-line px-3 py-2 text-sm text-ink" :class="respostaPergunta === o ? 'bg-nav-active' : ''" x-text="o"></button></template></div>
            <label x-show="perguntaAberta?.tipo !== 'formato'" class="mt-3 block text-xs text-ink-mute">Sua resposta<textarea x-ref="respostaPergunta" x-model="respostaPergunta" rows="3" class="mt-1 w-full rounded-control border-line bg-input text-sm text-ink" placeholder="Escolha uma opção ou digite…"></textarea></label>
            <p class="mt-2 text-xs text-ink-faint">Responder esclarece o pedido; não concede acesso a produção ou autorização de operações críticas.</p>
            <div class="mt-4 flex justify-end gap-3"><button type="button" @click="perguntaAberta = null" class="text-sm text-ink-mute">Agora não</button><button type="button" @click="responderPergunta()" :disabled="!respostaPergunta.trim() || perguntaAberta?.chave !== chaveAba || aba.enviando || !!aba.pendente || atendimento.ativo === 'telegram'" class="rounded-control bg-brand px-3 py-2 text-sm font-semibold text-on-brand disabled:opacity-50">Enviar resposta</button></div>
        </section>
    </div>
    </section>
</x-app-layout>
