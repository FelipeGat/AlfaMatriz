@props(['name', 'resourceUrl', 'anexoUrl'])

<x-modal :name="$name" maxWidth="lg">
    <div x-data="{
            open: false,
            recordId: null,
            tipo: 'boleto',
            anexos: [],
            enviando: false,
            erro: null,
            aRemover: null,
            carregar() {
                fetch('{{ $resourceUrl }}/' + this.recordId + '/anexos')
                    .then(r => r.json())
                    .then(data => this.anexos = data);
            },
            enviar(event) {
                const arquivos = event.target.arquivos.files;
                if (! arquivos.length) return;

                const form = new FormData();
                form.append('tipo', this.tipo);
                for (const arquivo of arquivos) form.append('arquivos[]', arquivo);

                this.enviando = true;
                this.erro = null;
                fetch('{{ $resourceUrl }}/' + this.recordId + '/anexos', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: form,
                })
                    .then(async r => {
                        if (! r.ok) {
                            const data = await r.json().catch(() => ({}));
                            throw new Error(data.message || 'Falha ao enviar arquivo(s).');
                        }
                        return r.json();
                    })
                    .then(() => { event.target.reset(); this.carregar(); })
                    .catch(e => this.erro = e.message)
                    .finally(() => this.enviando = false);
            },
            // A remoção é por fetch, não por formulário: a confirmação vira
            // estado deste modal (qual anexo espera confirmação), para não
            // cair no diálogo do navegador — o único que sobrara na tela.
            pedirRemocao(anexo) { this.aRemover = anexo; },
            remover() {
                const anexo = this.aRemover;
                this.aRemover = null;
                if (! anexo) return;
                fetch('{{ $anexoUrl }}/' + anexo.id, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                })
                    .then(() => this.carregar());
            },
        }"
        x-on:anexos-selecionar.window="if ($event.detail.modal === '{{ $name }}') { recordId = $event.detail.id; carregar(); }"
        class="relative p-6"
    >
        {{-- Confirmação da remoção, com a mesma cara do <x-confirmar>. Aqui ela
             vive no próprio modal porque a remoção é fetch, não formulário. --}}
        <div x-show="aRemover" x-cloak class="absolute inset-0 z-10 flex items-center justify-center p-4"
             @keydown.escape.window="aRemover = null" role="dialog" aria-modal="true">
            <div class="absolute inset-0 bg-black/60" @click="aRemover = null"></div>

            <div class="relative w-full max-w-[380px] rounded-panel border border-line bg-panel p-5">
                <div class="flex items-start gap-3">
                    <span class="h-8 w-8 shrink-0 rounded-tile flex items-center justify-center"
                          style="background: rgb(var(--crit) / var(--tint-alpha)); color: rgb(var(--crit))">
                        <span class="h-[17px] w-[17px]"><x-nav-icon name="alert-triangle" /></span>
                    </span>
                    <div class="min-w-0">
                        <h2 class="font-display text-[15.5px] font-semibold text-ink">Remover este anexo?</h2>
                        <p class="mt-1 text-[13px] text-ink-dim">
                            <span class="font-medium text-ink" x-text="aRemover?.nome_original ?? aRemover?.nome ?? 'O arquivo'"></span>
                            sai do lançamento e o arquivo é apagado do servidor.
                        </p>
                    </div>
                </div>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" @click="aRemover = null"
                            class="h-9 px-3.5 rounded-control border border-btn-line text-[12.5px] font-semibold text-ink-dim hover:text-ink transition">
                        Cancelar
                    </button>
                    <button type="button" @click="remover()"
                            class="h-9 px-3.5 rounded-control text-[12.5px] font-semibold text-white transition hover:opacity-90"
                            style="background: rgb(var(--crit))">
                        Remover
                    </button>
                </div>
            </div>
        </div>

        <h3 class="font-display font-semibold text-ink text-lg mb-4">Anexos (NF / Boleto)</h3>

        <form @submit.prevent="enviar($event)" class="flex flex-wrap items-end gap-3 mb-5 pb-5 border-b border-white/5">
            <div>
                <x-input-label value="Tipo" />
                <select x-model="tipo" class="mt-1 border-white/10 rounded-md shadow-sm text-sm">
                    <option value="boleto">Boleto</option>
                    <option value="nf">Nota Fiscal</option>
                </select>
            </div>
            <div class="flex-1 min-w-[180px]">
                <x-input-label value="Arquivo(s) PDF" />
                <input type="file" name="arquivos" accept=".pdf" multiple required
                       class="mt-1 block w-full text-xs text-ink-dim file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-brand/15 file:text-brand-dim hover:file:bg-brand/25">
            </div>
            <button type="submit" :disabled="enviando" class="inline-flex items-center gap-1.5 text-xs font-semibold bg-brand text-white rounded-md px-3 py-2 hover:bg-brand-bright disabled:opacity-50">
                <span class="h-3.5 w-3.5"><x-nav-icon name="upload" /></span>
                <span x-text="enviando ? 'Enviando...' : 'Enviar'"></span>
            </button>
        </form>
        <p x-show="erro" x-cloak x-text="erro" class="text-xs text-status-critical -mt-3 mb-4"></p>

        <div class="space-y-2 max-h-64 overflow-y-auto">
            <template x-if="anexos.length === 0">
                <p class="text-sm text-ink-mute">Nenhum anexo ainda.</p>
            </template>
            <template x-for="anexo in anexos" :key="anexo.id">
                <div class="flex items-center gap-3 bg-panel-raised rounded-lg px-3 py-2">
                    <span class="h-8 w-8 shrink-0 rounded-md bg-brand/10 text-brand-dim flex items-center justify-center">
                        <span class="h-4 w-4"><x-nav-icon name="document" /></span>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-ink truncate" x-text="anexo.nome_original"></p>
                        <p class="text-[11px] text-ink-mute">
                            <span x-text="anexo.tipo_formatado"></span> · <span x-text="anexo.tamanho_formatado"></span>
                        </p>
                    </div>
                    <a :href="anexo.url" target="_blank" title="Baixar" class="p-1.5 rounded-md text-status-good/70 hover:text-status-good hover:bg-status-good/10 transition">
                        <span class="block h-4 w-4"><x-nav-icon name="download" /></span>
                    </a>
                    <button type="button" @click="pedirRemocao(anexo)" title="Remover" class="p-1.5 rounded-md text-status-critical/70 hover:text-status-critical hover:bg-status-critical/10 transition">
                        <span class="block h-4 w-4"><x-nav-icon name="trash" /></span>
                    </button>
                </div>
            </template>
        </div>

        <div class="flex justify-end mt-5">
            <x-secondary-button x-on:click="$dispatch('close')">Fechar</x-secondary-button>
        </div>
    </div>
</x-modal>
