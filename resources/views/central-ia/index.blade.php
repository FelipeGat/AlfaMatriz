<x-app-layout>
    <x-slot name="titulo">Central de IA</x-slot>
    <x-slot name="contexto">Cockpit de projetos</x-slot>

    <section x-data="centralIa({
                painel: '{{ route('central-ia.painel') }}',
                historico: '{{ route('central-ia.mensagens') }}',
                enviar: '{{ route('central-ia.enviar') }}',
                tarefas: '{{ route('central-ia.tarefas', '__P__') }}',
                fixar: '{{ route('central-ia.fixar', '__P__') }}',
                statusTarefa: '{{ url('central-ia/tarefas/__T__/status') }}',
                continuar: '{{ route('central-ia.sessoes.continuar') }}',
                telegramCodigo: '{{ route('central-ia.telegram.codigo') }}',
                telegramDesvincular: '{{ route('central-ia.telegram.desvincular') }}',
                podeEnviar: {{ $podeEnviar ? 'true' : 'false' }},
             })"
             data-central-ia class="mx-auto max-w-[1600px] space-y-3">

        {{-- Faixa de estado: máquina, agentes, fila, Telegram --}}
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <span class="inline-flex items-center gap-1.5 rounded-badge border px-2 py-1"
                  :class="maquina.online ? 'border-good-line bg-good-tint text-good' : 'border-crit bg-crit-tint text-crit'">
                <span class="h-1.5 w-1.5 rounded-full" :class="maquina.online ? 'bg-good' : 'bg-crit'"></span>
                <span x-text="maquina.online ? 'Máquina online' : 'Máquina fora do ar — pedidos ficam na fila'"></span>
            </span>
            @foreach ($agentes as $chave => $nome)
                <span class="rounded-badge border border-line px-2 py-1"
                      :class="maquina.agentes.includes('{{ $chave }}') ? 'text-ink-dim' : 'text-ink-faint line-through'">{{ $nome }}</span>
            @endforeach
            <span class="rounded-badge border border-line px-2 py-1 text-ink-mute"
                  x-text="emExecucao + ' de ' + paralelo + ' rodando' + (naFila ? ' · ' + naFila + ' na fila' : '')"></span>
            <span class="rounded-badge border border-line px-2 py-1 text-ink-mute" x-text="telegram.vinculado ? 'Telegram vinculado' : 'Telegram não vinculado'"></span>
            <span role="status" aria-live="polite" class="ml-auto rounded-badge border border-line px-2 py-1 text-ink-mute" x-text="rotuloConexao"></span>
        </div>

        <div x-show="aviso" x-cloak role="alert" class="rounded-panel border border-warn-line bg-warn-tint px-4 py-2 text-sm text-ink">
            <span x-text="aviso"></span>
            <button type="button" @click="reconectar()" x-show="!bloqueado" class="ml-2 font-semibold text-brand-text">Tentar de novo</button>
            <a href="{{ route('login') }}" x-show="bloqueado" class="ml-2 font-semibold text-brand-text">Entrar de novo</a>
        </div>

        <div class="grid grid-cols-1 gap-3 lg:grid-cols-[250px_minmax(0,1fr)] 2xl:grid-cols-[250px_minmax(0,1fr)_300px]">

            {{-- ===== Projetos ===== --}}
            <aside aria-label="Projetos" class="min-w-0">
                <button type="button" @click="menuProjetosMovel = !menuProjetosMovel"
                        class="flex w-full items-center justify-between rounded-control border border-line bg-panel px-3 py-2 text-sm text-ink lg:hidden">
                    <span class="font-semibold" x-text="atual || 'Geral'"></span>
                    <span class="text-xs text-ink-mute" x-text="projetos.filter(p => p.atencao).length ? projetos.filter(p => p.atencao).length + ' pedem atenção' : 'Trocar projeto'"></span>
                </button>

                <nav class="mt-2 space-y-1.5 lg:mt-0 lg:!block" :class="menuProjetosMovel ? 'block' : 'hidden'">
                    <button type="button" @click="abrir('')"
                            class="flex w-full items-center gap-2 rounded-panel border px-3 py-2 text-left text-sm"
                            :class="atual === '' ? 'border-brand-mute bg-nav-active text-ink' : 'border-line bg-panel text-ink-dim hover:bg-chip'">
                        <span class="flex h-7 w-7 flex-none items-center justify-center rounded-tile bg-chip text-brand-text"><span class="h-4 w-4"><x-nav-icon name="chat" /></span></span>
                        <span class="min-w-0">
                            <span class="block font-semibold">Geral</span>
                            <span class="block text-xs text-ink-faint">Conversa fora de projeto</span>
                        </span>
                    </button>

                    <template x-for="p in projetos" :key="p.nome">
                        <button type="button" @click="abrir(p.nome)"
                                class="relative w-full rounded-panel border px-3 py-2 text-left"
                                :class="atual === p.nome ? 'border-brand-mute bg-nav-active' : 'border-line bg-panel hover:bg-chip'">
                            <span x-show="p.atencao" class="absolute right-2 top-2 h-2 w-2 rounded-full"
                                  :class="p.estado === 'falhou' ? 'bg-crit' : 'bg-pergunta'" :title="estado(p.estado)"></span>
                            <span class="flex items-center gap-2">
                                <span class="flex h-7 w-7 flex-none items-center justify-center rounded-tile bg-chip font-mono text-[11px] font-semibold text-ink-dim" x-text="iniciais(p.nome)"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block overflow-hidden text-ellipsis whitespace-nowrap text-sm font-semibold text-ink" x-text="p.nome"></span>
                                    <span class="block overflow-hidden text-ellipsis whitespace-nowrap text-xs text-ink-faint"
                                          x-text="(p.agente || 'sem agente') + ' · ' + haQuanto(p.ultima_atividade)"></span>
                                </span>
                            </span>
                            <span class="mt-1.5 flex flex-wrap items-center gap-1 text-[11px]">
                                <span class="rounded-badge border px-1.5 py-0.5" :class="corDoEstado(p.estado)" x-text="estado(p.estado)"></span>
                                <span x-show="p.tarefas_em_curso" class="rounded-badge border border-line px-1.5 py-0.5 text-ink-mute"
                                      x-text="p.tarefas_em_curso + (p.tarefas_em_curso > 1 ? ' tarefas' : ' tarefa')"></span>
                                <span x-show="p.git?.pendentes" class="rounded-badge border border-warn-line px-1.5 py-0.5 text-warn"
                                      title="Alterações não commitadas na pasta do projeto" x-text="p.git?.pendentes + ' pendentes'"></span>
                                <span x-show="p.git?.sessoes?.length" class="rounded-badge border border-exame-line px-1.5 py-0.5 text-exame"
                                      title="Sessão de agente aberta nesta pasta">sessão aberta</span>
                            </span>
                        </button>
                    </template>

                    <div class="rounded-panel border border-dashed border-line p-2" x-show="projetosParaFixar.length">
                        <label for="central-fixar" class="sr-only">Fixar projeto</label>
                        <div class="flex gap-1.5">
                            <select id="central-fixar" x-model="fixarEscolha" class="min-w-0 flex-1 rounded-control border-line bg-input py-1 text-xs text-ink focus:border-brand focus:ring-brand">
                                <option value="">Fixar projeto…</option>
                                <template x-for="p in projetosParaFixar" :key="p"><option :value="p" x-text="p"></option></template>
                            </select>
                            <button type="button" @click="fixar(fixarEscolha)" :disabled="!fixarEscolha"
                                    class="rounded-control border border-btn-line px-2 text-xs font-semibold text-ink disabled:opacity-50">Fixar</button>
                        </div>
                    </div>
                </nav>
            </aside>

            {{-- ===== Projeto aberto ===== --}}
            <main class="min-w-0 space-y-3">
                <div class="rounded-panel border border-line bg-panel px-4 py-3" x-show="atual" x-cloak>
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <h2 class="font-display text-lg font-semibold text-ink" x-text="atual"></h2>
                            <p class="mt-0.5 flex flex-wrap gap-x-3 gap-y-1 text-xs text-ink-mute">
                                <span x-show="projetoAtual?.git?.branch" x-text="'branch ' + projetoAtual?.git?.branch"></span>
                                <span x-show="projetoAtual?.git?.git" x-text="(projetoAtual?.git?.pendentes || 0) + ' alterações pendentes na pasta'"></span>
                                <span x-show="projetoAtual?.git?.ultimo_commit" :title="projetoAtual?.git?.ultimo_commit_msg" x-text="'último commit ' + haQuanto(projetoAtual?.git?.ultimo_commit)"></span>
                                <span x-show="projetoAtual?.git?.fase" x-text="'fase: ' + projetoAtual?.git?.fase"></span>
                                <span x-show="projetoAtual?.git?.atividade" x-text="'ritmo: ' + projetoAtual?.git?.atividade"></span>
                            </p>
                        </div>
                        <button type="button" @click="fixar(atual)" class="text-xs font-semibold text-brand-text"
                                x-text="projetoAtual?.fixado ? 'Desafixar' : 'Fixar no cockpit'"></button>
                    </div>
                    <div x-show="sessoesDoProjeto.length" class="mt-2 space-y-1">
                        <p class="font-mono text-[11px] uppercase tracking-caps text-ink-faint">Conversas deste projeto no VS Code</p>
                        <template x-for="s in sessoesDoProjeto.slice(0, 4)" :key="s.agente + s.id">
                            <div class="flex flex-wrap items-center justify-between gap-2 rounded-control border border-line px-3 py-1.5 text-xs">
                                <span class="text-ink-dim" x-text="s.nome + ' · ' + s.mensagens + ' mensagens suas · ' + haQuanto(s.ultima_atividade)"></span>
                                @if ($podeAcionar)
                                    <button type="button" @click="continuarSessao(s)" :disabled="s.ativa_agora"
                                            class="font-semibold text-brand-text disabled:text-ink-faint"
                                            :title="s.ativa_agora ? 'Em uso no VS Code agora — pare por lá antes' : 'Continua de onde parou, numa cópia da conversa'"
                                            x-text="s.ativa_agora ? 'em uso no VS Code' : 'Continuar daqui'"></button>
                                @endif
                            </div>
                        </template>
                    </div>
                    <p x-show="projetoAtual?.git?.sessoes?.length" class="mt-2 rounded-control border border-exame-line bg-exame-tint px-3 py-1.5 text-xs text-ink">
                        <span x-text="'Há sessão aberta de ' + (projetoAtual?.git?.sessoes || []).join(', ') + ' nesta pasta.'"></span>
                        Pedidos de implementar trabalham numa worktree separada e não mexem nela.
                    </p>
                </div>

                {{-- Tarefas do projeto --}}
                <div class="rounded-panel border border-line bg-panel" x-show="atual" x-cloak>
                    <div class="flex items-center justify-between border-b border-line px-4 py-2">
                        <h3 class="text-sm font-semibold text-ink">Tarefas</h3>
                        <button type="button" x-show="verTarefa" @click="selecionarTarefa(null)" class="text-xs font-semibold text-brand-text">Ver o projeto todo</button>
                    </div>
                    <p x-show="!tarefas.length" class="px-4 py-3 text-sm text-ink-faint">Nenhuma tarefa ainda. Escreva um pedido abaixo — ele abre a primeira.</p>
                    <ul class="flex gap-2 overflow-x-auto px-3 py-2" x-show="tarefas.length">
                        <template x-for="t in tarefas" :key="t.id">
                            <li class="w-64 flex-none">
                                <button type="button" @click="selecionarTarefa(t.id)"
                                        class="h-full w-full rounded-control border px-3 py-2 text-left"
                                        :class="verTarefa === t.id ? 'border-brand-mute bg-nav-active' : 'border-line hover:bg-chip'">
                                    <span class="flex items-center gap-1.5 text-[11px]">
                                        <span class="rounded-badge border px-1.5 py-0.5" :class="corDoEstado(t.status)" x-text="estado(t.status)"></span>
                                        <span class="text-ink-faint" x-text="'#' + t.id"></span>
                                    </span>
                                    <span class="mt-1 block overflow-hidden text-ellipsis whitespace-nowrap text-sm text-ink" x-text="t.titulo"></span>
                                    <span class="mt-0.5 block overflow-hidden text-ellipsis whitespace-nowrap text-xs text-ink-faint"
                                          x-text="(t.agente || 'sem agente') + (t.branch ? ' · ' + t.branch : '') + ' · ' + haQuanto(t.ultima_atividade)"></span>
                                </button>
                            </li>
                        </template>
                    </ul>

                    {{-- Dossiê da tarefa selecionada --}}
                    <template x-if="tarefaVista">
                        <div class="border-t border-line px-4 py-3 text-sm">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-semibold text-ink" x-text="tarefaVista.titulo"></p>
                                    <p class="mt-0.5 text-xs text-ink-mute"
                                       x-text="'Agente atual: ' + (tarefaVista.agente || '—') + (tarefaVista.agentes.length > 1 ? ' · já passaram: ' + tarefaVista.agentes.join(', ') : '')"></p>
                                    <p class="mt-0.5 text-xs text-ink-faint" x-show="tarefaVista.ultima_execucao?.motivo" x-text="tarefaVista.ultima_execucao?.motivo"></p>
                                </div>
                                <div class="flex gap-3 text-xs">
                                    <button type="button" @click="detalheAberto = !detalheAberto" class="font-semibold text-brand-text" x-text="detalheAberto ? 'Recolher' : 'Ver contexto'"></button>
                                    <button type="button" x-show="tarefaVista.status !== 'concluida'" @click="mudarTarefa(tarefaVista.id, 'concluida')" class="font-semibold text-good">Concluir</button>
                                    <button type="button" x-show="!['pausada','concluida'].includes(tarefaVista.status)" @click="mudarTarefa(tarefaVista.id, 'pausada')" class="font-semibold text-ink-mute">Pausar</button>
                                    <button type="button" x-show="['pausada','concluida','falhou'].includes(tarefaVista.status)" @click="mudarTarefa(tarefaVista.id, 'aberta')" class="font-semibold text-ink-mute">Reabrir</button>
                                </div>
                            </div>

                            <p x-show="tarefaVista.duvida" class="mt-2 rounded-control border border-pergunta-line bg-pergunta-tint px-3 py-2 text-ink">
                                <span class="font-semibold text-pergunta">O agente precisa de você: </span><span x-text="tarefaVista.duvida"></span>
                            </p>

                            <div x-show="detalheAberto" class="mt-3 grid gap-3 text-xs md:grid-cols-2">
                                <div class="space-y-1">
                                    <p class="font-mono uppercase tracking-caps text-ink-faint">Git</p>
                                    <p class="text-ink-dim" x-text="tarefaVista.branch ? 'Branch ' + tarefaVista.branch : 'Sem worktree (só análises até agora)'"></p>
                                    <p class="break-all text-ink-faint" x-show="tarefaVista.worktree" x-text="tarefaVista.worktree"></p>
                                    <p class="text-ink-faint" x-show="tarefaVista.commit_base" x-text="'Base ' + (tarefaVista.commit_base || '').slice(0, 10) + (tarefaVista.git?.head ? ' · HEAD ' + tarefaVista.git.head : '')"></p>
                                    <p class="text-ink-dim" x-show="tarefaVista.git?.arquivos?.length" x-text="(tarefaVista.git?.arquivos || []).length + ' arquivo(s) alterado(s): ' + (tarefaVista.git?.arquivos || []).slice(0, 8).join(', ')"></p>
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

                {{-- Conversa --}}
                <div class="overflow-hidden rounded-panel border border-line bg-panel">
                    <div class="flex items-center gap-2 border-b border-line px-4 py-2 text-sm font-semibold text-ink">
                        <span class="h-4 w-4 text-brand-text"><x-nav-icon name="chat" /></span>
                        <span x-text="verTarefa ? 'Conversa da tarefa #' + verTarefa : (atual ? 'Conversa de ' + atual : 'Conversa geral')"></span>
                    </div>

                    <div x-ref="feed" class="h-[55vh] min-h-[320px] overflow-y-auto overscroll-contain p-4" role="log" aria-label="Histórico da conversa" aria-live="polite" aria-relevant="additions">
                        <div class="mb-4 text-center" x-show="aba.haAnteriores && !verTarefa">
                            <button type="button" @click="anteriores()" :disabled="ocupado || bloqueado" class="text-xs font-semibold text-brand-text disabled:opacity-50">Carregar mensagens anteriores</button>
                        </div>
                        <p x-show="!aba.carregado" class="py-6 text-center text-sm text-ink-faint">Carregando…</p>
                        <div x-show="aba.carregado && !mensagensVisiveis.length && !bloqueado" class="flex h-full flex-col items-center justify-center gap-2 text-center">
                            <h3 class="font-display text-base font-semibold text-ink" x-text="atual ? 'Nada ainda em ' + atual : 'Sua conversa geral'"></h3>
                            <p class="max-w-sm text-sm text-ink-mute" x-text="atual ? 'Escreva o pedido abaixo. Em automático, a Central escolhe o agente e mostra o porquê.' : 'Ideias e decisões fora de projeto. Para trabalhar num projeto, escolha-o à esquerda.'"></p>
                        </div>
                        <div class="space-y-3">
                            <template x-for="m in mensagensVisiveis" :key="m.id">
                                <article class="min-w-0 rounded-control border px-4 py-3"
                                         :class="m.autor_tipo === 'usuario' ? 'border-line bg-input' : (m.autor_tipo === 'sistema' ? 'border-warn-line bg-warn-tint' : 'border-line bg-canvas')" data-mensagem>
                                    <div class="mb-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                        <span class="font-semibold text-ink" x-text="autor(m)"></span>
                                        <span class="text-ink-faint" x-show="m.origem !== 'agente'" x-text="canal(m.origem)"></span>
                                        <button type="button" class="text-ink-faint hover:text-brand-text" x-show="m.tarefa_id && !verTarefa" @click="selecionarTarefa(m.tarefa_id)" x-text="'tarefa #' + m.tarefa_id"></button>
                                        <time class="text-ink-faint" :datetime="m.criada_em" x-text="data(m.criada_em)"></time>
                                    </div>
                                    <p class="mb-1.5 text-xs" x-show="m.pedido">
                                        <span class="font-semibold" :class="corDaExecucao(m.pedido?.status)"
                                              x-text="m.pedido && ('Para ' + m.pedido.agente + ' · ' + m.pedido.modo + ' — ' + execucao(m.pedido.status))"></span>
                                        <span class="text-ink-faint" x-show="m.pedido?.handoff_de" x-text="' · assumiu de ' + m.pedido?.handoff_de"></span>
                                        <span class="block text-ink-faint" x-text="m.pedido?.motivo"></span>
                                    </p>
                                    <p class="whitespace-pre-wrap break-words text-sm leading-relaxed text-ink" x-text="m.conteudo"></p>
                                    <p class="mt-1.5 text-xs text-ink-faint" x-show="m.entrega" x-text="entrega(m.entrega)"></p>
                                </article>
                            </template>
                            <article x-show="aba.pendente" x-cloak class="rounded-control border border-line bg-input px-4 py-3" data-pendente>
                                <p class="mb-1 text-xs font-semibold text-ink-mute">Você · aguardando confirmação</p>
                                <p class="whitespace-pre-wrap break-words text-sm text-ink" x-text="aba.pendente?.conteudo"></p>
                            </article>
                        </div>
                    </div>

                    {{-- Compositor --}}
                    <form @submit.prevent="enviar()" class="space-y-2 border-t border-line bg-panel p-3" aria-label="Enviar mensagem">
                        @if ($podeAcionar)
                            <div class="flex flex-wrap items-center gap-2 text-xs" x-show="atual" x-cloak>
                                <label class="sr-only" for="central-tarefa">Tarefa</label>
                                <select id="central-tarefa" x-model="aba.tarefa" :disabled="!!aba.pendente"
                                        class="max-w-[16rem] rounded-control border-line bg-input py-1.5 text-xs text-ink focus:border-brand focus:ring-brand">
                                    <option value="nova">Nova tarefa</option>
                                    <template x-for="t in tarefasEmCurso" :key="t.id"><option :value="t.id" x-text="'#' + t.id + ' ' + t.titulo"></option></template>
                                </select>
                                <label class="sr-only" for="central-agente">Agente</label>
                                <select id="central-agente" x-model="aba.agente" :disabled="!!aba.pendente"
                                        class="rounded-control border-line bg-input py-1.5 text-xs text-ink focus:border-brand focus:ring-brand">
                                    <option value="auto">IA automática</option>
                                    @foreach ($agentes as $chave => $nome)
                                        <option value="{{ $chave }}" :disabled="!maquina.agentes.includes('{{ $chave }}')">{{ $nome }}</option>
                                    @endforeach
                                    <option value="nota">Só anotar (sem agente)</option>
                                </select>
                                <label class="sr-only" for="central-modo">Modo</label>
                                <select id="central-modo" x-model="aba.modo" :disabled="!!aba.pendente || aba.agente === 'nota'"
                                        class="rounded-control border-line bg-input py-1.5 text-xs text-ink focus:border-brand focus:ring-brand">
                                    <option value="auto">Modo automático</option>
                                    @foreach ($modos as $chave => $nome)
                                        <option value="{{ $chave }}">{{ $nome }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <label for="central-rascunho" class="sr-only">Sua mensagem</label>
                        <textarea id="central-rascunho" x-model="aba.rascunho" maxlength="{{ config('central_ia.max_texto') }}" rows="3"
                                  :readonly="!!aba.pendente" :disabled="bloqueado || !podeEnviar"
                                  @keydown.enter="if (!$event.shiftKey && !$event.isComposing && !window.matchMedia('(pointer: coarse)').matches) { $event.preventDefault(); enviar(); }"
                                  :placeholder="atual ? 'Peça algo para ' + atual + '…' : 'Escreva na conversa geral… (ou /auto projeto pedido)'"
                                  class="w-full resize-y rounded-control border-line bg-input text-sm text-ink placeholder-ink-faint focus:border-brand focus:ring-brand disabled:opacity-50"></textarea>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-xs text-ink-faint">Enter envia · Shift + Enter quebra linha. Trocar o agente numa tarefa passa o contexto dela ao novo, que confere o estado antes de alterar.</p>
                            <button type="submit" :disabled="aba.enviando || bloqueado || !podeEnviar || (!aba.pendente && !aba.rascunho.trim())"
                                    class="rounded-control bg-brand px-4 py-2 text-sm font-semibold text-on-brand transition hover:bg-brand-bright disabled:opacity-50"
                                    x-text="aba.enviando ? 'Enviando…' : (aba.pendente ? 'Reenviar com segurança' : 'Enviar')"></button>
                        </div>
                    </form>
                </div>
            </main>

            {{-- ===== Atividade de todos os projetos ===== --}}
            <aside aria-label="Atividade" class="min-w-0 space-y-3 lg:col-span-2 2xl:col-span-1">
                <div class="rounded-panel border border-line bg-panel">
                    <h3 class="border-b border-line px-4 py-2 text-sm font-semibold text-ink">Atividade de todos os projetos</h3>
                    <p x-show="!atividade.length" class="px-4 py-3 text-sm text-ink-faint">Nenhum pedido a agente ainda.</p>
                    <ul class="max-h-[60vh] divide-y divide-rule overflow-y-auto">
                        <template x-for="a in atividade" :key="a.id">
                            <li>
                                <button type="button" @click="abrir(a.projeto, a.tarefa_id)" class="w-full px-4 py-2 text-left hover:bg-chip">
                                    <span class="flex items-center justify-between gap-2 text-xs">
                                        <span class="font-semibold text-ink" x-text="a.projeto"></span>
                                        <span class="font-semibold" :class="corDaExecucao(a.status)" x-text="execucao(a.status)"></span>
                                    </span>
                                    <span class="mt-0.5 block overflow-hidden text-ellipsis whitespace-nowrap text-xs text-ink-dim" x-text="a.tarefa || ('pedido #' + a.id)"></span>
                                    <span class="mt-0.5 block text-[11px] text-ink-faint"
                                          x-text="a.agente + ' · ' + a.modo + (a.handoff_de ? ' · assumiu de ' + a.handoff_de : '') + (duracao(a) ? ' · ' + duracao(a) : ' · ' + haQuanto(a.criada_em))"></span>
                                </button>
                            </li>
                        </template>
                    </ul>
                </div>

                {{-- Consumo das três IAs, lido dos registros locais de cada uma --}}
                <div class="rounded-panel border border-line bg-panel">
                    <div class="flex items-center justify-between border-b border-line px-4 py-2">
                        <h3 class="text-sm font-semibold text-ink">Consumo de IA</h3>
                        <div class="flex gap-2 text-xs">
                            <button type="button" @click="periodo = 'hoje'" :class="periodo === 'hoje' ? 'font-semibold text-brand-text' : 'text-ink-mute'">Hoje</button>
                            <button type="button" @click="periodo = 'semana'" :class="periodo === 'semana' ? 'font-semibold text-brand-text' : 'text-ink-mute'">7 dias</button>
                        </div>
                    </div>
                    <div class="space-y-2 px-4 py-3 text-xs">
                        <template x-for="a in consumo.por_agente" :key="a.agente">
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="font-semibold text-ink" x-text="a.nome"></span>
                                <span class="text-right text-ink-dim">
                                    <span x-text="tokens(a[periodo].entrada + a[periodo].saida) + ' tokens'"></span>
                                    <span class="text-ink-faint" x-text="' · ' + tokens(a[periodo].cache_leitura) + ' em cache'"></span>
                                    <span class="block text-ink-faint" x-show="a.agente === 'copilot'" x-text="a[periodo].premium + ' requisições premium'"></span>
                                </span>
                            </div>
                        </template>
                        <template x-if="consumo.cota_codex">
                            <div class="space-y-1 border-t border-rule pt-2">
                                <p class="text-ink-mute">Cota do Codex (ChatGPT)</p>
                                <template x-for="[rotulo, j] in [['janela de 5 h', consumo.cota_codex.primaria], ['semana', consumo.cota_codex.semanal]]" :key="rotulo">
                                    <div x-show="j">
                                        <div class="flex justify-between text-ink-faint"><span x-text="rotulo"></span><span x-text="(j?.usado_pct ?? 0) + '% usado'"></span></div>
                                        <div class="mt-0.5 h-1.5 rounded-full bg-bar-track"><div class="h-1.5 rounded-full" :class="(j?.usado_pct ?? 0) >= 80 ? 'bg-crit' : ((j?.usado_pct ?? 0) >= 50 ? 'bg-warn' : 'bg-good')" :style="'width:' + Math.min(100, j?.usado_pct ?? 0) + '%'"></div></div>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <div x-show="consumo.projetos_hoje.length" class="space-y-0.5 border-t border-rule pt-2">
                            <p class="text-ink-mute">Projetos que mais gastaram hoje</p>
                            <template x-for="p in consumo.projetos_hoje" :key="p.projeto">
                                <div class="flex justify-between text-ink-faint"><span x-text="p.projeto"></span><span x-text="tokens(p.tokens)"></span></div>
                            </template>
                        </div>
                        <p class="text-[11px] text-ink-faint">Tokens de entrada e saída, sem o cache. Medido dos registros das IAs nesta máquina.</p>
                    </div>
                </div>

                {{-- Últimos projetos mexidos no git --}}
                <div class="rounded-panel border border-line bg-panel">
                    <h3 class="border-b border-line px-4 py-2 text-sm font-semibold text-ink">Recentes no git</h3>
                    <ul class="divide-y divide-rule">
                        <template x-for="r in recentes" :key="r.nome">
                            <li class="flex items-center justify-between gap-2 px-4 py-1.5 text-xs">
                                <button type="button" @click="abrir(r.nome)" class="min-w-0 text-left">
                                    <span class="block font-semibold text-ink" x-text="r.nome"></span>
                                    <span class="block overflow-hidden text-ellipsis whitespace-nowrap text-ink-faint" :title="r.ultimo_commit_msg"
                                          x-text="haQuanto(r.ultimo_commit) + ' · ' + (r.branch || '?') + (r.pendentes ? ' · ' + r.pendentes + ' pendentes' : '') + (r.fase ? ' · ' + r.fase : '')"></span>
                                </button>
                                <button type="button" x-show="!r.no_cockpit" @click="fixar(r.nome)" class="flex-none font-semibold text-brand-text">Fixar</button>
                            </li>
                        </template>
                    </ul>
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
                    <p class="mt-2 text-xs text-ink-faint">No Telegram: <code class="font-mono">/auto projeto pedido</code>, <code class="font-mono">/claude</code>, <code class="font-mono">/gpt</code>, <code class="font-mono">/copilot</code> — com <code class="font-mono">!</code> para implementar. <code class="font-mono">/continuar gestor</code> segue a conversa do VS Code.</p>
                </div>
            </aside>
        </div>
    </section>
</x-app-layout>
