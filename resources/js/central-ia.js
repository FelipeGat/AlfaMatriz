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
const diaLocal = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Sao_Paulo', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());

function guardar(chave, valor) { try { localStorage.setItem(chave, valor); } catch {} }
function ler(chave) { try { return localStorage.getItem(chave); } catch { return null; } }

export function centralIa(config) {
    return {
        // Painel (todos os projetos)
        inicioAtividade: diaLocal(), fimAtividade: diaLocal(), inicioConsumo: diaLocal(), fimConsumo: diaLocal(), usuarioConsumo: '', iaConsumo: '',
        relatorioAtividade: { eventos: [], execucoes: [] }, relatorioConsumo: { consumo: [] }, modoProjetos: ler('central-ia:modo-projetos') || 'cards',
        chats: [], buscaChats: '', filtroChatProjeto: '', filtroChatEstado: 'ativos', chatAtual: null,
        preferencias: null, ajustes: null, ajustesAlterados: false, salvandoAjustes: false, arquivando: false,
        administracao: { permitida: false, usuarios: [] }, usuarioAtividade: '', resumoUsuario: null,
        politicaUsuario: null, politicaAlterada: false, salvandoPolitica: false, carregandoUsuario: false,
        projetos: [], atividade: [], maquina: { online: false, agentes: [], projetos: [] }, paralelo: 2,
        recentes: [], consumo: { por_agente: [], projetos_hoje: [], cota_codex: null, serie: [], limites: null }, sessoes: [], periodo: 'hoje',
        grupos: [], busca: '', recolhidos: [], tela: 'projetos', sub: 'conversa',
        eventos: [], vistaAtividade: 'log', soProblemas: false, projetoLog: '', barraAberta: false, barraHover: null, mostrarTabela: false,
        telegram: { vinculado: false, usuario: null }, pareamento: null, contaNome: '', escolherConversaInicial: false,
        atendimento: { disponivel: false, ativo: 'web', projeto: null, tarefa_id: null }, transferindoCanal: false,
        // Projeto aberto
        atual: GERAL, abas: {}, tarefas: [], verTarefa: null, detalheAberto: true,
        historicosAbertos: {}, fixarEscolha: '', menuProjetosMovel: false,
        projetosRecolhidos: ler('central-ia:projetos-recolhidos') === 'sim',
        indicadoresRecolhidos: ler('central-ia:indicadores-recolhidos') === 'sim',
        alternarProjetos() {
            this.projetosRecolhidos = !this.projetosRecolhidos;
            guardar('central-ia:projetos-recolhidos', this.projetosRecolhidos ? 'sim' : 'nao');
        },
        alternarIndicadores() {
            this.indicadoresRecolhidos = !this.indicadoresRecolhidos;
            guardar('central-ia:indicadores-recolhidos', this.indicadoresRecolhidos ? 'sim' : 'nao');
        },

        // Conexão
        bloqueado: false, aviso: '', conexao: 'conectando', falhas: 0, timer: null, encerrado: false, ocupado: false,
        podeEnviar: config.podeEnviar, agora: Date.now(), relogio: null,

        // A aba é criada em `garantirAba()`, fora da renderização: criar dentro
        // do getter faria o Alpine escrever enquanto desenha.
        get chaveAba() { return this.chatAtual ? 'chat:' + JSON.stringify([this.atual, this.chatAtual]) : this.atual; },
        get aba() { return this.abas[this.chaveAba] || this.abaVazia; },
        abaVazia: null,
        get mensagens() { return this.aba.mensagens; },
        get projetoAtual() { return this.projetos.find(p => p.nome === this.atual) || null; },
        get tarefaVista() { return this.tarefas.find(t => t.id === this.verTarefa) || null; },
        get tarefasEmCurso() { return this.tarefas.filter(t => !['concluida'].includes(t.status)); },
        get mensagensVisiveis() {
            return this.aba.mensagens.filter(m => {
                if (this.verTarefa && m.tarefa_id !== this.verTarefa) return false;
                const ponto = this.tarefas.find(t => t.id === m.tarefa_id)?.ponto;
                return !ponto?.mensagem_id || this.historicosAbertos[m.tarefa_id] || m.id >= ponto.mensagem_id;
            });
        },
        get pedidosAtivos() {
            return this.atividade.filter(a => a.projeto === this.atual && ['pendente', 'executando'].includes(a.status)
                && (!this.verTarefa || a.tarefa_id === this.verTarefa));
        },
        andamento(a) {
            const segundos = Math.max(0, Math.floor((this.agora - new Date(a.iniciada_em || a.criada_em)) / 1000));
            const tempo = segundos < 60 ? segundos + ' s' : Math.floor(segundos / 60) + ' min ' + segundos % 60 + ' s';
            const etapas = { preparando: 'Preparando a execução', anexos: 'Preparando os anexos', agente: 'Aguardando resposta do agente',
                comando: 'Executando uma ferramenta ou comando', resposta: 'Recebendo saída do agente', finalizando: 'Registrando o resultado' };
            const recente = a.progresso_em && this.agora - new Date(a.progresso_em).getTime() < 90000;
            const estado = a.status === 'pendente'
                ? (this.maquina.online ? 'Na fila — aguardando o agente' : 'Máquina desconectada — aguardando reconexão')
                : !recente ? 'Sem confirmação recente desta execução'
                : etapas[a.progresso?.etapa] || 'Aguardando informação do runner';
            return a.agente + ' · ' + estado + ' · ' + tempo;
        },
        escolherArquivos(lista) {
            if (this.aba.pendente) return;
            for (const file of lista) {
                if (this.aba.arquivos.length >= 5) { this.aviso = 'Envie até cinco arquivos por mensagem.'; break; }
                if (file.size > 20 * 1024 * 1024 || !['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'video/mp4', 'video/webm', 'video/quicktime'].includes(file.type)) {
                    this.aviso = 'Use JPG, PNG, WebP, PDF, MP4, WebM ou MOV de até 20 MB.'; continue;
                }
                this.aba.arquivos.push({ file, id: novoId(), enviado: false });
            }
        },
        colar(event) {
            const arquivos = [...(event.clipboardData?.items || [])].filter(i => i.kind === 'file').map(i => i.getAsFile()).filter(Boolean);
            if (arquivos.length) { event.preventDefault(); this.escolherArquivos(arquivos); }
        },
        get projetosParaFixar() {
            const vistos = new Set(this.projetos.map(p => p.nome));
            return this.maquina.projetos.filter(p => !vistos.has(p));
        },
        get rotuloConexao() {
            return { conectando: 'Conectando…', online: 'Atualizado', offline: 'Reconectando…', bloqueado: 'Acesso interrompido' }[this.conexao];
        },
        get eventosFiltrados() {
            return this.eventos.filter(e => (!this.soProblemas || e.nivel !== 'info') && (!this.projetoLog || e.projeto === this.projetoLog));
        },
        get projetosDoLog() { return [...new Set(this.relatorioAtividade.eventos.map(e => e.projeto).filter(Boolean))].sort(); },
        corDoNivel(n) { return { erro: 'text-crit', aviso: 'text-warn' }[n] || 'text-ink-faint'; },
        rotuloEvento(t) {
            return { pedido: 'pedido', inicio: 'início', concluida: 'concluído', falhou: 'FALHOU', expirada: 'SEM RESPOSTA', troca_de_agente: 'troca de agente',
                recusado: 'recusado', sessao_continuada: 'conversa do VS Code', ponto_salvo: 'ponto salvo' }[t] || t;
        },

        /** A barra de projetos agrupada, já filtrada pela busca. */
        get secoes() {
            const termo = this.busca.trim().toLowerCase();
            const casa = p => !termo || p.nome.toLowerCase().includes(termo);
            const lista = this.projetos.filter(casa);
            const secoes = this.grupos.map(g => ({ id: g.id, nome: g.nome, projetos: lista.filter(p => p.grupo_id === g.id) }));
            secoes.push({ id: 0, nome: 'Sem grupo', projetos: lista.filter(p => !p.grupo_id || !this.grupos.some(g => g.id === p.grupo_id)) });
            return secoes.filter(sec => sec.projetos.length || (!termo && sec.id !== 0));
        },
        /** Na busca, os projetos da máquina que ainda não estão no cockpit. */
        get achadosNaMaquina() {
            const termo = this.busca.trim().toLowerCase();
            if (!termo) return [];
            const vistos = new Set(this.projetos.map(p => p.nome));
            return this.maquina.projetos.filter(p => !vistos.has(p) && p.toLowerCase().includes(termo)).slice(0, 12);
        },
        recolher(id) {
            this.recolhidos = this.recolhidos.includes(id) ? this.recolhidos.filter(x => x !== id) : [...this.recolhidos, id];
            guardar('central-ia:recolhidos', JSON.stringify(this.recolhidos));
        },
        irPara(tela) { this.tela = tela; if (['atividade', 'consumo'].includes(tela)) this.carregarRelatorio(tela); guardar('central-ia:tela', tela); if (tela === 'projetos') this.$nextTick(() => { const f = this.$refs.feed; if (f) f.scrollTop = f.scrollHeight; }); },

        async criarGrupo() {
            const nome = prompt('Nome do grupo (ex.: VixCard, Grupo Soluções, Pessoal):');
            if (!nome?.trim()) return;
            try { await this.pedir(config.grupos, 'POST', { nome: nome.trim() }); this.reconectar(); } catch (erro) { this.falhou(erro); }
        },
        async renomearGrupo(g) {
            const nome = prompt('Novo nome do grupo:', g.nome);
            if (!nome?.trim() || nome.trim() === g.nome) return;
            try { await this.pedir(config.grupo.replace('__G__', g.id), 'PUT', { nome: nome.trim() }); this.reconectar(); } catch (erro) { this.falhou(erro); }
        },
        async apagarGrupo(g) {
            if (!confirm(`Apagar o grupo "${g.nome}"? Os projetos dele ficam sem grupo.`)) return;
            try { await this.pedir(config.grupo.replace('__G__', g.id), 'DELETE'); this.reconectar(); } catch (erro) { this.falhou(erro); }
        },
        async moverParaGrupo(projeto, grupoId) {
            if (grupoId === 'novo') {
                const nome = prompt('Nome do novo grupo:');
                if (!nome?.trim()) return;
                try { grupoId = (await this.pedir(config.grupos, 'POST', { nome: nome.trim() })).id; } catch (erro) { this.falhou(erro); return; }
            }
            try {
                await this.pedir(config.grupoDoProjeto.replace('__P__', encodeURIComponent(projeto)), 'POST', { grupo_id: grupoId ? Number(grupoId) : null });
                this.reconectar();
            } catch (erro) { this.falhou(erro); }
        },

        /* ---------- gráfico de consumo ---------- */
        get maxSerie() {
            return Math.max(1, ...this.consumo.serie.map(d => d.claude + d.codex + d.copilot));
        },
        // Escala com 15% de folga: a barra mais alta não encosta no teto.
        altura(v) { return (v / (this.maxSerie * 1.15)) * 180; },
        diaCurto(d) { return new Date(d + 'T12:00:00').toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit' }); },
        corPct(p) { return p >= 90 ? 'bg-crit' : (p >= 70 ? 'bg-warn' : 'bg-good'); },

        get projetosDosChats() { return [...new Set(this.chats.map(c => c.projeto))].sort(); },
        get chatsFiltrados() {
            const termo = this.buscaChats.trim().toLocaleLowerCase('pt-BR');
            return this.chats.filter(c => (!this.filtroChatProjeto || c.projeto === this.filtroChatProjeto)
                && (this.filtroChatEstado === 'todos' || (this.filtroChatEstado === 'ativos' ? c.status !== 'pausada' : c.status === this.filtroChatEstado))
                && (!termo || [c.titulo, c.projeto, c.agente, '#' + c.id].join(' ').toLocaleLowerCase('pt-BR').includes(termo)));
        },
        abrirChat(chat) {
            this.abrir(chat.projeto || GERAL, chat.id);
            this.irPara('chats'); this.sub = 'conversa';
        },

        get sessoesDoProjeto() { return this.sessoes.filter(s => s.projeto === this.atual); },
        get emExecucao() { return this.atividade.filter(a => a.status === 'executando').length; },
        get naFila() { return this.atividade.filter(a => a.status === 'pendente').length; },

        garantirAba() { if (!this.abas[this.chaveAba]) this.abas[this.chaveAba] = this.novaAba(); },

        novaAba() {
            return { mensagens: [], carregado: false, haAnteriores: false, rascunho: '', pendente: null, enviando: false, arquivos: [], progresso: '', recibo: '',
                tarefa: 'nova', agente: 'auto', modo: 'auto', verTarefa: null, scroll: null };
        },

        init() {
            const url = new URLSearchParams(location.search).get('projeto');
            this.abaVazia = this.novaAba();
            this.tela = ler('central-ia:tela') || 'chats';
            try { this.recolhidos = JSON.parse(ler('central-ia:recolhidos') || '[]'); } catch { this.recolhidos = []; }
            const salvo = ler('central-ia:projeto');
            this.escolherConversaInicial = url === null && salvo === null;
            this.atual = url ?? salvo ?? GERAL;
            const tarefaUrl = Number(new URLSearchParams(location.search).get('tarefa'));
            this.chatAtual = Number.isSafeInteger(tarefaUrl) && tarefaUrl > 0 ? tarefaUrl : null;
            this.verTarefa = this.chatAtual;
            this.garantirAba();
            if (this.chatAtual) this.aba.tarefa = this.chatAtual;
            this.aoVoltarRede = () => this.reconectar();
            this.aoPerderRede = () => { this.conexao = 'offline'; this.aviso = 'Sem conexão. Sua mensagem continua aqui para reenviar.'; };
            this.aoMostrar = () => { if (!document.hidden) this.reconectar(); };
            this.aoSair = e => {
                if (Object.values(this.abas).some(a => a.pendente || a.rascunho.trim() || a.arquivos.length)) { e.preventDefault(); e.returnValue = ''; }
            };
            window.addEventListener('online', this.aoVoltarRede);
            window.addEventListener('offline', this.aoPerderRede);
            document.addEventListener('visibilitychange', this.aoMostrar);
            window.addEventListener('beforeunload', this.aoSair);
            this.relogio = setInterval(() => { this.agora = Date.now(); }, 1000);
            this.atualizar();
        },

        destroy() {
            this.encerrado = true; clearTimeout(this.timer); clearInterval(this.relogio);
            window.removeEventListener('online', this.aoVoltarRede);
            window.removeEventListener('offline', this.aoPerderRede);
            document.removeEventListener('visibilitychange', this.aoMostrar);
            window.removeEventListener('beforeunload', this.aoSair);
        },

        async pedir(url, method = 'GET', corpo = undefined) {
            const abortar = new AbortController();
            const multipart = corpo instanceof FormData;
            const prazo = setTimeout(() => abortar.abort(), multipart ? 120000 : 12000);
            try {
                const resposta = await fetch(url, {
                    method, credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: abortar.signal,
                    headers: { Accept: 'application/json', ...(multipart ? {} : { 'Content-Type': 'application/json' }), 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: corpo === undefined ? undefined : multipart ? corpo : JSON.stringify(corpo),
                });
                if (!resposta.ok) {
                    const erro = new Error('falhou'); erro.status = resposta.status;
                    // A recusa explica o motivo (projeto, limite do dia, tarefa ocupada).
                    if ([422, 403, 413].includes(resposta.status)) { try { erro.detalhe = (await resposta.json()).message; } catch {} }
                    throw erro;
                }
                return await resposta.json();
            } finally { clearTimeout(prazo); }
        },

        falhou(erro) {
            this.falhas++;
            if ([401, 403, 419].includes(erro.status)) {
                this.administracao = { permitida: false, usuarios: [] }; this.consultaAtividade = null; this.consultaConsumo = null;
                this.usuarioAtividade = ''; this.usuarioConsumo = ''; this.relatorioAtividade = { eventos: [], execucoes: [] }; this.relatorioConsumo = { consumo: [] }; this.resumoUsuario = null; this.politicaUsuario = null;
            }
            if ([401, 419].includes(erro.status) || (erro.status === 403 && !erro.detalhe)) {
                this.bloqueado = true; this.conexao = 'bloqueado'; this.abas = {}; this.garantirAba();
                this.aviso = 'Sua sessão ou permissão mudou. Entre novamente no AlfaMatriz para continuar.';
            } else if ([422, 403, 413].includes(erro.status)) {
                this.aviso = erro.status === 413 ? 'Arquivo maior que o limite do servidor (20 MB).' : erro.detalhe || 'Confira a mensagem: ela não pôde ser registrada.';
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
                if (item.origem === 'agente') aba.recibo = '';
                if (aba.pendente?.client_id === item.client_id) { aba.pendente = null; aba.rascunho = ''; aba.arquivos = []; }
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
                this.chats = painel.chats || [];
                this.preferencias = painel.preferencias || null;
                this.administracao = painel.administracao || { permitida: false, usuarios: [] };
                if (!this.administracao.permitida) { this.usuarioAtividade = ''; this.usuarioConsumo = ''; this.relatorioAtividade = { eventos: [], execucoes: [] }; this.relatorioConsumo = { consumo: [] }; this.resumoUsuario = null; this.politicaUsuario = null; }
                else if (this.usuarioAtividade) this.carregarUsuario(false);
                if (!this.ajustesAlterados && this.preferencias) this.ajustes = JSON.parse(JSON.stringify(this.preferencias.regras));
                this.projetos = painel.projetos; this.atividade = painel.atividade; this.maquina = painel.maquina;
                this.atendimento = painel.canal || { disponivel: false, ativo: 'web' };
                this.paralelo = painel.paralelo; this.telegram = painel.telegram; this.contaNome = painel.conta_nome || '';
                if (this.escolherConversaInicial) {
                    this.escolherConversaInicial = false;
                    if ((!this.atendimento.disponivel || this.atendimento.ativo === 'telegram') && this.telegram.vinculado && !this.aba.rascunho && !this.aba.arquivos.length && !this.aba.pendente) this.abrirTelegram();
                }
                this.recentes = painel.recentes; this.consumo = painel.consumo; this.sessoes = painel.sessoes; this.grupos = painel.grupos || []; this.eventos = painel.eventos || [];
                if (['atividade', 'consumo'].includes(this.tela)) this.carregarRelatorio(this.tela);
                if (this.telegram.vinculado) this.pareamento = null;

                const projeto = this.atual, aba = this.aba, chave = this.chaveAba, tarefa = this.chatAtual;
                const feed = this.$refs.feed;
                const noFim = !feed || feed.scrollHeight - feed.scrollTop - feed.clientHeight < 80;
                const ultima = aba.mensagens.at(-1)?.id;
                const r = await this.pedir(this.url(config.historico, { projeto, tarefa: tarefa || undefined, depois: ultima }));
                if (chave !== this.chaveAba) return;
                this.juntar(aba, r.mensagens);
                for (const e of r.recentes || []) {
                    const m = aba.mensagens.find(x => x.id === e.id);
                    if (m) { m.entrega = e.entrega; m.pedido = e.pedido; }
                }
                if (!ultima) aba.haAnteriores = r.ha_mais;
                alcancando = !!ultima && r.ha_mais;

                if (projeto) {
                    const t = await this.pedir(config.tarefas.replace('__P__', encodeURIComponent(projeto)));
                    if (chave === this.chaveAba) this.tarefas = t.tarefas;
                } else {
                    this.tarefas = [];
                }

                const primeira = !aba.carregado;
                aba.carregado = true;
                if (this.conexao === 'offline') this.aviso = ''; this.conexao = 'online'; this.falhas = 0;
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
            const aba = this.aba, chave = this.chaveAba;
            if (this.ocupado || this.bloqueado || !aba.mensagens.length) return;
            clearTimeout(this.timer); this.ocupado = true;
            const feed = this.$refs.feed; const altura = feed.scrollHeight;
            try {
                const r = await this.pedir(this.url(config.historico, { projeto: this.atual, tarefa: this.chatAtual || undefined, antes: aba.mensagens[0].id }));
                if (chave !== this.chaveAba) return;
                this.juntar(aba, r.mensagens); aba.haAnteriores = r.ha_mais;
                this.$nextTick(() => { feed.scrollTop += feed.scrollHeight - altura; });
            } catch (erro) { this.falhou(erro); }
            finally { this.ocupado = false; if (!this.bloqueado && !this.encerrado) this.timer = setTimeout(() => this.atualizar(), 5000); }
        },

        reconectar() { if (!this.ocupado && !this.bloqueado) { clearTimeout(this.timer); this.atualizar(); } },

        /** Alterna de projeto guardando o lugar de onde se saiu. */
        abrir(projeto, tarefa = null) {
            this.escolherConversaInicial = false;
            const feed = this.$refs.feed;
            if (feed) this.aba.scroll = feed.scrollTop;
            this.aba.verTarefa = this.verTarefa;
            this.atual = projeto ?? GERAL;
            this.chatAtual = tarefa || null;
            this.garantirAba();
            this.verTarefa = tarefa ?? this.aba.verTarefa;
            if (tarefa) this.aba.tarefa = tarefa;
            this.tarefas = [];
            this.menuProjetosMovel = false;
            if (!['projetos', 'chats'].includes(this.tela)) this.irPara('projetos');
            guardar('central-ia:projeto', this.atual);
            const q = new URLSearchParams(location.search);
            this.atual ? q.set('projeto', this.atual) : q.delete('projeto');
            if (this.chatAtual) { q.set('projeto', this.atual); q.set('tarefa', this.chatAtual); } else q.delete('tarefa');
            history.replaceState(null, '', location.pathname + (q.toString() ? '?' + q : ''));
            this.$nextTick(() => {
                const f = this.$refs.feed;
                if (f) f.scrollTop = this.aba.scroll ?? f.scrollHeight;
            });
            this.reconectar();
        },

        abrirTelegram() {
            if (!this.telegram.vinculado) return;
            this.abrir(this.telegram.projeto || GERAL);
            // Um filtro de outra tarefa fazia uma conversa gravada parecer ausente.
            this.verTarefa = null; this.aba.verTarefa = null;
            if (this.telegram.tarefa_id) {
                this.historicosAbertos[this.telegram.tarefa_id] = true;
            }
            if (!this.aba.pendente && !this.aba.rascunho.trim() && !this.aba.arquivos.length) this.aba.tarefa = this.telegram.tarefa_id || 'nova';
            this.sub = 'conversa';
        },

        selecionarTarefa(id, irParaConversa = false) {
            if (id && (irParaConversa || this.chatAtual)) {
                this.abrirChat({ projeto: this.atual, id }); return;
            }
            if (!id && this.chatAtual) { this.abrir(this.atual); this.verTarefa = null; return; }
            this.verTarefa = this.verTarefa === id && !irParaConversa ? null : id;
            if (irParaConversa) this.sub = 'conversa';
            if (this.verTarefa) this.aba.tarefa = id;
            this.$nextTick(() => { const f = this.$refs.feed; if (f) f.scrollTop = f.scrollHeight; });
        },

        async transferirCanal(destino) {
            if (this.transferindoCanal || this.aba.enviando || this.aba.pendente) return;
            this.transferindoCanal = true;
            const anterior = { ...this.atendimento };
            try {
                const tarefa = this.verTarefa || (this.aba.tarefa !== 'nova' ? this.aba.tarefa : null);
                const resultado = await this.pedir(config.canal, 'POST', {
                    canal: destino, projeto: destino === 'telegram' ? (this.atual || null) : null,
                    tarefa_id: destino === 'telegram' ? tarefa : null,
                });
                this.atendimento = { ...resultado, disponivel: true };
                if (destino === 'web' && anterior.ativo === 'telegram') this.abrir(anterior.projeto || GERAL, anterior.tarefa_id);
                this.aviso = destino === 'telegram'
                    ? 'Continue no Telegram. Seu rascunho fica guardado aqui; ele não foi enviado.'
                    : 'Conversa retomada no AlfaMatriz.';
                this.reconectar();
            } catch (e) { this.aviso = e.detalhe || 'Não foi possível trocar o canal. Tente novamente; o canal anterior permanece ativo.'; }
            finally { this.transferindoCanal = false; }
        },

        async enviar() {
            const aba = this.aba, chave = this.chaveAba, projetoEnviado = this.atual;
            if ((this.atendimento.disponivel && this.atendimento.ativo !== 'web') || this.transferindoCanal || aba.enviando || this.bloqueado || !this.podeEnviar || (!aba.pendente && !aba.rascunho.trim() && !aba.arquivos.length)) return;
            if (!aba.pendente && this.chatAtual) aba.tarefa = this.chatAtual;
            // Vendo uma tarefa (o filtro "Só a tarefa #N"), o pedido vai para ela
            // — e não abre uma tarefa nova sem o contexto da que está na tela.
            if (!aba.pendente && this.verTarefa && aba.tarefa === 'nova') aba.tarefa = this.verTarefa;
            if (!aba.pendente) {
                aba.pendente = {
                    client_id: novoId(), conteudo: aba.rascunho.trim() || 'Analise os arquivos anexados.', anexos: aba.arquivos.map(a => a.id), projeto: this.atual || null,
                    tarefa_id: (this.chatAtual || this.atual) && aba.tarefa !== 'nova' ? Number(aba.tarefa) : null,
                    agente: aba.agente !== 'nota' ? aba.agente : null,
                    modo: aba.modo,
                };
            }
            aba.enviando = true;
            try {
                for (const [i, anexo] of aba.arquivos.entries()) {
                    if (anexo.enviado) continue;
                    aba.progresso = 'Enviando arquivo ' + (i + 1) + ' de ' + aba.arquivos.length + ': ' + anexo.file.name;
                    const form = new FormData(); form.append('arquivo', anexo.file); form.append('client_id', anexo.id);
                    await this.pedir(config.anexar, 'POST', form); anexo.enviado = true;
                }
                aba.progresso = 'Confirmando mensagem…';
                const r = await this.pedir(config.enviar, 'POST', aba.pendente);
                aba.recibo = aba.pendente.agente ? 'Pedido recebido. Acompanhe o andamento abaixo.' : 'Mensagem registrada.';
                aba.pendente = null; aba.rascunho = ''; aba.arquivos = []; this.aviso = '';
                // Pedido que abriu tarefa nova: o compositor passa a seguir nela.
                if (r.tarefa_id && aba.tarefa === 'nova') {
                    aba.tarefa = r.tarefa_id;
                    if (chave === this.chaveAba) this.abrirChat({ projeto: projetoEnviado, id: r.tarefa_id });
                }
                await this.atualizar();
            } catch (erro) {
                this.falhou(erro);
                // Recusa definitiva (projeto, limite): o texto volta ao rascunho
                // para corrigir, e o próximo envio ganha id novo.
                if ([422, 403, 413].includes(erro.status) && aba.pendente) { aba.rascunho = aba.pendente.conteudo; aba.pendente = null; }
            }
            finally { aba.enviando = false; aba.progresso = ''; }
        },

        async salvarAjustes() {
            if (!this.ajustes || this.salvandoAjustes || !this.preferencias?.disponivel) return;
            this.salvandoAjustes = true;
            try {
                const r = await this.pedir(config.preferencias, 'PUT', this.ajustes);
                this.ajustes = r.regras; this.ajustesAlterados = false;
                this.aviso = 'Seus ajustes foram salvos. Execuções já iniciadas não são interrompidas.';
                this.reconectar();
            } catch (e) { this.aviso = e.detalhe || 'Não foi possível salvar seus ajustes.'; }
            finally { this.salvandoAjustes = false; }
        },

        diaHoje() { return diaLocal(); },
        async carregarRelatorio(tela) {
            if (!config.relatorio) return;
            const atividade = tela === 'atividade';
            const params = { inicio: atividade ? this.inicioAtividade : this.inicioConsumo,
                fim: atividade ? this.fimAtividade : this.fimConsumo,
                usuario: (atividade ? this.usuarioAtividade : this.usuarioConsumo) || undefined,
                agente: atividade ? undefined : (this.iaConsumo || undefined) };
            const chave = JSON.stringify(params);
            const slot = atividade ? 'consultaAtividade' : 'consultaConsumo';
            if (this[slot] !== chave) {
                if (atividade) this.relatorioAtividade = { eventos: [], execucoes: [] }; else this.relatorioConsumo = { consumo: [] };
            }
            this[slot] = chave;
            try {
                const r = await this.pedir(this.url(config.relatorio, params));
                if (this[slot] !== chave) return;
                if (atividade) this.relatorioAtividade = r; else this.relatorioConsumo = r;
            } catch (e) {
                if (this[slot] !== chave) return;
                if (atividade) this.relatorioAtividade = { eventos: [], execucoes: [] }; else this.relatorioConsumo = { consumo: [] };
                this.falhou(e);
            }
        },
        abrirProjetoCatalogo(p) { this.abrir(p.nome); this.irPara('chats'); this.sub = 'conversa'; },
        definirModoProjetos(modo) { this.modoProjetos = modo; guardar('central-ia:modo-projetos', modo); },
        async carregarUsuario(trocou = true) {
            if (trocou) { this.resumoUsuario = null; this.politicaUsuario = null; this.politicaAlterada = false; }
            if (!this.usuarioAtividade || !this.administracao.permitida) return;
            const id = String(this.usuarioAtividade);
            this.carregandoUsuario = true;
            try {
                const r = await this.pedir(this.url(config.administracao, { usuario: id }));
                if (id !== String(this.usuarioAtividade) || !this.administracao.permitida) return;
                this.resumoUsuario = r;
                if (!this.politicaAlterada && !this.salvandoPolitica) this.politicaUsuario = JSON.parse(JSON.stringify(r.politica));
            } catch (e) {
                if (id === String(this.usuarioAtividade)) { this.resumoUsuario = null; this.politicaUsuario = null; this.aviso = e.detalhe || 'Não foi possível consultar o uso administrativo.'; if ([401, 403, 419].includes(e.status)) this.falhou(e); }
            } finally { if (id === String(this.usuarioAtividade)) this.carregandoUsuario = false; }
        },

        async salvarPolitica() {
            if (!this.politicaUsuario || this.salvandoPolitica || !this.administracao.permitida) return;
            const id = String(this.usuarioAtividade);
            const { revisao, habilitados, pedidos_dia, limite_total, paralelo } = this.politicaUsuario;
            this.salvandoPolitica = true;
            try {
                const r = await this.pedir(config.politicaUsuario.replace('__U__', id), 'PUT', { revisao, habilitados, pedidos_dia, limite_total, paralelo });
                if (id !== String(this.usuarioAtividade) || !this.administracao.permitida) return;
                this.resumoUsuario = r; this.politicaUsuario = r.politica; this.politicaAlterada = false;
                this.aviso = 'Política salva. Execução continua bloqueada até validar o executor isolado; deploy e OneDrive não foram liberados.';
            } catch (e) { if (id === String(this.usuarioAtividade)) { this.aviso = e.detalhe || 'Não foi possível salvar a política.'; if ([401, 403, 419].includes(e.status)) this.falhou(e); } }
            finally { this.salvandoPolitica = false; }
        },

        async arquivarChats() {
            if (this.arquivando || !this.chats.length || !confirm('Arquivar os chats listados? O histórico será preservado e pedidos na fila ficarão suspensos. Execuções em andamento não serão interrompidas.')) return;
            this.arquivando = true;
            try {
                const r = await this.pedir(config.arquivarChats, 'POST', { ids: this.chats.map(c => c.id) });
                this.chats = this.chats.filter(c => !r.arquivadas.includes(c.id));
                if (r.arquivadas.includes(this.chatAtual)) { this.abrir(this.atual); this.irPara('chats'); }
                this.aviso = r.arquivadas.length + ' chats arquivados. ' + (r.rodando.length ? r.rodando.length + ' continuam executando.' : 'Histórico preservado.');
                this.reconectar();
            } catch (e) { this.aviso = e.detalhe || 'Não foi possível arquivar os chats.'; }
            finally { this.arquivando = false; }
        },

        async fixar(projeto) {
            if (!projeto) return;
            try { await this.pedir(config.fixar.replace('__P__', encodeURIComponent(projeto)), 'POST', {}); this.fixarEscolha = ''; this.reconectar(); }
            catch (erro) { this.falhou(erro); }
        },

        /** Abre a tarefa que continua a sessão do VS Code e põe o foco no pedido. */
        async continuarSessao(s) {
            try {
                const r = await this.pedir(config.continuar, 'POST', { agente: s.agente, sessao: s.id });
                this.abrir(r.projeto, r.tarefa_id);
                this.aba.tarefa = r.tarefa_id; this.aba.agente = 'auto'; this.aba.modo = 'auto';
                this.$nextTick(() => document.getElementById('central-rascunho')?.focus());
            } catch (erro) { this.falhou(erro); }
        },

        async salvarPonto(t) {
            if (!confirm(`Salvar ponto da tarefa #${t.id}? O agente resume o estado e os próximos pedidos começam enxutos a partir do resumo.`)) return;
            try { await this.pedir(config.ponto.replace('__T__', t.id), 'POST', {}); this.sub = 'conversa'; this.reconectar(); }
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
                pausada: 'Pausado', arquivada: 'Arquivado', concluida: 'Concluída', ocioso: 'Sem tarefa' }[e] || e;
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
        tokens(n) {
            n = Number(n) || 0;
            return n >= 1e9 ? (n / 1e9).toFixed(1) + ' bi' : n >= 1e6 ? (n / 1e6).toFixed(1) + ' mi' : n >= 1e3 ? Math.round(n / 1e3) + ' mil' : String(n);
        },
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
