/**
 * A conversa da Central de IA.
 *
 * Nada da conversa vai para o armazenamento do navegador: ela é privada e a
 * máquina pode ser de outra pessoa. O envio leva um `client_id` gerado AQUI e
 * guardado em `pendente` até o servidor confirmar — quem reenvia depois de um
 * timeout reenvia o mesmo id e o mesmo texto, e o servidor devolve a mensagem
 * que já tinha entrado em vez de gravar outra.
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

export function centralIa(config) {
    return {
        mensagens: [], rascunho: '', pendente: null, enviando: false, carregando: true,
        ocupado: false, haAnteriores: false, telegram: { vinculado: false, usuario: null }, pareamento: null, bloqueado: false,
        aviso: '', conexao: 'conectando', falhas: 0, timer: null, encerrado: false,
        podeEnviar: config.podeEnviar,

        get rotuloConexao() {
            return { conectando: 'Conectando…', online: 'Atualizado', offline: 'Reconectando…', bloqueado: 'Acesso interrompido' }[this.conexao];
        },

        init() {
            this.aoVoltarRede = () => this.reconectar();
            this.aoPerderRede = () => { this.conexao = 'offline'; this.aviso = 'Sem conexão. Sua mensagem continua aqui para reenviar.'; };
            this.aoMostrar = () => { if (!document.hidden) this.reconectar(); };
            this.aoSair = e => { if (this.pendente || this.rascunho.trim()) { e.preventDefault(); e.returnValue = ''; } };
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
                if (!resposta.ok) { const erro = new Error('falhou'); erro.status = resposta.status; throw erro; }
                return await resposta.json();
            } finally { clearTimeout(prazo); }
        },

        falhou(erro) {
            this.falhas++;
            if ([401, 403, 419].includes(erro.status)) {
                this.bloqueado = true; this.conexao = 'bloqueado'; this.mensagens = [];
                this.aviso = 'Sua sessão ou permissão mudou. Entre novamente no AlfaMatriz para continuar.';
            } else {
                this.conexao = 'offline';
                this.aviso = erro.status === 429 ? 'Muitas mensagens em pouco tempo. Aguarde um instante.'
                    : erro.status === 422 ? 'Confira a mensagem: ela não pôde ser registrada.'
                    : 'Não foi possível falar com o servidor. Sua mensagem continua aqui para reenviar com segurança.';
            }
        },

        juntar(itens) {
            const porId = new Map(this.mensagens.map(m => [m.id, m]));
            for (const item of itens) {
                porId.set(item.id, item);
                if (this.pendente?.client_id === item.client_id) { this.pendente = null; this.rascunho = ''; }
            }
            this.mensagens = [...porId.values()].sort((a, b) => a.id - b.id);
        },

        async atualizar() {
            if (this.ocupado || this.bloqueado || this.encerrado) return;
            clearTimeout(this.timer); this.ocupado = true;
            let alcancando = false;
            try {
                const feed = this.$refs.feed;
                const noFim = feed.scrollHeight - feed.scrollTop - feed.clientHeight < 80;
                const ultima = this.mensagens.at(-1)?.id;
                const r = await this.pedir(config.historico + (ultima ? '?depois=' + ultima : ''));
                this.juntar(r.mensagens);
                if (!ultima) this.haAnteriores = r.ha_mais;
                alcancando = !!ultima && r.ha_mais;
                for (const e of r.entregas || []) {
                    const m = this.mensagens.find(x => x.id === e.id);
                    if (m) m.entrega = e.status;
                }
                this.telegram = r.telegram;
                if (this.telegram.vinculado) this.pareamento = null;
                this.conexao = 'online'; this.aviso = ''; this.falhas = 0;
                if (noFim || this.carregando) this.$nextTick(() => { feed.scrollTop = feed.scrollHeight; });
            } catch (erro) { this.falhou(erro); }
            finally {
                this.carregando = false; this.ocupado = false;
                if (!this.bloqueado && !this.encerrado) {
                    const espera = alcancando ? 50
                        : this.falhas ? Math.min(30000, 3000 * 2 ** Math.min(this.falhas, 4))
                        : document.hidden ? 15000 : 5000;
                    this.timer = setTimeout(() => this.atualizar(), espera);
                }
            }
        },

        async anteriores() {
            if (this.ocupado || this.bloqueado || !this.mensagens.length) return;
            clearTimeout(this.timer); this.ocupado = true;
            const feed = this.$refs.feed; const altura = feed.scrollHeight;
            try {
                const r = await this.pedir(config.historico + '?antes=' + this.mensagens[0].id);
                this.juntar(r.mensagens); this.haAnteriores = r.ha_mais;
                this.$nextTick(() => { feed.scrollTop += feed.scrollHeight - altura; });
            } catch (erro) { this.falhou(erro); }
            finally { this.ocupado = false; if (!this.bloqueado && !this.encerrado) this.timer = setTimeout(() => this.atualizar(), 5000); }
        },

        reconectar() { if (!this.ocupado && !this.bloqueado) { clearTimeout(this.timer); this.atualizar(); } },

        async enviar() {
            if (this.enviando || this.bloqueado || !this.podeEnviar || (!this.pendente && !this.rascunho.trim())) return;
            if (!this.pendente) this.pendente = { client_id: novoId(), conteudo: this.rascunho };
            this.enviando = true;
            try {
                await this.pedir(config.enviar, 'POST', this.pendente);
                this.pendente = null; this.rascunho = ''; this.aviso = '';
                await this.atualizar();
            } catch (erro) { this.falhou(erro); }
            finally { this.enviando = false; }
        },

        // O código vale minutos e aparece uma vez; some sozinho quando o
        // poll vê o vínculo feito.
        async parearTelegram() {
            try { this.pareamento = await this.pedir(config.telegramCodigo, 'POST', {}); this.aviso = ''; }
            catch (erro) { this.falhou(erro); }
        },

        async desvincularTelegram() {
            if (!confirm('Desvincular o Telegram desta conversa?')) return;
            try { await this.pedir(config.telegramDesvincular, 'DELETE'); this.telegram = { vinculado: false, usuario: null }; }
            catch (erro) { this.falhou(erro); }
        },

        entrega(status) {
            return { pendente: 'Indo para o Telegram…', enviando: 'Indo para o Telegram…', entregue: 'Entregue no Telegram',
                incerta: 'Entrega no Telegram não confirmada', falhou: 'Não foi para o Telegram' }[status] || '';
        },

        autor(m) { return m.autor_nome || { usuario: 'Você', agente: 'Agente', sistema: 'Sistema' }[m.autor_tipo] || 'Sistema'; },
        papel(m) { return { usuario: 'Usuário', agente: 'Agente', sistema: 'Sistema' }[m.autor_tipo] || 'Sistema'; },
        canal(origem) { return { web: 'AlfaMatriz', telegram: 'Telegram', agente: 'Agente' }[origem] || 'Central'; },
        data(valor) { return new Date(valor).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }); },
    };
}
