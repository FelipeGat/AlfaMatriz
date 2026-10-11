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
                historico: '{{ route('central-ia.mensagens') }}',
                enviar: '{{ route('central-ia.enviar') }}',
                anexar: '{{ route('central-ia.anexos.criar') }}',
                tarefas: '{{ route('central-ia.tarefas', '__P__') }}',
                fixar: '{{ route('central-ia.fixar', '__P__') }}',
                statusTarefa: '{{ url('central-ia/tarefas/__T__/status') }}',
                ponto: '{{ url('central-ia/tarefas/__T__/ponto') }}',
                continuar: '{{ route('central-ia.sessoes.continuar') }}',
                grupos: '{{ route('central-ia.grupos.criar') }}',
                grupo: '{{ url('central-ia/grupos/__G__') }}',
                grupoDoProjeto: '{{ route('central-ia.projetos.grupo', '__P__') }}',
                canal: '{{ route('central-ia.canal') }}',
                preferencias: '{{ route('central-ia.preferencias') }}',
                arquivarChats: '{{ route('central-ia.chats.arquivar') }}',
                telegramCodigo: '{{ route('central-ia.telegram.codigo') }}',
                telegramDesvincular: '{{ route('central-ia.telegram.desvincular') }}',
                podeEnviar: {{ $podeEnviar ? 'true' : 'false' }},
             })"
             data-central-ia class="mx-auto flex h-[calc(100dvh-7.5rem)] max-w-[1600px] flex-col gap-3">

        {{-- ===== Faixa: abas + estado ===== --}}
        <div class="flex flex-none flex-wrap items-center gap-2 text-xs">
            <nav class="flex rounded-control border border-line bg-panel p-0.5" aria-label="Seções da Central">
                @foreach (['chats' => 'Chats Ativos', 'projetos' => 'Projetos', 'atividade' => 'Atividade', 'consumo' => 'Consumo', 'ajustes' => 'Ajustes'] as $chave => $rotulo)
                    <button type="button" @click="irPara('{{ $chave }}')" class="rounded-ctl px-3 py-1.5 font-semibold"
                            :class="tela === '{{ $chave }}' ? 'bg-nav-active text-ink' : 'text-ink-mute hover:text-ink'">
                        {{ $rotulo }}
                        @if ($chave === 'atividade')
                            <span x-show="emExecucao + naFila" class="ml-1 rounded-badge bg-brand px-1 text-on-brand" x-text="emExecucao + naFila"></span>
                            <span x-show="eventos.some(e => e.nivel === 'erro' && Date.now() - new Date(e.em) < 3600000)" class="ml-1 inline-block h-1.5 w-1.5 rounded-full bg-crit"></span>
                        @endif
                    </button>
                @endforeach
            </nav>
            <button type="button" @click="alternarIndicadores()" :aria-expanded="(!indicadoresRecolhidos).toString()" aria-controls="central-indicadores"
                    class="rounded-control border border-line px-3 py-2 text-ink-mute hover:text-ink"
                    x-text="indicadoresRecolhidos ? 'Mostrar indicadores' : 'Recolher indicadores'"></button>
            <div id="central-indicadores" x-show="!indicadoresRecolhidos" class="ml-auto flex min-w-0 flex-wrap items-center justify-end gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-badge border px-2 py-1"
                  :class="maquina.online ? 'border-good-line bg-good-tint text-good' : 'border-crit bg-crit-tint text-crit'">
                <span class="h-1.5 w-1.5 rounded-full" :class="maquina.online ? 'bg-good' : 'bg-crit'"></span>
                <span x-text="maquina.online ? 'Máquina online' : 'Máquina fora do ar'"></span>
            </span>
            @foreach ($agentes as $chave => $nome)
                <span class="rounded-badge border px-2 py-1"
                      :class="consumo.limites?.esgotados?.['{{ $chave }}'] ? 'border-warn-line text-warn' : (maquina.agentes.includes('{{ $chave }}') ? 'border-line text-ink-dim' : 'border-line text-ink-faint line-through')"
                      :title="consumo.limites?.esgotados?.['{{ $chave }}'] || ''">{{ $nome }}<span x-show="consumo.limites?.esgotados?.['{{ $chave }}']"> · no limite</span></span>
            @endforeach
            <span class="rounded-badge border border-line px-2 py-1 text-ink-mute"
                  x-text="emExecucao + ' de ' + paralelo + ' rodando' + (naFila ? ' · ' + naFila + ' na fila' : '')"></span>
            <span class="rounded-badge border border-line px-2 py-1 text-ink-mute"
                  x-show="consumo.limites" x-text="(consumo.limites?.central.usados ?? 0) + (consumo.limites?.central.limite == null ? ' pedidos hoje · sem teto interno' : ' de ' + consumo.limites.central.limite + ' pedidos hoje')"></span>
            <span role="status" aria-live="polite" class="ml-auto rounded-badge border border-line px-2 py-1 text-ink-mute" x-text="rotuloConexao"></span>
            </div>
        </div>

        <div x-show="aviso" x-cloak role="alert" class="flex-none rounded-panel border border-warn-line bg-warn-tint px-4 py-2 text-sm text-ink">
            <span x-text="aviso"></span>
            <button type="button" @click="aviso = ''" x-show="!bloqueado" class="ml-2 font-semibold text-brand-text">Fechar</button>
            <a href="{{ route('login') }}" x-show="bloqueado" class="ml-2 font-semibold text-brand-text">Entrar de novo</a>
        </div>

        <div class="flex flex-none flex-wrap items-center justify-between gap-2 px-1 text-xs text-ink-mute">
            <span x-show="contaNome" x-text="'Conversa privada de ' + contaNome"></span>
            <div x-show="atendimento.disponivel" x-cloak class="flex flex-wrap items-center gap-2">
                <span x-text="atendimento.ativo === 'telegram' ? 'Atendimento no Telegram · ' + (atendimento.projeto || 'Geral') : 'Atendimento no AlfaMatriz'"></span>
                <button type="button" @click="transferirCanal(atendimento.ativo === 'telegram' ? 'web' : 'telegram')"
                        :disabled="transferindoCanal || aba.enviando || !!aba.pendente || !podeEnviar"
                        x-show="telegram.vinculado || atendimento.ativo === 'telegram'"
                        class="rounded-control border border-line px-3 py-2 font-semibold text-brand-text disabled:opacity-50"
                        x-text="transferindoCanal ? 'Transferindo…' : (atendimento.ativo === 'telegram' ? 'Retomar no AlfaMatriz' : 'Continuar pelo Telegram')"></button>
                <button type="button" x-show="!telegram.vinculado" @click="irPara('ajustes')" class="font-semibold text-brand-text">Vincular Telegram</button>
            </div>
        </div>

        {{-- ================= PROJETOS ================= --}}
        <div x-show="['chats', 'projetos'].includes(tela)" class="relative grid min-h-0 flex-1 grid-cols-1 gap-3"
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
                    <option value="ativos">Em andamento</option><option value="executando">Executando</option>
                    <option value="aguardando_voce">Aguardando você</option><option value="falhou">Com falha</option>
                    <option value="aberta">Abertos / na fila</option><option value="pausada">Pausados</option><option value="todos">Todos, incluindo pausados</option>
                </select>
                <div class="min-h-0 flex-1 space-y-2 overflow-y-auto">
                    <p x-show="!chatsFiltrados.length" class="p-2 text-xs text-ink-mute">Nenhum chat corresponde ao filtro. Inicie outro assunto pela aba Projetos.</p>
                    <template x-for="c in chatsFiltrados" :key="c.id">
                        <button type="button" @click="abrirChat(c)" :aria-pressed="(chatAtual === c.id).toString()"
                                class="w-full rounded-control border px-3 py-2 text-left hover:bg-chip"
                                :class="chatAtual === c.id ? 'border-brand-mute bg-nav-active' : 'border-line'">
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

            {{-- Projeto aberto --}}
            <main class="flex min-h-0 min-w-0 flex-col overflow-hidden rounded-panel border border-line bg-panel">
                <div class="flex flex-none flex-wrap items-center gap-x-3 gap-y-1 border-b border-line px-4 py-2">
                    <button type="button" @click="alternarProjetos()" :aria-expanded="(!projetosRecolhidos).toString()" :aria-controls="tela === 'chats' ? 'central-chats' : 'central-projetos'"
                            class="hidden lg:flex items-center gap-2 rounded-control border border-line px-2 py-1 text-xs text-ink-mute hover:text-ink"
                            :title="projetosRecolhidos ? 'Mostrar lista' : 'Recolher lista'">
                        <span class="h-4 w-4"><x-nav-icon name="panel-left" /></span>
                        <span x-text="projetosRecolhidos ? 'Mostrar lista' : 'Recolher lista'"></span>
                    </button>
                    <button type="button" @click="menuProjetosMovel = true" class="rounded-control border border-line px-2 py-1 text-xs text-ink-mute lg:hidden" x-text="tela === 'chats' ? 'Chats' : 'Projetos'"></button>
                    <h2 class="font-display text-base font-semibold text-ink" x-text="atual || 'Geral'"></h2>
                    <template x-if="atual">
                        <p class="flex min-w-0 flex-1 flex-wrap gap-x-3 text-xs text-ink-mute">
                            <span x-show="projetoAtual?.git?.branch" x-text="projetoAtual?.git?.branch"></span>
                            <span x-show="projetoAtual?.git?.git" :class="projetoAtual?.git?.pendentes ? 'text-warn' : ''" x-text="(projetoAtual?.git?.pendentes || 0) + ' pendentes'"></span>
                            <span x-show="projetoAtual?.git?.ultimo_commit" :title="projetoAtual?.git?.ultimo_commit_msg" x-text="'commit ' + haQuanto(projetoAtual?.git?.ultimo_commit)"></span>
                            <span x-show="projetoAtual?.git?.fase" x-text="projetoAtual?.git?.fase"></span>
                            <span x-show="projetoAtual?.git?.sessoes?.length" class="text-exame" x-text="'sessão aberta: ' + (projetoAtual?.git?.sessoes || []).join(', ')"></span>
                        </p>
                    </template>
                    <template x-if="atual">
                        <div class="ml-auto flex items-center gap-2 text-xs">
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

                <p x-show="chatAtual" class="flex-none border-b border-line px-4 py-2 text-sm font-semibold text-ink"
                   x-text="'#' + chatAtual + ' · ' + (chats.find(c => c.id === chatAtual)?.titulo || tarefaVista?.titulo || 'Chat da tarefa')"></p>
                <div class="flex flex-none gap-1 border-b border-line px-3 text-xs" x-show="atual">
                    <template x-for="[chave, rotulo] in [['conversa', 'Chat'], ['tarefas', 'Tarefas'], ['contexto', 'Contexto']]" :key="chave">
                        <button type="button" @click="sub = chave" class="-mb-px border-b-2 px-2.5 py-2 font-semibold"
                                :class="sub === chave ? 'border-brand text-ink' : 'border-transparent text-ink-mute hover:text-ink'">
                            <span x-text="rotulo"></span>
                            <span x-show="chave === 'tarefas' && tarefasEmCurso.length" class="ml-0.5 text-ink-faint" x-text="'(' + tarefasEmCurso.length + ')'"></span>
                            <span x-show="chave === 'contexto' && tarefaVista?.duvida" class="ml-0.5 inline-block h-1.5 w-1.5 rounded-full bg-pergunta"></span>
                        </button>
                    </template>
                </div>

                <div x-show="tela === 'chats' && !chatAtual" class="flex min-h-0 flex-1 flex-col items-center justify-center gap-3 p-6 text-center text-sm text-ink-mute">
                    <p>Selecione um chat para continuar o assunto com seu próprio histórico.</p>
                    <button type="button" @click="irPara('projetos')" class="font-semibold text-brand-text">Escolher projeto para iniciar outro assunto</button>
                </div>
                {{-- Conversa --}}
                <div class="flex min-h-0 flex-1 flex-col" x-show="(tela !== 'chats' || chatAtual) && (!atual || sub === 'conversa')">
                    <div class="flex flex-none items-center justify-between px-4 pt-2 text-xs text-ink-faint" x-show="verTarefa">
                        <span x-text="'Só a tarefa #' + verTarefa"></span>
                        <button type="button" @click="selecionarTarefa(null)" class="font-semibold text-brand-text">Ver o projeto todo</button>
                    </div>
                    <div class="flex flex-none flex-wrap items-center justify-between gap-2 px-4 py-2 text-xs" x-show="atual">
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
                            <h3 class="font-display text-base font-semibold text-ink" x-text="atual ? 'Nada ainda em ' + atual : 'Conversa geral'"></h3>
                            <p class="max-w-sm text-sm text-ink-mute" x-text="atual ? 'Escreva o pedido abaixo. Em automático, a Central escolhe o agente e mostra o porquê.' : 'Perguntas e pedidos de resumo, relatório ou agenda vão a um agente; o resto fica anotado.'"></p>
                        </div>
                        <div class="space-y-3">
                            <template x-for="m in mensagensVisiveis" :key="m.id">
                                <article class="min-w-0 rounded-control border px-4 py-2.5"
                                         :class="m.autor_tipo === 'usuario' ? 'border-line bg-input' : (m.autor_tipo === 'sistema' ? 'border-warn-line bg-warn-tint' : 'border-line bg-canvas')" data-mensagem>
                                    <div class="mb-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
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
                                    <p class="whitespace-pre-wrap break-words text-sm leading-relaxed text-ink" x-text="m.conteudo"></p>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <template x-for="a in m.anexos || []" :key="a.id">
                                            <a :href="a.url" class="text-xs font-semibold text-brand-text" x-text="'📎 ' + a.nome" download></a>
                                        </template>
                                    </div>
                                    <p class="mt-1 text-xs text-ink-faint" x-show="m.entrega" x-text="entrega(m.entrega)"></p>
                                </article>
                            </template>
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
                                <select id="central-tarefa" x-model="aba.tarefa" x-show="atual" :disabled="!!aba.pendente || !!chatAtual"
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
                                <select id="central-modo" x-model="aba.modo" x-show="atual" :disabled="!!aba.pendente || aba.agente === 'nota'"
                                        class="rounded-control border-line bg-input py-1 text-xs text-ink focus:border-brand focus:ring-brand">
                                    <option value="auto">Modo automático</option>
                                    @foreach ($modos as $chave => $nome)
                                        <option value="{{ $chave }}">{{ $nome }}</option>
                                    @endforeach
                                </select>
                                <span class="text-warn" x-show="aba.agente !== 'auto' && aba.agente !== 'nota' && consumo.limites?.esgotados?.[aba.agente]"
                                      x-text="'⚠ ' + (consumo.limites?.esgotados?.[aba.agente] || '')"></span>
                            </div>
                        @endif
                        <div class="flex flex-wrap items-center gap-2 text-xs">
                            <label class="cursor-pointer font-semibold text-brand-text">📎 Anexar
                                <input type="file" class="sr-only" multiple accept="image/jpeg,image/png,image/webp,application/pdf,video/mp4,video/webm,video/quicktime"
                                       :disabled="!!aba.pendente || bloqueado || !podeEnviar" @change="escolherArquivos($event.target.files); $event.target.value = ''">
                            </label>
                            <span class="text-ink-faint">Até 20 MB por arquivo · cole ou arraste uma captura</span>
                            <template x-for="(a, i) in aba.arquivos" :key="a.id">
                                <button type="button" :disabled="!!aba.pendente" @click="aba.arquivos.splice(i, 1)" class="text-ink-mute" :aria-label="'Remover ' + a.file.name" x-text="a.file.name + ' ×'"></button>
                            </template>
                            <span role="status" x-text="aba.progresso" class="text-brand-text"></span>
                        </div>
                        <div class="flex items-end gap-2">
                            <label for="central-rascunho" class="sr-only">Sua mensagem</label>
                            <textarea @paste="colar($event)" id="central-rascunho" x-model="aba.rascunho" maxlength="{{ config('central_ia.max_texto') }}" rows="2"
                                      :readonly="!!aba.pendente" :disabled="bloqueado || !podeEnviar"
                                      @keydown.enter="if (!$event.shiftKey && !$event.isComposing && !window.matchMedia('(pointer: coarse)').matches) { $event.preventDefault(); enviar(); }"
                                      :placeholder="atual ? 'Peça algo para ' + atual + '… (Enter envia, Shift+Enter quebra linha)' : 'Pergunte ou anote… (Enter envia)'"
                                      class="min-w-0 flex-1 resize-none rounded-control border-line bg-input text-sm text-ink placeholder-ink-faint focus:border-brand focus:ring-brand disabled:opacity-50"></textarea>
                            <button type="submit" :disabled="aba.enviando || bloqueado || !podeEnviar || (!aba.pendente && !aba.rascunho.trim() && !aba.arquivos.length)"
                                    class="flex-none rounded-control bg-brand px-4 py-2 text-sm font-semibold text-on-brand transition hover:bg-brand-bright disabled:opacity-50"
                                    x-text="aba.enviando ? 'Enviando…' : (aba.pendente ? 'Reenviar' : 'Enviar')"></button>
                        </div>
                    </form>
                </div>

                {{-- Tarefas --}}
                <div class="min-h-0 flex-1 overflow-y-auto p-3" x-show="atual && sub === 'tarefas'" x-cloak>
                    <p x-show="!tarefas.length" class="px-1 py-2 text-sm text-ink-faint">Nenhuma tarefa ainda. Um pedido na Conversa abre a primeira.</p>
                    <ul class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                        <template x-for="t in tarefas" :key="t.id">
                            <li>
                                <button type="button" @click="selecionarTarefa(t.id, true)" class="h-full w-full rounded-control border border-line px-3 py-2 text-left hover:bg-chip">
                                    <span class="flex items-center gap-1.5 text-[11px]">
                                        <span class="rounded-badge border px-1.5 py-0.5" :class="corDoEstado(t.status)" x-text="estado(t.status)"></span>
                                        <span class="text-ink-faint" x-text="'#' + t.id"></span>
                                        <span x-show="t.duvida" class="text-pergunta">● dúvida</span>
                                    </span>
                                    <span class="mt-1 block overflow-hidden text-ellipsis whitespace-nowrap text-sm text-ink" x-text="t.titulo"></span>
                                    <span class="mt-0.5 block overflow-hidden text-ellipsis whitespace-nowrap text-xs text-ink-faint"
                                          x-text="(t.agente || 'sem agente') + (t.branch ? ' · ' + t.branch : '') + ' · ' + haQuanto(t.ultima_atividade)"></span>
                                </button>
                                <button type="button" x-show="t.status === 'arquivada'" @click="mudarTarefa(t.id, 'aberta')" class="mt-1 text-xs font-semibold text-brand-text">Reabrir chat e liberar pedidos suspensos</button>
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
                <label class="flex items-center gap-1 text-ink-dim" x-show="vistaAtividade === 'log'">
                    <input type="checkbox" x-model="soProblemas" class="rounded border-line bg-input text-brand focus:ring-brand"> só problemas
                </label>
                <select x-model="projetoLog" x-show="vistaAtividade === 'log'" class="rounded-control border-line bg-input py-0.5 text-xs text-ink focus:border-brand focus:ring-brand">
                    <option value="">Todos os projetos</option>
                    <template x-for="p in projetosDoLog" :key="p"><option :value="p" x-text="p"></option></template>
                </select>
            </div>

            {{-- Log: quando, o quê, onde, qual agente, o que aconteceu --}}
            <ul x-show="vistaAtividade === 'log'" class="min-h-0 flex-1 divide-y divide-rule overflow-y-auto">
                <li x-show="!eventosFiltrados.length" class="px-4 py-3 text-sm text-ink-faint">Nenhum evento.</li>
                <template x-for="e in eventosFiltrados" :key="e.id">
                    <li class="grid grid-cols-[6.5rem_minmax(0,1fr)] gap-x-3 px-4 py-1.5 text-xs md:grid-cols-[6.5rem_7.5rem_9rem_8rem_minmax(0,1fr)]">
                        <time class="text-ink-faint" :datetime="e.em" x-text="data(e.em)"></time>
                        <span class="font-semibold" :class="corDoNivel(e.nivel)" x-text="rotuloEvento(e.tipo)"></span>
                        <button type="button" class="overflow-hidden text-ellipsis whitespace-nowrap text-left text-ink hover:text-brand-text" x-text="e.projeto || '—'"
                                @click="e.projeto && e.projeto !== 'Geral' ? abrir(e.projeto, e.tarefa_id) : abrir('')"></button>
                        <span class="text-ink-dim" x-text="(e.agente || '—') + (e.origem ? ' · ' + e.origem : '')"></span>
                        <span class="col-span-2 break-words md:col-span-1" :class="e.nivel === 'erro' ? 'text-crit' : 'text-ink-dim'" x-text="e.descricao"></span>
                    </li>
                </template>
            </ul>

            <ul x-show="vistaAtividade === 'execucoes'" class="min-h-0 flex-1 divide-y divide-rule overflow-y-auto">
                <li x-show="!atividade.length" class="px-4 py-3 text-sm text-ink-faint">Nenhum pedido a agente ainda.</li>
                <template x-for="a in atividade" :key="a.id">
                    <li>
                        <button type="button" @click="abrir(a.projeto, a.tarefa_id)" class="grid w-full grid-cols-[minmax(0,1fr)_auto] gap-x-3 px-4 py-2 text-left hover:bg-chip md:grid-cols-[10rem_minmax(0,1fr)_14rem_7rem]">
                            <span class="text-xs font-semibold text-ink" x-text="a.projeto || 'Geral'"></span>
                            <span class="order-3 col-span-2 overflow-hidden text-ellipsis whitespace-nowrap text-sm text-ink-dim md:order-none md:col-span-1" x-text="a.tarefa || ('pedido #' + a.id)"></span>
                            <span class="order-4 col-span-2 text-xs text-ink-faint md:order-none md:col-span-1"
                                  x-text="a.agente + ' · ' + a.modo + (a.handoff_de ? ' · assumiu de ' + a.handoff_de : '') + (duracao(a) ? ' · ' + duracao(a) : ' · ' + haQuanto(a.criada_em))"></span>
                            <span class="text-right text-xs font-semibold" :class="corDaExecucao(a.status)" x-text="execucao(a.status)"></span>
                        </button>
                    </li>
                </template>
            </ul>
        </div>

        {{-- ================= CONSUMO ================= --}}
        <div x-show="tela === 'consumo'" x-cloak class="min-h-0 flex-1 overflow-y-auto">
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
                    <p class="text-xs text-ink-mute">Requisições premium no mês</p>
                    <p class="mt-2 font-display text-2xl font-semibold text-ink"
                       x-text="(consumo.limites?.copilot?.premium_mes ?? '—') + (consumo.limites?.copilot?.franquia ? ' / ' + consumo.limites.copilot.franquia : '')"></p>
                    <template x-if="consumo.limites?.copilot?.franquia">
                        <div class="mt-1 h-1.5 rounded-full bg-bar-track"><div class="h-1.5 rounded-full" :class="corPct(100 * consumo.limites.copilot.premium_mes / consumo.limites.copilot.franquia)" :style="'width:' + Math.min(100, 100 * consumo.limites.copilot.premium_mes / consumo.limites.copilot.franquia) + '%'"></div></div>
                    </template>
                    <p class="mt-1 text-xs text-ink-faint" x-text="consumo.limites?.copilot?.franquia ? 'Restam ' + Math.max(0, consumo.limites.copilot.franquia - consumo.limites.copilot.premium_mes) + ' no mês' : 'Franquia do plano ainda não informada.'"></p>
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

        {{-- ================= AJUSTES ================= --}}
        <div x-show="tela === 'ajustes'" x-cloak class="grid min-h-0 flex-1 content-start gap-3 overflow-y-auto lg:grid-cols-2">
            <div class="rounded-panel border border-line bg-panel p-4 text-sm lg:col-span-2">
                <h3 class="font-semibold text-ink">Minhas IAs e regras de uso</h3>
                <p class="mt-1 text-ink-mute">Escolha manual vence o padrão. Automático usa regras locais e informa o motivo, sem gastar uma chamada ao GPT para decidir.</p>
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
                                    <label class="block text-ink-mute">Solicitações por dia<input type="number" min="0" max="10000" x-model.number="ajustes.pedidos_dia[agente]" placeholder="Sem teto interno" class="mt-1 w-full rounded-control border-line bg-input text-sm text-ink"></label>
                                </div>
                            </template>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="text-ink-mute">Limite total diário<input type="number" min="0" max="30000" x-model.number="ajustes.limite_total" placeholder="Sem teto interno" class="mt-1 w-full rounded-control border-line bg-input text-sm text-ink"></label>
                            <label class="text-ink-mute">Execuções simultâneas<input type="number" min="1" max="{{ max(1, (int) config('central_ia.agentes.paralelo')) }}" x-model.number="ajustes.paralelo" class="mt-1 w-full rounded-control border-line bg-input text-sm text-ink"></label>
                        </div>
                        <label class="flex gap-2 items-center"><input type="checkbox" x-model="ajustes.fallback" class="rounded border-line text-brand">Tentar outra IA habilitada quando o provedor estiver indisponível ou no limite</label>
                        <p class="text-xs text-ink-faint">Em branco: sem teto administrativo. Zero: bloqueia novos pedidos. Tentativas alternativas contam nos limites. As franquias reais continuam sendo as de cada assinatura. Nenhum adaptador de API paga foi adicionado; a autenticação do executor precisa de validação própria. Desabilitar suspende novos pedidos e a fila dessa IA, sem interromper processos já iniciados.</p>
                        @if ($podeParear)
                            <button type="button" @click="salvarAjustes()" :disabled="salvandoAjustes || !preferencias?.disponivel" class="rounded-control bg-brand px-3 py-2 font-semibold text-on-brand disabled:opacity-50">Salvar meus ajustes</button>
                        @endif
                    </fieldset>
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
                <p class="mt-2 text-xs text-ink-faint">Para pôr um projeto num grupo, abra o projeto e escolha o grupo no cabeçalho dele.</p>
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
        </div>
    </section>
</x-app-layout>
