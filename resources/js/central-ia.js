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
        perguntaAberta: null, respostaPergunta: '', detalhesChat: false,
        // Pedidos parados esperando o código que a MÁQUINA mandou ao Telegram.
        confirmacoes: [], codigos: {}, confirmando: null, acoesAbertas: {},
        pops: [], popEditando: null, salvandoPop: false,
        // Novidades por chat (dono, 13/10/2026: "imagina 5 chats simultâneos —
        // o próprio chat tem que comunicar que finalizou ou está aguardando").
        novidades: {}, estadoDosChats: null, tituloOriginal: null,
        chats: [], buscaChats: '', filtroChatProjeto: '', filtroChatEstado: 'ativos', chatAtual: null,
        // Chats Business: um chat contínuo por assunto (docs/specs/chats-business).
        assuntos: [], novoAssunto: null, buscaAssuntos: '',
        imagemAberta: null, trazendo: null, memoria: null,
        preferencias: null, ajustes: null, ajustesAlterados: false, salvandoAjustes: false, arquivando: false,
        administracao: { permitida: false, usuarios: [] }, usuarioAtividade: '', resumoUsuario: null,
        politicaUsuario: null, politicaAlterada: false, salvandoPolitica: false, carregandoUsuario: false,
        projetos: [], atividade: [], maquina: { online: false, agentes: [], projetos: [] }, paralelo: 2,
        recentes: [], consumo: { por_agente: [], projetos_hoje: [], cota_codex: null, serie: [], limites: null }, sessoes: [], periodo: 'hoje',
        grupos: [], busca: '', recolhidos: [], tela: 'projetos', sub: 'conversa',
        eventos: [], vistaAtividade: 'log', soProblemas: false, projetoLog: '', barraAberta: false, barraHover: null, mostrarTabela: false,
        telegram: { vinculado: false, usuario: null, bot: null, bot_situacao: null }, pareamento: null, botToken: '', salvandoBot: false, trocandoBot: false, contaNome: '', escolherConversaInicial: false,
        // Conectar minha máquina (T-374): o comando de instalação e as máquinas da pessoa.
        maquinas: { lista: [], carregada: false, comando: null, minutos: 15, gerado_em: null, copiado: false },
        espiandoMaquinas: null,
        atendimento: { disponivel: false, ativo: 'web', projeto: null, tarefa_id: null }, transferindoCanal: false,
        // Projeto aberto
        atual: GERAL, abas: {}, tarefas: [], verTarefa: null, detalheAberto: true,
        historicosAbertos: {}, fixarEscolha: '', menuProjetosMovel: false,
        projetosRecolhidos: ler('central-ia:projetos-recolhidos') === 'sim',
        // Recolhidos por padrão: o estado cabe num selo só ao lado das abas.
        indicadoresRecolhidos: ler('central-ia:indicadores-recolhidos') !== 'nao',
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
        /**
         * Para onde vai a próxima mensagem. Sem chat escolhido, o chat da
         * última mensagem na tela, se ainda estiver em curso — e não um chat
         * novo (dono, 13/10/2026: "ótimo, o commit está feito?" respondido
         * sob o #16 virou o #19). Chat novo só pelo "+ Novo chat".
         */
        get destinoDaMensagem() {
            if (this.chatAtual) return this.chatAtual;
            if (this.aba.tarefa !== 'nova') return Number(this.aba.tarefa);
            if (this.aba.novoExplicito) return null;
            const ultima = [...this.mensagensVisiveis].reverse().find(m => m.tarefa_id);
            // Assunto só é destino na aba Business: o feed da Geral também traz
            // as mensagens dos assuntos sem projeto, e a mensagem cairia nele.
            const candidatos = this.tela === 'business' ? this.assuntos : this.chats;
            const viva = ultima && candidatos.find(c => c.id === ultima.tarefa_id && !['concluida', 'arquivada'].includes(c.status));
            return viva ? viva.id : null;
        },
        get rotuloDoDestino() {
            const id = this.destinoDaMensagem;
            if (!id) return '→ novo chat';
            const c = [...this.chats, ...this.assuntos].find(x => x.id === id);
            return '→ #' + id + (c ? ' ' + c.titulo : '');
        },
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
                if (file.size > 12 * 1024 * 1024 || !['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'video/mp4', 'video/webm', 'video/quicktime'].includes(file.type)) {
                    this.aviso = 'Use JPG, PNG, WebP, PDF, MP4, WebM ou MOV de até 12 MB.'; continue;
                }
                // Miniatura local (nada sai do navegador até o envio).
                const miniatura = file.type.startsWith('image/') ? URL.createObjectURL(file) : null;
                this.aba.arquivos.push({ file, id: novoId(), enviado: false, miniatura });
            }
        },
        removerArquivo(i) {
            const [a] = this.aba.arquivos.splice(i, 1);
            if (a?.miniatura) URL.revokeObjectURL(a.miniatura);
        },
        /** A caixa cresce com o texto, até 40% da altura da janela. */
        crescer(el) {
            if (!el) return;
            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight + 2, Math.round(window.innerHeight * 0.4)) + 'px';
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
                recusado: 'recusado', sessao_continuada: 'conversa do VS Code', ponto_salvo: 'ponto salvo',
                alteracoes: 'alterou', pasta_mudou: 'pasta mudou', pop_atualizado: 'POP', pop_apagado: 'POP apagado', analise_alterou: 'ANÁLISE ALTEROU', escalada: 'escalada', escalada_esgotada: 'escalada parou',
                escalada_sem_alternativa: 'sem alternativa', confirmacao_pedida: 'pediu código', confirmacao_aceita: 'confirmado',
                confirmacao_recusada: 'RECUSADO', confirmacao_expirada: 'código expirou', confirmacao_codigo_errado: 'código errado',
                confirmacao_digitada: 'código digitado' }[t] || t;
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
        irPara(tela) { this.tela = tela; if (['atividade', 'consumo'].includes(tela)) this.carregarRelatorio(tela); if (tela === 'ajustes') this.carregarMaquinas(); guardar('central-ia:tela', tela); if (tela === 'projetos') this.$nextTick(() => { const f = this.$refs.feed; if (f) f.scrollTop = f.scrollHeight; }); },

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
                && (this.filtroChatEstado === 'todos' || (this.filtroChatEstado === 'ativos' ? !['pausada', 'concluida'].includes(c.status) : c.status === this.filtroChatEstado))
                && (!termo || [c.titulo, c.projeto, c.agente, '#' + c.id].join(' ').toLocaleLowerCase('pt-BR').includes(termo)));
        },
        /**
         * Compara o estado de cada chat com o do poll anterior: terminou,
         * falhou ou passou a esperar você vira novidade (pisca até abrir).
         */
        notarNovidades(chats) {
            const antes = this.estadoDosChats;
            this.estadoDosChats = Object.fromEntries(chats.map(c => [c.id, c.status]));
            if (antes === null) return; // primeira carga: nada é novidade
            for (const c of chats) {
                const era = antes[c.id];
                if (!era || era === c.status || c.id === this.chatAtual) continue;
                const tipo = c.status === 'aguardando_voce' ? 'aguardando' : c.status === 'falhou' ? 'falhou'
                    : (era === 'executando' && ['aberta', 'concluida'].includes(c.status)) ? 'terminou' : null;
                if (!tipo) continue;
                this.novidades[c.id] = tipo;
                const texto = { aguardando: 'está esperando você', falhou: 'falhou', terminou: 'terminou' }[tipo];
                // Assunto (tem grupo_id) se diz pelo nome; tarefa, pelo projeto.
                this.aviso = 'grupo_id' in c ? `${c.titulo} ${texto}.` : `Chat #${c.id} (${c.projeto || 'Geral'}) ${texto}: ${c.titulo}`;
                try { if (typeof Notification !== 'undefined' && Notification.permission === 'granted') new Notification(`Central de IA · #${c.id} ${texto}`, { body: c.titulo, tag: 'central-ia-' + c.id }); } catch {}
            }
            this.atualizarTitulo();
        },
        get totalNovidades() { return Object.keys(this.novidades).length; },
        atualizarTitulo() {
            if (typeof document === 'undefined' || typeof document.title !== 'string') return;
            this.tituloOriginal ??= document.title.replace(/^\(\d+\) /, '');
            document.title = (this.totalNovidades ? `(${this.totalNovidades}) ` : '') + this.tituloOriginal;
        },
        pedirNotificacoes() {
            try { if (typeof Notification !== 'undefined' && Notification.permission === 'default') Notification.requestPermission(); } catch {}
        },

        novoChatEm: '',
        /** Chat novo: o primeiro pedido cria a tarefa (no projeto, ou na Geral). */
        novoChat(projeto = '') {
            this.abrir(projeto || GERAL);
            this.chatAtual = null; this.verTarefa = null; this.aba.verTarefa = null;
            if (!this.aba.pendente) { this.aba.tarefa = 'nova'; this.aba.novoExplicito = true; }
            this.irPara('chats'); this.sub = 'conversa';
            this.$nextTick(() => document.getElementById('central-rascunho')?.focus());
        },
        abrirChat(chat) {
            delete this.novidades[chat.id]; this.atualizarTitulo();
            this.abrir(chat.projeto || GERAL, chat.id);
            // Dentro da aba Business, a conversa abre ao lado da lista de assuntos.
            // Assunto abre na Business; chat comum, em Chats Ativos — mesmo
            // clicado de dentro da Business (a pergunta pendente aparece em toda aba).
            this.irPara(this.assuntos.some(a => a.id === chat.id) ? 'business' : 'chats'); this.sub = 'conversa';
        },

        /* ---------- Chats Business ---------- */
        get assuntoAtual() { return this.assuntos.find(a => a.id === this.chatAtual) || null; },
        /** Os assuntos nos grupos de Projetos (os mesmos da conta), e os sem grupo por último. */
        get secoesAssuntos() {
            const termo = this.buscaAssuntos.trim().toLocaleLowerCase('pt-BR');
            const lista = this.assuntos.filter(a => !termo || [a.titulo, a.projeto, a.agente].join(' ').toLocaleLowerCase('pt-BR').includes(termo));
            const secoes = this.grupos.map(g => ({ id: 'assunto-g' + g.id, nome: g.nome, assuntos: lista.filter(a => a.grupo_id === g.id) }));
            secoes.push({ id: 'assunto-sem', nome: 'Sem grupo', assuntos: lista.filter(a => !this.grupos.some(g => g.id === a.grupo_id)) });
            return secoes.filter(s => s.assuntos.length || (!termo && s.id !== 'assunto-sem'));
        },
        abrirAssunto(a) { this.irPara('business'); this.abrirChat(a); this.pedirNotificacoes(); },
        async criarAssunto() {
            const n = this.novoAssunto;
            if (!n?.titulo.trim() || n.salvando) return;
            n.salvando = true;
            try {
                const r = await this.pedir(config.assuntos, 'POST', { titulo: n.titulo.trim(), grupo_id: n.grupo_id ? Number(n.grupo_id) : null, projeto: n.projeto || '' });
                this.assuntos = [{ id: r.id, titulo: n.titulo.trim(), grupo_id: n.grupo_id ? Number(n.grupo_id) : null, projeto: n.projeto || '', status: 'aberta', agente: null, ultima_atividade: new Date().toISOString() }, ...this.assuntos];
                this.novoAssunto = null;
                this.abrirAssunto(this.assuntos[0]);
                this.$nextTick(() => document.getElementById('central-rascunho')?.focus());
            } catch (erro) { this.falhou(erro); n.salvando = false; }
        },
        /** Conversas do VS Code que podem semear um chat (Claude e Codex), com nome primeiro. */
        get conversasDoVsCode() {
            return this.sessoes.filter(s => ['claude', 'codex'].includes(s.agente))
                .sort((a, b) => (!!b.titulo - !!a.titulo) || b.ultima_atividade.localeCompare(a.ultima_atividade));
        },
        abrirTrazer() { this.trazendo = { ids: [], titulo: '', grupo_id: '', projeto: '', salvando: false, nomeTocado: false }; },
        /** Marca/desmarca; o nome e o grupo do chat seguem a primeira conversa marcada (Vix* → VixCard). */
        marcarConversa(s) {
            const t = this.trazendo;
            t.ids = t.ids.includes(s.id) ? t.ids.filter(x => x !== s.id) : [...t.ids, s.id];
            const primeira = this.sessoes.find(x => x.id === t.ids[0]);
            if (!t.nomeTocado) t.titulo = primeira?.titulo || '';
            if (primeira && !t.grupo_id) {
                const alvo = /vix/i.test(primeira.titulo || '') ? /vix/i : /alfa/i;
                t.grupo_id = this.grupos.find(g => alvo.test(g.nome))?.id || '';
            }
        },
        async trazerDoVsCode() {
            const t = this.trazendo;
            if (!t?.ids.length || !t.titulo.trim() || t.salvando) return;
            t.salvando = true;
            try {
                const sessoes = t.ids.map(id => ({ agente: this.sessoes.find(s => s.id === id).agente, id }));
                const r = await this.pedir(config.semear, 'POST', { titulo: t.titulo.trim(), grupo_id: t.grupo_id ? Number(t.grupo_id) : null, projeto: t.projeto || '', sessoes });
                this.assuntos = [{ id: r.id, titulo: t.titulo.trim(), grupo_id: t.grupo_id ? Number(t.grupo_id) : null, projeto: t.projeto || '', status: 'executando', agente: null, ultima_atividade: new Date().toISOString() }, ...this.assuntos];
                this.trazendo = null;
                this.abrirAssunto(this.assuntos[0]);
            } catch (erro) { this.falhou(erro); t.salvando = false; }
        },
        async carregarMemoria() {
            const a = this.assuntoAtual;
            if (!a) return;
            if (this.memoria?.id !== a.id) this.memoria = null;
            try { this.memoria = await this.pedir(config.memoria.replace('__A__', a.id)); } catch (erro) { this.falhou(erro); }
        },
        /** Editar ou apagar um item; manda o texto que a pessoa via, para não corrigir o item errado. */
        async corrigirMemoria(lista, i, acao) {
            const item = this.memoria?.listas[lista]?.[i];
            if (!item) return;
            const corpo = { lista, indice: i, acao, antes: item.texto };
            if (acao === 'apagar') {
                if (!confirm(`Apagar da memória?

${item.texto}

Uma IA não poderá devolvê-lo depois.`)) return;
            } else {
                const texto = prompt('Texto:', item.texto);
                if (texto === null || !texto.trim()) return;
                corpo.texto = texto.trim();
                if (lista === 'mapa') {
                    const caminho = prompt('Caminho (pasta, arquivo ou link; vazio se não houver):', item.caminho || '');
                    if (caminho === null) return;
                    const etapa = prompt('Em que etapa está agora? (vazio se não se aplica)', item.etapa || '');
                    if (etapa === null) return;
                    corpo.caminho = caminho.trim() || null; corpo.etapa = etapa.trim() || null;
                }
            }
            try { this.memoria = await this.pedir(config.memoria.replace('__A__', this.memoria.id), 'PUT', corpo); }
            catch (erro) { this.falhou(erro); this.carregarMemoria(); }
        },
        async salvarAssunto(a, dados) {
            try { await this.pedir(config.assunto.replace('__A__', a.id), 'PUT', dados); this.reconectar(); } catch (erro) { this.falhou(erro); }
        },
        async renomearAssunto(a) {
            const titulo = prompt('Nome do chat:', a.titulo);
            if (titulo?.trim() && titulo.trim() !== a.titulo) this.salvarAssunto(a, { titulo: titulo.trim() });
        },
        async arquivarAssunto(a) {
            if (!confirm(`Arquivar o chat "${a.titulo}"? A memória e o histórico ficam guardados.`)) return;
            await this.salvarAssunto(a, { status: 'arquivada' });
            if (this.chatAtual === a.id) { this.chatAtual = null; this.abrir(GERAL); this.irPara('business'); }
        },

        get sessoesDoProjeto() { return this.sessoes.filter(s => s.projeto === this.atual); },
        get emExecucao() { return this.atividade.filter(a => a.status === 'executando').length; },
        get naFila() { return this.atividade.filter(a => a.status === 'pendente').length; },

        garantirAba() { if (!this.abas[this.chaveAba]) this.abas[this.chaveAba] = this.novaAba(); },

        novaAba() {
            return { mensagens: [], carregado: false, haAnteriores: false, rascunho: '', pendente: null, enviando: false, arquivos: [], progresso: '', recibo: '',
                tarefa: 'nova', agente: 'auto', modo: 'auto', nivel: 'auto', verTarefa: null, scroll: null };
        },

        init() {
            const url = new URLSearchParams(location.search).get('projeto');
            this.abaVazia = this.novaAba();
            this.tela = ler('central-ia:tela') || 'chats';
            if (this.tela === 'ajustes') this.carregarMaquinas();
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
                this.notarNovidades([...(painel.chats || []), ...(painel.assuntos || [])]);
                this.chats = painel.chats || [];
                this.assuntos = painel.assuntos || [];
                this.confirmacoes = painel.confirmacoes || [];
                this.pops = painel.pops || [];
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
            if (!['projetos', 'chats', 'business'].includes(this.tela)) this.irPara('projetos');
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

        get perguntaPendente() {
            const t = this.tarefaVista || this.tarefas.find(t => t.id === Number(this.aba.tarefa));
            return t?.duvida ? t : null;
        },
        abrirPergunta(texto, opcoes = []) {
            this.perguntaAberta = { texto, opcoes, chave: this.chaveAba };
            this.respostaPergunta = '';
            this.$nextTick(() => this.$refs.respostaPergunta?.focus());
        },
        conterFocoPergunta(event) {
            const controles = [...(this.$refs.dialogoPergunta?.querySelectorAll('button:not([disabled]), textarea') || [])];
            const primeiro = controles[0], ultimo = controles.at(-1);
            if (event.shiftKey && document.activeElement === primeiro) { event.preventDefault(); ultimo?.focus(); }
            else if (!event.shiftKey && document.activeElement === ultimo) { event.preventDefault(); primeiro?.focus(); }
        },
        responderPergunta() {
            if (!this.perguntaAberta || !this.respostaPergunta.trim() || this.perguntaAberta.chave !== this.chaveAba) return;
            if (this.aba.pendente || this.aba.enviando) { this.aviso = 'Aguarde a confirmação da mensagem anterior.'; return; }
            if (this.aba.rascunho.trim()) { this.aviso = 'Envie ou guarde seu rascunho antes de responder à pergunta.'; return; }
            this.aba.rascunho = 'Esclarecimento da pergunta: ' + this.perguntaAberta.texto + '\nMinha resposta: ' + this.respostaPergunta.trim();
            this.perguntaAberta = null;
            this.enviar();
        },
        async enviar() {
            const aba = this.aba, chave = this.chaveAba, projetoEnviado = this.atual;
            if ((this.atendimento.disponivel && this.atendimento.ativo !== 'web') || this.transferindoCanal || aba.enviando || this.bloqueado || !this.podeEnviar || (!aba.pendente && !aba.rascunho.trim() && !aba.arquivos.length)) return;
            // Código de confirmação digitado na caixa (13/10/2026: o dono mandou
            // "220705" como mensagem e virou pedido novo). Com pedido esperando,
            // 6 dígitos confirmam — não vão para a conversa.
            const codigo = aba.rascunho.trim();
            if (!aba.pendente && /^\d{6}$/.test(codigo) && this.confirmacoes.length) {
                const alvo = this.confirmacoes.find(c => c.tarefa_id && (c.tarefa_id === this.chatAtual || c.tarefa_id === Number(aba.tarefa)))
                    || (this.confirmacoes.length === 1 ? this.confirmacoes[0] : null);
                if (alvo) { this.codigos[alvo.id] = codigo; aba.rascunho = ''; return this.confirmarCodigo(alvo.id); }
                this.aviso = 'Há mais de um pedido esperando código: digite-o na faixa "Confirme" do pedido certo.'; return;
            }
            if (!aba.pendente) aba.tarefa = this.destinoDaMensagem || 'nova';
            // Pergunta do agente aberta: o que se escreve é a RESPOSTA, e vai
            // para quem perguntou (como no VS Code), sem diálogo à parte.
            const pergunta = !aba.pendente && aba.agente === 'auto' && aba.rascunho.trim() && !aba.rascunho.startsWith('Resposta à sua pergunta') ? this.perguntaPendente : null;
            if (pergunta) {
                aba.tarefa = pergunta.id;
                const chave = { 'Claude': 'claude', 'GPT (Codex)': 'codex', 'Copilot': 'copilot' }[pergunta.agente];
                if (chave && this.maquina.agentes.includes(chave)) aba.agente = chave;
                aba.rascunho = 'Resposta à sua pergunta ("' + String(pergunta.duvida).slice(0, 300) + '"): ' + aba.rascunho.trim();
            }
            // Vendo uma tarefa (o filtro "Só a tarefa #N"), o pedido vai para ela
            // — e não abre uma tarefa nova sem o contexto da que está na tela.
            if (!aba.pendente && this.verTarefa && aba.tarefa === 'nova') aba.tarefa = this.verTarefa;
            if (!aba.pendente) {
                aba.pendente = {
                    client_id: novoId(), conteudo: aba.rascunho.trim() || 'Analise os arquivos anexados.', anexos: aba.arquivos.map(a => a.id), projeto: this.atual || null,
                    tarefa_id: aba.tarefa !== 'nova' ? Number(aba.tarefa) : null,
                    agente: aba.agente !== 'nota' ? aba.agente : null,
                    modo: aba.modo,
                    nivel: aba.nivel || 'auto',
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
                for (const a of aba.arquivos) if (a.miniatura) URL.revokeObjectURL(a.miniatura);
                aba.pendente = null; aba.rascunho = ''; aba.arquivos = []; this.aviso = '';
                if (pergunta) aba.agente = 'auto';
                // Pedido que abriu tarefa nova: o compositor passa a seguir nela.
                if (r.tarefa_id && aba.tarefa === 'nova') {
                    aba.tarefa = r.tarefa_id; aba.novoExplicito = false;
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

        /** O que está parado esperando a pessoa: código da máquina e dúvidas de agente. */
        // A dúvida da tarefa aberta já aparece na conversa: a faixa do topo não repete.
        get duvidasPendentes() { return this.chats.filter(c => c.status === 'aguardando_voce' && c.id !== this.chatAtual && c.id !== Number(this.aba.tarefa)); },
        get esperandoVoce() { return this.confirmacoes.length + this.duvidasPendentes.length; },

        /** O código que a máquina mandou ao Telegram; quem confere é ela. */
        async confirmarCodigo(id) {
            const codigo = String(this.codigos[id] || '').replace(/\D/g, '');
            if (codigo.length !== 6 || this.confirmando) { this.aviso = 'Digite os 6 dígitos que a sua máquina mandou ao Telegram.'; return; }
            this.confirmando = id;
            try {
                await this.pedir(config.confirmar.replace('__E__', id), 'POST', { codigo });
                this.codigos[id] = '';
                this.aviso = 'Código enviado à sua máquina. Ela confere e começa — ou avisa aqui se não conferir.';
                this.reconectar();
            } catch (e) { this.falhou(e); }
            finally { this.confirmando = null; }
        },

        /**
         * Ações no balão da resposta (dono, 12/10/2026: respostas "fáceis de
         * escolher e delegar"). Usam o compositor de verdade: o pedido aparece
         * na conversa, com recibo, como se a pessoa tivesse digitado.
         */
        agir(m, acao, agente = null) {
            const aba = this.aba;
            if (aba.pendente || aba.enviando) { this.aviso = 'Aguarde a confirmação da mensagem anterior.'; return; }
            if (acao !== 'ajustar' && aba.rascunho.trim()) { this.aviso = 'Envie ou apague o rascunho antes de usar este atalho.'; return; }
            if (m.tarefa_id) aba.tarefa = m.tarefa_id;
            if (acao === 'ajustar') {
                aba.rascunho = aba.rascunho.trim() ? aba.rascunho : 'Ajuste: ';
                this.$nextTick(() => { const t = document.getElementById('central-rascunho'); t?.focus(); t?.setSelectionRange(t.value.length, t.value.length); });
                return;
            }
            if (acao === 'aprovar') {
                aba.agente = 'auto'; aba.modo = 'implementar'; aba.nivel = 'auto';
                aba.rascunho = 'Aprovado. Pode seguir com o que você propôs.';
            } else if (acao === 'delegar') {
                aba.agente = agente; aba.modo = 'auto';
                aba.rascunho = 'Assuma esta tarefa a partir da última resposta e continue de onde parou.';
            }
            this.enviar().finally(() => { aba.agente = 'auto'; aba.modo = 'auto'; });
        },
        /** Os agentes a quem dá para passar a tarefa: os da máquina, menos quem respondeu. */
        delegaveis(m) {
            const nomes = { claude: 'Claude', codex: 'GPT (Codex)', copilot: 'Copilot' };
            return this.maquina.agentes.filter(a => nomes[a] !== m.autor_nome).map(a => [a, nomes[a]]);
        },

        /* ---------- POPs das IAs (só da própria conta) ---------- */
        get popsPorSistema() {
            const g = {};
            for (const p of this.pops) (g[p.sistema] ??= []).push(p);
            return Object.entries(g);
        },
        novoPop() { this.popEditando = { id: null, sistema: this.atual || '', titulo: '', conteudo: '' }; },
        editarPop(p) { this.popEditando = { id: p.id, sistema: p.sistema, titulo: p.titulo, conteudo: p.conteudo }; },
        async salvarPop() {
            const p = this.popEditando;
            if (!p || this.salvandoPop || !p.sistema.trim() || !p.titulo.trim() || !p.conteudo.trim()) return;
            this.salvandoPop = true;
            try {
                await this.pedir(p.id ? config.pop.replace('__P__', p.id) : config.pops, p.id ? 'PUT' : 'POST',
                    { sistema: p.sistema.trim(), titulo: p.titulo.trim(), conteudo: p.conteudo });
                this.popEditando = null; this.aviso = 'POP salvo. As IAs passam a usá-lo nos próximos pedidos desse sistema.';
                this.reconectar();
            } catch (e) { this.falhou(e); }
            finally { this.salvandoPop = false; }
        },
        async apagarPop(p) {
            if (!confirm(`Apagar o POP "${p.titulo}" (${p.sistema})? As IAs deixam de recebê-lo.`)) return;
            try { await this.pedir(config.pop.replace('__P__', p.id), 'DELETE'); this.reconectar(); } catch (e) { this.falhou(e); }
        },

        responderCom(opcao) {
            if (this.aba.pendente || this.aba.enviando || this.aba.rascunho.trim()) { this.aviso = 'Envie ou apague o rascunho antes.'; return; }
            this.aba.rascunho = opcao;
            this.enviar().finally(() => { this.aba.agente = 'auto'; });
        },

        /** Atalhos da conversa Geral: o pedido mais comum, no nível mais barato que resolve. */
        atalho(texto, nivel = 'auto') {
            if (this.aba.pendente || this.aba.enviando || this.aba.rascunho.trim()) return;
            this.aba.rascunho = texto; this.aba.agente = 'auto'; this.aba.nivel = nivel;
            this.enviar().finally(() => { this.aba.nivel = 'auto'; });
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
            const { revisao, habilitados, pedidos_dia, limite_total, paralelo, execucao_bloqueada } = this.politicaUsuario;
            this.salvandoPolitica = true;
            try {
                const r = await this.pedir(config.politicaUsuario.replace('__U__', id), 'PUT', { revisao, habilitados, pedidos_dia, limite_total, paralelo, execucao_bloqueada: execucao_bloqueada !== false });
                if (id !== String(this.usuarioAtividade) || !this.administracao.permitida) return;
                this.resumoUsuario = r; this.politicaUsuario = r.politica; this.politicaAlterada = false;
                this.aviso = r.politica?.execucao_bloqueada ? 'Política salva. A execução desta conta continua bloqueada.' : 'Política salva. Execução liberada: a máquina da pessoa ainda pede o código dela antes de modificar algo.';
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

        /* ---------- Conectar minha máquina (T-374) ---------- */

        async carregarMaquinas() {
            if (!config.maquinas) return;
            try {
                const { maquinas: lista, ...resto } = await this.pedir(config.maquinas);
                this.maquinas = { ...this.maquinas, ...resto, lista, carregada: true };
            }
            catch (erro) { this.falhou(erro); }
        },

        async gerarComandoMaquina() {
            try {
                const r = await this.pedir(config.maquinasInstalacao, 'POST', {});
                this.maquinas = { ...this.maquinas, comando: r.comando, minutos: r.minutos, gerado_em: Date.now(), copiado: false };
                // Enquanto o código vale, a lista se atualiza sozinha: a máquina
                // aparece aqui assim que o comando rodar nela.
                clearInterval(this.espiandoMaquinas);
                this.espiandoMaquinas = setInterval(() => {
                    if (this.tela !== 'ajustes' || Date.now() - this.maquinas.gerado_em > r.minutos * 60_000) { clearInterval(this.espiandoMaquinas); return; }
                    this.carregarMaquinas();
                }, 5000);
            } catch (erro) { this.falhou(erro); }
        },

        codigoVencido() { return !this.maquinas.gerado_em || this.agora - this.maquinas.gerado_em > this.maquinas.minutos * 60_000; },

        async copiarComando(texto) {
            try { await navigator.clipboard.writeText(texto); this.maquinas.copiado = true; setTimeout(() => { this.maquinas.copiado = false; }, 2500); }
            catch { this.aviso = 'Não consegui copiar: selecione o comando e copie à mão.'; }
        },

        async desconectarMaquina(m) {
            if (!confirm(`Desconectar a máquina "${m.maquina || m.nome}"? Ela deixa de receber pedidos até ser conectada de novo.`)) return;
            try { const r = await this.pedir(config.maquinaDesconectar.replace('__M__', m.id), 'DELETE'); this.maquinas.lista = r.maquinas; }
            catch (erro) { this.falhou(erro); }
        },

        sistemaDaMaquina(s) { return { mac: 'Mac', wsl: 'Windows (WSL)', linux: 'Linux' }[s] || '—'; },

        async parearTelegram() {
            try { this.pareamento = await this.pedir(config.telegramCodigo, 'POST', {}); this.aviso = ''; }
            catch (erro) { this.falhou(erro); }
        },

        async desvincularTelegram() {
            if (!confirm('Desvincular o Telegram desta conversa?')) return;
            try { await this.pedir(config.telegramDesvincular, 'DELETE'); this.telegram = { ...this.telegram, vinculado: false, usuario: null }; }
            catch (erro) { this.falhou(erro); }
        },

        /** O bot de cada pessoa (T-374). O token só vai ao servidor; a tela não o guarda. */
        async salvarBotTelegram() {
            if (this.salvandoBot || !this.botToken.trim()) return;
            this.salvandoBot = true;
            try {
                const r = await this.pedir(config.telegramBot, 'PUT', { token: this.botToken.trim() });
                this.botToken = ''; this.trocandoBot = false; this.aviso = '';
                this.telegram = { ...this.telegram, bot: r.bot, bot_situacao: 'ok', vinculado: r.vinculado };
                this.pareamento = r.pareamento;
            } catch (erro) { this.falhou(erro); }
            finally { this.salvandoBot = false; }
        },

        async removerBotTelegram() {
            if (!confirm('Retirar o seu bot da Central? A conversa pelo Telegram para, e as suas máquinas deixam de receber o código de confirmação até você cadastrar um bot de novo.')) return;
            try {
                await this.pedir(config.telegramBot, 'DELETE');
                this.telegram = { vinculado: false, usuario: null, bot: null, bot_situacao: null }; this.pareamento = null;
            } catch (erro) { this.falhou(erro); }
        },

        /* ---------- rótulos ---------- */

        iniciais(nome) {
            const partes = nome.split(/[-_.]/).filter(Boolean);
            return (partes.length > 1 ? partes[0][0] + partes[1][0] : nome.slice(0, 2)).toUpperCase();
        },
        estado(e) {
            return { executando: 'Executando', aguardando_voce: 'Aguardando você', falhou: 'Falhou', aberta: 'Aberta',
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
