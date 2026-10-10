/**
 * O cockpit da Central de IA: vários projetos à vista, a conversa e as
 * tarefas do que estiver aberto, e a atividade de todos.
 *
 * Cada projeto guarda o PRÓPRIO estado na página — mensagens já lidas,
 * rascunho, tarefa e agente escolhidos —, e alternar entre eles não perde
 * nada. Nada da conversa vai para o armazenamento do navegador: ela é
 * privada e a máquina pode ser de outra pessoa. Só o último projeto aberto
 * fica lá, por conveniência.
 *
 * O envio leva um `client_id` gerado AQUI e guardado em `pendente` até o
 * servidor confirmar — quem reenvia depois de um timeout reenvia o mesmo id e
 * o mesmo texto, e o servidor devolve o que já tinha entrado.
 */

/**
 * UUID v4 para o `client_id`.
 *
 * Não é `crypto.randomUUID()`: ele só existe em contexto seguro (HTTPS ou
 * localhost), e o Mac abre o sistema em http://home:8096 — o envio morria
 * ali, sem erro na tela. `getRandomValues` existe em qualquer contexto.
 */
function novoId() {
    const b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = [...b].map(x => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

const GERAL = '';

function guardar(chave, valor) { try { localStorage.setItem(chave, valor); } catch {} }
function ler(chave) { try { return localStorage.getItem(chave); } catch { return null; } }

export function centralIa(config) {
    return {
        // Painel (todos os projetos)
        projetos: [], atividade: [], maquina: { online: false, agentes: [], projetos: [] }, paralelo: 2,
        telegram: { vinculado: false, usuario: null }, pareamento: null,
        // Projeto aberto
        atual: GERAL, abas: {}, tarefas: [], verTarefa: null, detalheAberto: true,
        fixarEscolha: '', menuProjetosMovel: false,
        // Conexão
        bloqueado: false, aviso: '', conexao: 'conectando', falhas: 0, timer: null, encerrado: false, ocupado: false,
        podeEnviar: config.podeEnviar,

        // A aba é criada em `garantirAba()`, fora da renderização: criar dentro
        // do getter faria o Alpine escrever enquanto desenha.
        get aba() { return this.abas[this.atual] || this.abaVazia; },
        abaVazia: null,
        get mensagens() { return this.aba.mensagens; },
        get projetoAtual() { return this.projetos.find(p => p.nome === this.atual) || null; },
        get tarefaVista() { return this.tarefas.find(t => t.id === this.verTarefa) || null; },
        get tarefasEmCurso() { return this.tarefas.filter(t => !['concluida'].includes(t.status)); },
        get mensagensVisiveis() {
            return this.verTarefa ? this.aba.mensagens.filter(m => m.tarefa_id === this.verTarefa) : this.aba.mensagens;
        },
        get projetosParaFixar() {
            const vistos = new Set(this.projetos.map(p => p.nome));
            return this.maquina.projetos.filter(p => !vistos.has(p));
        },
        get rotuloConexao() {
            return { conectando: 'Conectando…', online: 'Atualizado', offline: 'Reconectando…', bloqueado: 'Acesso interrompido' }[this.conexao];
        },
        get emExecucao() { return this.atividade.filter(a => a.status === 'executando').length; },
        get naFila() { return this.atividade.filter(a => a.status === 'pendente').length; },

        garantirAba() { if (!this.abas[this.atual]) this.abas[this.atual] = this.novaAba(); },

        novaAba() {
            return { mensagens: [], carregado: false, haAnteriores: false, rascunho: '', pendente: null, enviando: false,
                tarefa: 'nova', agente: 'auto', modo: 'auto', verTarefa: null, scroll: null };
        },

        init() {
            const url = new URLSearchParams(location.search).get('projeto');
            this.abaVazia = this.novaAba();
            this.atual = url ?? ler('central-ia:projeto') ?? GERAL;
            this.garantirAba();
            this.aoVoltarRede = () => this.reconectar();
            this.aoPerderRede = () => { this.conexao = 'offline'; this.aviso = 'Sem conexão. Sua mensagem continua aqui para reenviar.'; };
            this.aoMostrar = () => { if (!document.hidden) this.reconectar(); };
            this.aoSair = e => {
                if (Object.values(this.abas).some(a => a.pendente || a.rascunho.trim())) { e.preventDefault(); e.returnValue = ''; }
            };
            window.addEventListener('online', this.aoVoltarRede);
            window.addEventListener('offline', this.aoPerderRede);
            document.addEventListener('visibilitychange', this.aoMostrar);
            window.addEventListener('beforeunload', this.aoSair);
            this.atualizar();
        },

        destroy() {
            this.encerrado = true; clearTimeout(this.timer);
            window.removeEventListener('online', this.aoVoltarRede);
            window.removeEventListener('offline', this.aoPerderRede);
            document.removeEventListener('visibilitychange', this.aoMostrar);
            window.removeEventListener('beforeunload', this.aoSair);
        },

        async pedir(url, method = 'GET', corpo = undefined) {
            const abortar = new AbortController();
            const prazo = setTimeout(() => abortar.abort(), 12000);
            try {
                const resposta = await fetch(url, {
                    method, credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: abortar.signal,
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: corpo === undefined ? undefined : JSON.stringify(corpo),
                });
                if (!resposta.ok) {
                    const erro = new Error('falhou'); erro.status = resposta.status;
                    // A recusa explica o motivo (projeto, limite do dia, tarefa ocupada).
                    if ([422, 403].includes(resposta.status)) { try { erro.detalhe = (await resposta.json()).message; } catch {} }
                    throw erro;
                }
                return await resposta.json();
            } finally { clearTimeout(prazo); }
        },

        falhou(erro) {
            this.falhas++;
            if ([401, 419].includes(erro.status) || (erro.status === 403 && !erro.detalhe)) {
                this.bloqueado = true; this.conexao = 'bloqueado'; this.abas = {}; this.garantirAba();
                this.aviso = 'Sua sessão ou permissão mudou. Entre novamente no AlfaMatriz para continuar.';
            } else if ([422, 403].includes(erro.status)) {
                this.aviso = erro.detalhe || 'Confira a mensagem: ela não pôde ser registrada.';
            } else {
                this.conexao = 'offline';
                this.aviso = erro.status === 429 ? 'Muitas requisições em pouco tempo. Aguarde um instante.'
                    : 'Não foi possível falar com o servidor. Sua mensagem continua aqui para reenviar com segurança.';
            }
        },

        url(base, params) {
            const q = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ''));
            return base + (q.toString() ? '?' + q : '');
        },

        juntar(aba, itens) {
            const porId = new Map(aba.mensagens.map(m => [m.id, m]));
            for (const item of itens) {
                porId.set(item.id, item);
                if (aba.pendente?.client_id === item.client_id) { aba.pendente = null; aba.rascunho = ''; }
            }
            aba.mensagens = [...porId.values()].sort((a, b) => a.id - b.id);
        },

        /** Um ciclo do poll: painel de todos + conversa e tarefas do aberto. */
        async atualizar() {
            if (this.ocupado || this.bloqueado || this.encerrado) return;
            clearTimeout(this.timer); this.ocupado = true;
            let alcancando = false;
            try {
                const painel = await this.pedir(config.painel);
                this.projetos = painel.projetos; this.atividade = painel.atividade; this.maquina = painel.maquina;
                this.paralelo = painel.paralelo; this.telegram = painel.telegram;
                if (this.telegram.vinculado) this.pareamento = null;

                const projeto = this.atual, aba = this.aba;
                const feed = this.$refs.feed;
                const noFim = !feed || feed.scrollHeight - feed.scrollTop - feed.clientHeight < 80;
                const ultima = aba.mensagens.at(-1)?.id;
                const r = await this.pedir(this.url(config.historico, { projeto, depois: ultima }));
                if (projeto !== this.atual) return;
                this.juntar(aba, r.mensagens);
                for (const e of r.recentes || []) {
                    const m = aba.mensagens.find(x => x.id === e.id);
                    if (m) { m.entrega = e.entrega; m.pedido = e.pedido; }
                }
                if (!ultima) aba.haAnteriores = r.ha_mais;
                alcancando = !!ultima && r.ha_mais;

                if (projeto) {
                    const t = await this.pedir(config.tarefas.replace('__P__', encodeURIComponent(projeto)));
                    if (projeto === this.atual) this.tarefas = t.tarefas;
                } else {
                    this.tarefas = [];
                }

                const primeira = !aba.carregado;
                aba.carregado = true;
                this.conexao = 'online'; if (this.aviso && !this.aviso.startsWith('Confira')) this.aviso = ''; this.falhas = 0;
                if (noFim || primeira) this.$nextTick(() => { const f = this.$refs.feed; if (f) f.scrollTop = f.scrollHeight; });
            } catch (erro) { this.falhou(erro); }
            finally {
                this.ocupado = false;
                if (!this.bloqueado && !this.encerrado) {
                    const espera = alcancando ? 50
                        : this.falhas ? Math.min(30000, 3000 * 2 ** Math.min(this.falhas, 4))
                        : document.hidden ? 15000 : 5000;
                    this.timer = setTimeout(() => this.atualizar(), espera);
                }
            }
        },

        async anteriores() {
            const aba = this.aba;
            if (this.ocupado || this.bloqueado || !aba.mensagens.length) return;
            clearTimeout(this.timer); this.ocupado = true;
            const feed = this.$refs.feed; const altura = feed.scrollHeight;
            try {
                const r = await this.pedir(this.url(config.historico, { projeto: this.atual, antes: aba.mensagens[0].id }));
                this.juntar(aba, r.mensagens); aba.haAnteriores = r.ha_mais;
                this.$nextTick(() => { feed.scrollTop += feed.scrollHeight - altura; });
            } catch (erro) { this.falhou(erro); }
            finally { this.ocupado = false; if (!this.bloqueado && !this.encerrado) this.timer = setTimeout(() => this.atualizar(), 5000); }
        },

        reconectar() { if (!this.ocupado && !this.bloqueado) { clearTimeout(this.timer); this.atualizar(); } },

        /** Alterna de projeto guardando o lugar de onde se saiu. */
        abrir(projeto, tarefa = null) {
            const feed = this.$refs.feed;
            if (feed) this.aba.scroll = feed.scrollTop;
            this.aba.verTarefa = this.verTarefa;
            this.atual = projeto ?? GERAL;
            this.garantirAba();
            this.verTarefa = tarefa ?? this.aba.verTarefa;
            if (tarefa) this.aba.tarefa = tarefa;
            this.tarefas = [];
            this.menuProjetosMovel = false;
            guardar('central-ia:projeto', this.atual);
            const q = new URLSearchParams(location.search);
            this.atual ? q.set('projeto', this.atual) : q.delete('projeto');
            history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : ''));
            this.$nextTick(() => {
                const f = this.$refs.feed;
                if (f) f.scrollTop = this.aba.scroll ?? f.scrollHeight;
            });
            this.reconectar();
        },

        selecionarTarefa(id) {
            this.verTarefa = this.verTarefa === id ? null : id;
            if (this.verTarefa) this.aba.tarefa = id;
            this.$nextTick(() => { const f = this.$refs.feed; if (f) f.scrollTop = f.scrollHeight; });
        },

        async enviar() {
            const aba = this.aba;
            if (aba.enviando || this.bloqueado || !this.podeEnviar || (!aba.pendente && !aba.rascunho.trim())) return;
            if (!aba.pendente) {
                aba.pendente = {
                    client_id: novoId(), conteudo: aba.rascunho, projeto: this.atual || null,
                    tarefa_id: this.atual && aba.tarefa !== 'nova' ? Number(aba.tarefa) : null,
                    agente: this.atual && aba.agente !== 'nota' ? aba.agente : null,
                    modo: aba.modo,
                };
            }
            aba.enviando = true;
            try {
                const r = await this.pedir(config.enviar, 'POST', aba.pendente);
                aba.pendente = null; aba.rascunho = ''; this.aviso = '';
                // Pedido que abriu tarefa nova: o compositor passa a seguir nela.
                if (r.tarefa_id && aba.tarefa === 'nova') { aba.tarefa = r.tarefa_id; this.verTarefa = null; }
                await this.atualizar();
            } catch (erro) {
                this.falhou(erro);
                // Recusa definitiva (projeto, limite): o texto volta ao rascunho
                // para corrigir, e o próximo envio ganha id novo.
                if ([422, 403].includes(erro.status)) { aba.rascunho = aba.pendente.conteudo; aba.pendente = null; }
            }
            finally { aba.enviando = false; }
        },

        async fixar(projeto) {
            if (!projeto) return;
            try { await this.pedir(config.fixar.replace('__P__', encodeURIComponent(projeto)), 'POST', {}); this.fixarEscolha = ''; this.reconectar(); }
            catch (erro) { this.falhou(erro); }
        },

        async mudarTarefa(id, status) {
            try { await this.pedir(config.statusTarefa.replace('__T__', id), 'POST', { status }); this.reconectar(); }
            catch (erro) { this.falhou(erro); }
        },

        async parearTelegram() {
            try { this.pareamento = await this.pedir(config.telegramCodigo, 'POST', {}); this.aviso = ''; }
            catch (erro) { this.falhou(erro); }
        },

        async desvincularTelegram() {
            if (!confirm('Desvincular o Telegram desta conversa?')) return;
            try { await this.pedir(config.telegramDesvincular, 'DELETE'); this.telegram = { vinculado: false, usuario: null }; }
            catch (erro) { this.falhou(erro); }
        },

        /* ---------- rótulos ---------- */

        iniciais(nome) {
            const partes = nome.split(/[-_.]/).filter(Boolean);
            return (partes.length > 1 ? partes[0][0] + partes[1][0] : nome.slice(0, 2)).toUpperCase();
        },
        estado(e) {
            return { executando: 'Executando', aguardando_voce: 'Aguardando você', falhou: 'Falhou', aberta: 'Na fila / aberta',
                pausada: 'Pausado', concluida: 'Concluída', ocioso: 'Sem tarefa' }[e] || e;
        },
        corDoEstado(e) {
            return { executando: 'text-brand-text border-brand-mute', aguardando_voce: 'text-pergunta border-pergunta-line bg-pergunta-tint',
                falhou: 'text-crit border-crit bg-crit-tint', concluida: 'text-good border-good-line bg-good-tint',
                aberta: 'text-ink-mute border-line', pausada: 'text-ink-faint border-line', ocioso: 'text-ink-faint border-line' }[e] || 'text-ink-mute border-line';
        },
        execucao(s) {
            return { pendente: 'na fila', executando: 'trabalhando…', concluida: 'concluído', falhou: 'falhou', expirada: 'sem resposta' }[s] || s;
        },
        corDaExecucao(s) {
            return { pendente: 'text-ink-mute', executando: 'text-brand-text', concluida: 'text-good', falhou: 'text-crit', expirada: 'text-crit' }[s] || 'text-ink-mute';
        },
        entrega(status) {
            return { pendente: 'Indo para o Telegram…', enviando: 'Indo para o Telegram…', entregue: 'Entregue no Telegram',
                incerta: 'Entrega no Telegram não confirmada', falhou: 'Não foi para o Telegram' }[status] || '';
        },
        autor(m) { return m.autor_nome || { usuario: 'Você', agente: 'Agente', sistema: 'Sistema' }[m.autor_tipo] || 'Sistema'; },
        canal(origem) { return { web: 'AlfaMatriz', telegram: 'Telegram', agente: 'Agente' }[origem] || 'Central'; },
        data(valor) { return valor ? new Date(valor).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : ''; },
        haQuanto(valor) {
            if (!valor) return '—';
            const s = Math.max(0, (Date.now() - new Date(valor)) / 1000);
            if (s < 60) return 'agora';
            if (s < 3600) return `há ${Math.round(s / 60)} min`;
            if (s < 86400) return `há ${Math.round(s / 3600)} h`;
            return `há ${Math.round(s / 86400)} d`;
        },
        duracao(a) {
            if (!a.iniciada_em) return '';
            const s = Math.round(((a.concluida_em ? new Date(a.concluida_em) : Date.now()) - new Date(a.iniciada_em)) / 1000);
            return s < 60 ? `${s} s` : `${Math.floor(s / 60)} min ${s % 60} s`;
        },
    };
}
