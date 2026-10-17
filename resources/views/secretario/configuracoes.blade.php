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
            briefing: @js((bool) $preferencia->briefing),
            briefing_hora: @js($preferencia->briefing_hora),
            briefing_dias: @js(array_map('intval', (array) $preferencia->briefing_dias)),
            salvo: '', erro: '',
            async salvar() {
                this.salvo = ''; this.erro = '';
                const r = await fetch(@js(route('secretario.configuracoes.salvar')), { method: 'PUT',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ auto_confirmar: this.auto_confirmar, antecedencia_padrao: Number(this.antecedencia_padrao), telegram: this.telegram,
                        briefing: this.briefing, briefing_hora: this.briefing_hora, briefing_dias: this.briefing_dias }) });
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
                <div class="space-y-2 border-t border-line pt-3">
                    <label class="flex items-start gap-3">
                        <input type="checkbox" x-model="briefing" class="mt-1 rounded border-btn-line text-brand focus:ring-brand">
                        <span>Briefing diário <span class="block text-[12px] text-ink-mute">O resumo do dia no Telegram e no Meu Dia, no horário abaixo.</span></span>
                    </label>
                    <div x-show="briefing" class="flex flex-col gap-2 pl-7 sm:flex-row sm:items-center sm:gap-4">
                        <label class="flex items-center gap-2">às <input type="time" x-model="briefing_hora" class="rounded-control border border-btn-line bg-surface px-2 py-1 text-ink focus:border-brand focus:outline-none"></label>
                        <div class="flex flex-wrap gap-1">
                            @foreach ([1 => 'seg', 2 => 'ter', 3 => 'qua', 4 => 'qui', 5 => 'sex', 6 => 'sáb', 7 => 'dom'] as $n => $dia)
                                <button type="button" @click="briefing_dias.includes({{ $n }}) ? briefing_dias = briefing_dias.filter(d => d !== {{ $n }}) : briefing_dias.push({{ $n }})"
                                        :class="briefing_dias.includes({{ $n }}) ? 'bg-brand text-on-brand border-brand' : 'border-btn-line text-ink-dim'"
                                        class="h-[30px] rounded-control border px-2 text-[12px] font-semibold">{{ $dia }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>
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

        {{-- Etapa 5: caixas de e-mail (só leitura) e o retrato do Gestor. A senha não volta para a tela. --}}
        <x-painel titulo="E-mail (somente leitura)">
            <div class="space-y-3 text-[13.5px] text-ink" x-data="{ f: { rotulo: '', host: '', porta: 993, seguranca: 'ssl', usuario: '', senha: '', pasta_entrada: 'INBOX', pasta_enviados: '' }, recado: '', erro: false, ocupado: false,
                    async enviar(rota) { this.ocupado = true; this.recado = ''; const r = await fetch(rota, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify(this.f) });
                        const j = await r.json().catch(() => ({})); this.ocupado = false; this.erro = !r.ok;
                        if (r.status === 201) { location.reload(); return; }
                        this.recado = r.ok ? ('Conectou: ' + j.mensagens + ' mensagem(ns) na entrada. Pastas: ' + (j.pastas || []).slice(0, 12).join(', ')) : (j.erro || (j.errors && Object.values(j.errors)[0][0]) || 'Não deu certo.'); } }">
                <p class="text-[12.5px] text-ink-mute">O Matriz só lê: abre a pasta em modo leitura, não marca nada como lido e não envia e-mail. Guarda remetente, assunto e data — o texto da mensagem não fica guardado. Em Gmail/Outlook, use uma <b>senha de app</b>.</p>
                @foreach ($caixas as $c)
                    <div class="flex flex-col gap-1 rounded-ctl border border-line px-3 py-2 sm:flex-row sm:items-center sm:gap-3">
                        <span class="min-w-0 flex-1 break-words"><b>{{ $c->rotulo }}</b> · {{ $c->usuario }} · {{ $c->host }}</span>
                        <span class="text-[12px] {{ $c->situacao === 'erro' ? 'text-crit' : 'text-ink-mute' }}">{{ $c->situacao === 'erro' ? 'erro: '.$c->erro : 'lida '.($c->lida_em?->format('d/m H:i') ?? '—') }}</span>
                        <form method="POST" action="{{ route('secretario.emails.remover', $c->id) }}" onsubmit="return confirm('Remover a caixa? A senha e as mensagens guardadas dela serão apagadas.')">@csrf @method('DELETE')
                            <button class="h-[28px] rounded-control border border-btn-line px-2 text-[12px] font-semibold text-ink-mute hover:border-crit hover:text-crit">Remover</button></form>
                    </div>
                @endforeach
                <div class="grid gap-2 sm:grid-cols-2">
                    <input x-model="f.rotulo" placeholder="Rótulo (ex.: VixCard comercial)" class="rounded-control border border-btn-line bg-surface px-2 py-1.5 text-ink">
                    <input x-model="f.host" placeholder="Servidor IMAP (ex.: imap.gmail.com)" class="rounded-control border border-btn-line bg-surface px-2 py-1.5 text-ink">
                    <div class="flex gap-2"><input x-model="f.porta" type="number" class="w-24 rounded-control border border-btn-line bg-surface px-2 py-1.5 text-ink">
                        <select x-model="f.seguranca" class="flex-1 rounded-control border border-btn-line bg-surface px-2 py-1.5 text-ink"><option value="ssl">SSL (993)</option><option value="tls">STARTTLS (143)</option></select></div>
                    <input x-model="f.usuario" placeholder="Usuário (e-mail)" autocomplete="off" class="rounded-control border border-btn-line bg-surface px-2 py-1.5 text-ink">
                    <input x-model="f.senha" type="password" placeholder="Senha (de app)" autocomplete="new-password" class="rounded-control border border-btn-line bg-surface px-2 py-1.5 text-ink">
                    <input x-model="f.pasta_entrada" placeholder="Pasta de entrada" class="rounded-control border border-btn-line bg-surface px-2 py-1.5 text-ink">
                    <input x-model="f.pasta_enviados" placeholder="Pasta de enviados (para saber o que já foi respondido)" class="rounded-control border border-btn-line bg-surface px-2 py-1.5 text-ink sm:col-span-2">
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" @click="enviar(@js(route('secretario.emails.testar')))" :disabled="ocupado" class="h-[34px] rounded-control border border-btn-line px-3 text-[12.5px] font-semibold text-ink-dim hover:border-brand hover:text-brand">Testar</button>
                    <button type="button" @click="enviar(@js(route('secretario.emails.cadastrar')))" :disabled="ocupado" class="h-[34px] rounded-control bg-brand px-3 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright">Ligar caixa</button>
                    <span x-show="recado" x-text="recado" class="text-[12.5px]" :class="erro ? 'text-crit' : 'text-good'"></span>
                </div>
            </div>
        </x-painel>

        <x-painel titulo="Gestor (pela sua máquina)">
            <p class="text-[13.5px] text-ink">
                @if ($gestor)
                    Último retrato: {{ $gestor->lido_em->format('d/m H:i') }} (máquina {{ $gestor->maquina }}){{ $gestor->velho() ? ' — desatualizado' : '' }}.
                @else
                    Sua máquina ainda não mandou o retrato do Gestor.
                @endif
            </p>
            <p class="mt-1 text-[12px] text-ink-mute">A máquina lê o Gestor pela ponte de produção, só leitura (o script recusa gravar e abre o banco em modo leitura), de hora em hora e antes do briefing.</p>
        </x-painel>
        @include('secretario._regras')
    </div>
</x-app-layout>
