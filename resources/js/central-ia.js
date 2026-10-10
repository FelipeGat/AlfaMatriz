// No private drafts/history in browser storage; retries keep the same message id.
export function centralIa(config) {
    return {
        messages: [], draft: '', pending: null, sending: false, loading: true,
        busyHistory: false, moreBefore: false, paired: false, blocked: false,
        notice: '', connection: 'connecting', failures: 0, timer: null, destroyed: false,
        canSend: config.canSend,
        get statusLabel() { return { connecting: 'Conectando…', online: 'Atualizado', offline: 'Reconectando…', blocked: 'Acesso interrompido' }[this.connection]; },
        init() {
            this.onOnline = () => this.reconnect();
            this.onOffline = () => { this.connection = 'offline'; this.notice = 'Sem conexão. Sua mensagem permanece nesta página para reenviar.'; };
            this.onVisible = () => { if (!document.hidden) this.reconnect(); };
            this.onUnload = e => { if (this.pending || this.draft.trim()) { e.preventDefault(); e.returnValue = ''; } };
            window.addEventListener('online', this.onOnline);
            window.addEventListener('offline', this.onOffline);
            document.addEventListener('visibilitychange', this.onVisible);
            window.addEventListener('beforeunload', this.onUnload);
            this.refresh();
        },
        destroy() {
            this.destroyed = true; clearTimeout(this.timer);
            window.removeEventListener('online', this.onOnline);
            window.removeEventListener('offline', this.onOffline);
            document.removeEventListener('visibilitychange', this.onVisible);
            window.removeEventListener('beforeunload', this.onUnload);
        },
        async request(url, method = 'GET', body = undefined) {
            const abort = new AbortController();
            const timeout = setTimeout(() => abort.abort(), 12000);
            try {
                const response = await fetch(url, { method, credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: abort.signal,
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: body === undefined ? undefined : JSON.stringify(body) });
                if (!response.ok) {
                    const error = new Error('request_failed'); error.status = response.status; throw error;
                }
                return await response.json();
            } finally { clearTimeout(timeout); }
        },
        fail(error) {
            this.failures++;
            if ([401,403,419].includes(error.status)) {
                this.blocked = true; this.connection = 'blocked'; this.messages = [];
                this.notice = 'Sua sessão ou permissão mudou. Entre novamente no AlfaMatriz para continuar.';
            } else {
                this.connection = 'offline';
                this.notice = error.status === 429 ? 'Limite temporário atingido. Aguarde antes de tentar novamente.'
                    : 'Não foi possível confirmar a comunicação. Seu envio permanece disponível para reenviar com segurança.';
            }
        },
        merge(items) {
            const map = new Map(this.messages.map(m => [m.id, m]));
            for (const item of items) {
                map.set(item.id, item);
                if (this.pending?.client_id === item.client_id) { this.pending = null; this.draft = ''; }
            }
            this.messages = [...map.values()].sort((a,b) => a.id-b.id);
        },
        async refresh() {
            if (this.busyHistory || this.blocked || this.destroyed) return;
            clearTimeout(this.timer); this.busyHistory = true;
            try {
                const feed = this.$refs.feed;
                const nearBottom = feed.scrollHeight-feed.scrollTop-feed.clientHeight < 80;
                const newest = this.messages.at(-1)?.id;
                const result = await this.request(config.history + (newest ? '?after='+newest : ''));
                this.merge(result.messages);
                for (const update of result.deliveries || []) {
                    const message = this.messages.find(m => m.id === update.id);
                    if (message) message.delivery_status = update.status;
                }
                if (!newest) this.moreBefore = result.has_more;
                this.paired = result.telegram_paired; this.connection = 'online'; this.notice = ''; this.failures = 0;
                if (nearBottom || this.loading) this.$nextTick(() => { feed.scrollTop = feed.scrollHeight; });
                this.catchup = !!newest && result.has_more;
            } catch (error) { this.fail(error); }
            finally {
                this.loading = false; this.busyHistory = false;
                if (!this.blocked && !this.destroyed) this.timer = setTimeout(() => this.refresh(), this.catchup ? 50 : (this.failures ? Math.min(30000, 3000*2**Math.min(this.failures,4)) : (document.hidden ? 15000 : 5000)));
                this.catchup = false;
            }
        },
        async older() {
            if (this.busyHistory || this.blocked || !this.messages.length) return;
            clearTimeout(this.timer); this.busyHistory = true; const feed = this.$refs.feed; const height = feed.scrollHeight;
            try {
                const result = await this.request(config.history+'?before='+this.messages[0].id);
                this.merge(result.messages); this.moreBefore = result.has_more;
                this.$nextTick(() => { feed.scrollTop += feed.scrollHeight-height; });
            } catch (error) { this.fail(error); }
            finally { this.busyHistory = false; if (!this.blocked && !this.destroyed) this.timer = setTimeout(() => this.refresh(), 5000); }
        },
        reconnect() { if (!this.busyHistory && !this.blocked) { clearTimeout(this.timer); this.refresh(); } },
        async submit() {
            if (this.sending || this.blocked || !this.canSend || (!this.pending && !this.draft.trim())) return;
            // A retry after timeout must reuse both UUID and the original text.
            if (!this.pending) this.pending = { client_id: crypto.randomUUID(), content: this.draft };
            this.sending = true;
            try {
                await this.request(config.send, 'POST', { client_id: this.pending.client_id, content: this.pending.content });
                this.pending = null; this.draft = ''; this.notice = '';
                await this.refresh();
            } catch (error) { this.fail(error); }
            finally { this.sending = false; }
        },
        author(message) { return message.actor_type === 'user' ? message.actor_name : (message.actor_name || { agent: 'Agente', tool: 'Ferramenta', event: 'Sistema' }[message.actor_type] || 'Sistema'); },
        channel(origin) { return { alfamatriz: 'AlfaMatriz', web: 'Central Web', telegram: 'Telegram', agent: 'Agente' }[origin] || 'Central'; },
        delivery(status) { return { pending: 'Aguardando entrega ao Telegram', sending: 'Enviando ao Telegram', delivered: 'Entregue ao Telegram', failed: 'Entrega ao Telegram não confirmada', uncertain: 'Entrega ao Telegram em verificação' }[status] || 'Mensagem registrada'; },
        date(value) { return new Date(value).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }); },
    };
}
