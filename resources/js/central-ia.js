/**
 * A conversa da Central de IA.
 *
 * Nada da conversa vai para o armazenamento do navegador: ela é privada e a
 * máquina pode ser de outra pessoa. O envio leva um `client_id` gerado AQUI e
 * guardado em `pendente` até o servidor confirmar — quem reenvia depois de um
 * timeout reenvia o mesmo id e o mesmo texto, e o servidor devolve a mensagem
 * que já tinha entrado em vez de gravar outra.
 */
export function centralIa(config) {
    return {
        mensagens: [], rascunho: '', pendente: null, enviando: false, carregando: true,
        ocupado: false, haAnteriores: false, telegramVinculado: false, bloqueado: false,
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
                this.telegramVinculado = r.telegram_vinculado;
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
            if (!this.pendente) this.pendente = { client_id: crypto.randomUUID(), conteudo: this.rascunho };
            this.enviando = true;
            try {
                await this.pedir(config.enviar, 'POST', this.pendente);
                this.pendente = null; this.rascunho = ''; this.aviso = '';
                await this.atualizar();
            } catch (erro) { this.falhou(erro); }
            finally { this.enviando = false; }
        },

        autor(m) { return m.autor_nome || { usuario: 'Você', agente: 'Agente', sistema: 'Sistema' }[m.autor_tipo] || 'Sistema'; },
        papel(m) { return { usuario: 'Usuário', agente: 'Agente', sistema: 'Sistema' }[m.autor_tipo] || 'Sistema'; },
        canal(origem) { return { web: 'AlfaMatriz', telegram: 'Telegram', agente: 'Agente' }[origem] || 'Central'; },
        data(valor) { return new Date(valor).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }); },
    };
}
