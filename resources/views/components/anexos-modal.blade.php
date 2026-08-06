@props(['name', 'resourceUrl', 'anexoUrl'])

<x-modal :name="$name" maxWidth="lg">
    <div x-data="{
            open: false,
            recordId: null,
            tipo: 'boleto',
            anexos: [],
            enviando: false,
            erro: null,
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
            remover(anexo) {
                if (! confirm('Remover este anexo?')) return;
                fetch('{{ $anexoUrl }}/' + anexo.id, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                })
                    .then(() => this.carregar());
            },
        }"
        x-on:anexos-selecionar.window="if ($event.detail.modal === '{{ $name }}') { recordId = $event.detail.id; carregar(); }"
        class="p-6"
    >
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
                    <button type="button" @click="remover(anexo)" title="Remover" class="p-1.5 rounded-md text-status-critical/70 hover:text-status-critical hover:bg-status-critical/10 transition">
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
