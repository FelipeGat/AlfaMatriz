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

        {{-- Etapa 2: a conta Google da própria pessoa. Tokens nunca chegam à tela. --}}
        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif
        @if ($errors->has('google'))
            <x-aviso tom="critico" :segundos="0">{{ $errors->first('google') }}</x-aviso>
        @endif
        <x-painel titulo="Google Agenda">
            <div class="space-y-3 text-[13.5px] text-ink">
                @if (! $google)
                    @if ($googleConfigurado)
                        <p>Conecte sua conta para o Secretário ver e criar eventos nas suas agendas. O Matriz pede só acesso a agendas e eventos; ninguém mais vê.</p>
                        <a href="{{ route('secretario.google.conectar') }}" class="inline-flex h-[36px] items-center rounded-control bg-brand px-4 text-[13px] font-semibold text-on-brand hover:bg-brand-bright">Conectar Google Agenda</a>
                    @else
                        <p class="text-ink-mute">A integração com o Google ainda não está configurada no servidor (cliente OAuth do Google pendente).</p>
                    @endif
                @else
                    <p>
                        Conta: <span class="font-semibold">{{ $google->conta ?? '—' }}</span> ·
                        @if ($google->conectada())
                            <span class="text-good">conectada</span>
                        @else
                            <span class="text-crit">precisa reconectar</span> · <a href="{{ route('secretario.google.conectar') }}" class="font-semibold text-brand-text">Reconectar</a>
                        @endif
                        · última leitura {{ $google->sincronizado_em?->format('d/m H:i') ?? 'ainda não' }}
                    </p>
                    @if ($agendas->isNotEmpty())
                        <ul class="divide-y divide-line rounded-ctl border border-line">
                            @foreach ($agendas as $a)
                                <li x-data="{ incluida: @js($a->incluida), padrao: @js($a->padrao), palavras: @js($a->palavras ?? ''), salvo: false,
                                        async salvar() {
                                            const r = await fetch(@js(route('secretario.google.agendas', $a->id)), { method: 'PUT',
                                                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                                body: JSON.stringify({ incluida: this.incluida, padrao: this.padrao, palavras: this.palavras || null }) });
                                            this.salvo = r.ok; if (r.ok && this.padrao) location.reload();
                                        } }"
                                    class="flex flex-col gap-2 px-3 py-2.5 sm:flex-row sm:items-center">
                                    <span class="min-w-0 flex-1 break-words font-semibold">{{ $a->nome }} @if ($a->primaria)<span class="text-[11px] font-normal text-ink-mute">(principal)</span>@endif</span>
                                    <label class="flex items-center gap-1.5 text-[12.5px]"><input type="checkbox" x-model="incluida" @change="salvar()" class="rounded border-btn-line text-brand focus:ring-brand"> na visão</label>
                                    <label class="flex items-center gap-1.5 text-[12.5px]"><input type="radio" name="padrao" :checked="padrao" @change="padrao = true; salvar()" class="border-btn-line text-brand focus:ring-brand"> padrão</label>
                                    <input type="text" x-model="palavras" @change="salvar()" maxlength="255" placeholder="palavras: vixcard, vix-id"
                                           class="w-full rounded-control border border-btn-line bg-surface px-2 py-1 text-[12.5px] text-ink placeholder:text-ink-faint focus:border-brand focus:outline-none sm:w-52">
                                    <span x-show="salvo" x-cloak class="text-[11.5px] text-good">salvo</span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="text-[12px] text-ink-mute">"Agenda uma reunião… da VixCard" vai para a agenda cujas palavras aparecem na frase; senão, para a padrão.</p>
                    @endif
                    <div class="flex flex-wrap gap-2" x-data="{ msg: '' }">
                        <button type="button" @click="msg = 'lendo…'; const r = await fetch(@js(route('secretario.google.sincronizar')), { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } }); const j = await r.json().catch(() => ({})); msg = r.ok ? 'lido às ' + j.sincronizado_em + ' (' + j.eventos + ' eventos)' : 'falhou (' + (j.erro || r.status) + ')'"
                                class="h-[34px] rounded-control border border-btn-line px-3 text-[12.5px] font-semibold text-ink-dim hover:text-brand hover:border-brand">Sincronizar agora</button>
                        <form method="POST" action="{{ route('secretario.google.desconectar') }}" onsubmit="return confirm('Desconectar o Google? A autorização é revogada e os eventos guardados aqui são apagados.')">
                            @csrf
                            <button type="submit" class="h-[34px] rounded-control border border-btn-line px-3 text-[12.5px] font-semibold text-ink-mute hover:text-crit hover:border-crit">Desconectar</button>
                        </form>
                        <span x-show="msg" x-text="msg" class="self-center text-[12px] text-ink-mute"></span>
                    </div>
                @endif
            </div>
        </x-painel>
    </div>
</x-app-layout>
