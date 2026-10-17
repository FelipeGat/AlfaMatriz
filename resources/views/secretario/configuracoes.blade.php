<x-app-layout>
    <x-slot name="titulo">Secretário · Configurações</x-slot>
    <x-slot name="acoes">
        <a href="{{ route('secretario.meu-dia') }}"
           class="h-[34px] px-3 inline-flex items-center gap-1.5 rounded-control border border-btn-line text-ink-dim text-[12.5px] font-semibold hover:text-brand hover:border-brand transition whitespace-nowrap">
            Meu Dia
        </a>
    </x-slot>

    <div x-data="{
            auto_confirmar: @js((bool) $preferencia->auto_confirmar),
            antecedencia_padrao: @js((int) $preferencia->antecedencia_padrao),
            telegram: @js((bool) $preferencia->telegram),
            salvo: '', erro: '',
            async salvar() {
                this.salvo = ''; this.erro = '';
                const r = await fetch(@js(route('secretario.configuracoes.salvar')), { method: 'PUT',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ auto_confirmar: this.auto_confirmar, antecedencia_padrao: Number(this.antecedencia_padrao), telegram: this.telegram }) });
                r.ok ? this.salvo = 'Salvo.' : this.erro = 'Não deu certo. Confira os valores.';
            } }" class="mx-auto w-full max-w-2xl space-y-4">
        <x-painel titulo="Como o Secretário confirma e avisa">
            <div class="space-y-4 text-[13.5px] text-ink">
                <label class="flex items-start gap-3">
                    <input type="checkbox" x-model="auto_confirmar" class="mt-1 rounded border-btn-line text-brand focus:ring-brand">
                    <span>Gravar sem perguntar quando o pedido for inequívoco (data e hora claras, como "daqui a 10 minutos" ou "amanhã às 9h").
                        <span class="block text-[12px] text-ink-mute">Pedido sem hora, com hora ajustada ou vindo da IA continua pedindo confirmação.</span></span>
                </label>
                <label class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-3">
                    <span>Avisar compromissos com antecedência de</span>
                    <span class="flex items-center gap-2"><input type="number" min="0" max="1440" x-model="antecedencia_padrao"
                        class="w-24 rounded-control border border-btn-line bg-surface px-2 py-1.5 text-ink focus:border-brand focus:outline-none"> minutos</span>
                </label>
                <label class="flex items-start gap-3">
                    <input type="checkbox" x-model="telegram" class="mt-1 rounded border-btn-line text-brand focus:ring-brand">
                    <span>Mandar os lembretes também no Telegram <span class="block text-[12px] text-ink-mute">O sino do AlfaMatriz recebe sempre.</span></span>
                </label>
                <div class="flex items-center gap-3">
                    <button type="button" @click="salvar()" class="h-[36px] rounded-control bg-brand px-4 text-[13px] font-semibold text-on-brand hover:bg-brand-bright">Salvar</button>
                    <span x-show="salvo" x-text="salvo" class="text-[12.5px] text-good"></span>
                    <span x-show="erro" x-text="erro" class="text-[12.5px] text-crit"></span>
                </div>
            </div>
        </x-painel>
    </div>
</x-app-layout>
