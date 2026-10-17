<x-app-layout>
    <x-slot name="titulo">Secretário · Configurações</x-slot>
    <x-slot name="contexto"><span>personalize como o seu secretário trabalha</span></x-slot>
    <x-slot name="acoes">
        <a href="{{ route('secretario.meu-dia') }}"
           class="h-[34px] px-3 inline-flex items-center gap-1.5 rounded-control border border-btn-line text-ink-dim text-[12.5px] font-semibold hover:text-brand hover:border-brand transition whitespace-nowrap">
            Meu Dia
        </a>
    </x-slot>

    {{-- Configurações (desenho de 17/10/2026): seções recolhíveis à esquerda,
         o estado real do Secretário à direita. Só aparece canal que existe:
         o sino é sempre ligado; resumo por e-mail não existe (o Matriz não
         envia e-mail). --}}
    @php
        $chave = 'relative inline-flex h-[24px] w-[44px] shrink-0 items-center rounded-full transition';
        $estado = fn (string $tom, string $texto) => '<span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[12px] font-semibold '
            .['ok' => 'bg-good-tint text-good', 'alerta' => 'bg-warn-tint text-warn', 'erro' => 'bg-crit-tint text-crit', 'neutro' => 'bg-head text-ink-mute'][$tom].'">'.e($texto).'</span>';
        $googleEstado = ! $googleConfigurado && ! $google ? ['neutro', 'Não configurado'] : (! $google ? ['erro', 'Não conectado'] : ($google->conectada() ? ['ok', 'Conectado'] : ['erro', 'Reconectar']));
        $emailEstado = $caixas->isEmpty() ? ['neutro', 'Nenhuma caixa'] : ($caixas->contains('situacao', 'erro') ? ['erro', 'Com erro'] : ['ok', $caixas->count().' caixa(s)']);
        $gestorEstado = ! $gestor ? ['neutro', 'Sem leitura'] : ($gestor->velho() ? ['alerta', 'Desatualizado'] : ['ok', 'Lido às '.$gestor->lido_em->format('H:i')]);
    @endphp
    <div x-data="{
            auto_confirmar: @js((bool) $preferencia->auto_confirmar),
            antecedencia_padrao: @js((int) $preferencia->antecedencia_padrao),
            telegram: @js((bool) $preferencia->telegram),
            briefing: @js((bool) $preferencia->briefing),
            briefing_hora: @js($preferencia->briefing_hora),
            briefing_dias: @js(array_map('intval', (array) $preferencia->briefing_dias)),
            aberto: { comportamento: true, google: true, email: true, gestor: false },
            salvo: '', erro: '',
            async salvar() {
                this.salvo = ''; this.erro = '';
                const r = await fetch(@js(route('secretario.configuracoes.salvar')), { method: 'PUT',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ auto_confirmar: this.auto_confirmar, antecedencia_padrao: Number(this.antecedencia_padrao), telegram: this.telegram,
                        briefing: this.briefing, briefing_hora: this.briefing_hora, briefing_dias: this.briefing_dias }) });
                r.ok ? this.salvo = 'Salvo.' : this.erro = 'Não deu certo. Confira os valores.';
            } }" class="mx-auto grid w-full max-w-7xl grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
        <div class="min-w-0 space-y-4">
            <div>
                <h1 class="font-display text-[28px] font-semibold leading-tight text-ink">Configurações do Secretário</h1>
                <p class="mt-1 text-[14px] text-ink-mute">Defina como o AlfaMatriz deve trabalhar, avisar e se comunicar com você.</p>
            </div>
            @if (session('status'))
                <x-aviso>{{ session('status') }}</x-aviso>
            @endif
            @if ($errors->has('google'))
                <x-aviso tom="critico" :segundos="0">{{ $errors->first('google') }}</x-aviso>
            @endif

            {{-- Comportamento e avisos --}}
            <section class="rounded-panel border border-line bg-surface">
                <button type="button" @click="aberto.comportamento = !aberto.comportamento" class="flex w-full items-center gap-2.5 border-b border-line px-4 py-3 text-left">
                    <span class="h-5 w-5 text-ink-dim"><x-nav-icon name="bell" peso="1.7" /></span>
                    <span class="flex-1 font-display text-[16px] font-semibold text-ink">Comportamento e avisos</span>
                    <span class="text-ink-faint" x-text="aberto.comportamento ? '▴' : '▾'"></span>
                </button>
                <div x-show="aberto.comportamento" class="space-y-4 px-4 py-4 text-[13.5px] text-ink">
                    <div class="flex items-start gap-3">
                        <button type="button" role="switch" :aria-checked="auto_confirmar" @click="auto_confirmar = !auto_confirmar" class="{{ $chave }}" :class="auto_confirmar ? 'bg-brand' : 'bg-btn-line'"><span class="inline-block h-[18px] w-[18px] rounded-full bg-white shadow transition" :class="auto_confirmar ? 'translate-x-[23px]' : 'translate-x-[3px]'"></span></button>
                        <span><b class="font-semibold">Gravar sem perguntar quando o pedido for inequívoco</b>
                            <span class="block text-[12px] text-ink-mute">Ex.: "daqui a 10 minutos", "amanhã às 9h". Pedido sem hora, com hora ajustada ou vindo da IA continua pedindo confirmação.</span></span>
                    </div>
                    <label class="flex flex-wrap items-center gap-2 pl-[56px]">
                        <span>Avisar compromissos com antecedência de</span>
                        <select x-model.number="antecedencia_padrao" class="rounded-control border border-btn-line bg-canvas px-2 py-1.5 text-ink focus:border-brand focus:outline-none">
                            @foreach ([0, 5, 10, 15, 30, 45, 60, 90, 120, 180, 1440] as $m)<option value="{{ $m }}">{{ $m }}</option>@endforeach
                        </select> minutos
                    </label>
                    <div class="flex items-start gap-3">
                        <button type="button" role="switch" :aria-checked="telegram" @click="telegram = !telegram" class="{{ $chave }}" :class="telegram ? 'bg-brand' : 'bg-btn-line'"><span class="inline-block h-[18px] w-[18px] rounded-full bg-white shadow transition" :class="telegram ? 'translate-x-[23px]' : 'translate-x-[3px]'"></span></button>
                        <span><b class="font-semibold">Enviar lembretes também no Telegram</b><span class="block text-[12px] text-ink-mute">O sino do AlfaMatriz recebe sempre.</span></span>
                    </div>
                    <div class="flex items-start gap-3">
                        <button type="button" role="switch" :aria-checked="briefing" @click="briefing = !briefing" class="{{ $chave }}" :class="briefing ? 'bg-brand' : 'bg-btn-line'"><span class="inline-block h-[18px] w-[18px] rounded-full bg-white shadow transition" :class="briefing ? 'translate-x-[23px]' : 'translate-x-[3px]'"></span></button>
                        <span class="min-w-0 flex-1"><b class="font-semibold">Briefing diário</b><span class="block text-[12px] text-ink-mute">O resumo do dia no Telegram e no Meu Dia, no horário abaixo.</span>
                            <span x-show="briefing" class="mt-2 flex flex-wrap items-center gap-2">
                                às <input type="time" x-model="briefing_hora" class="rounded-control border border-btn-line bg-canvas px-2 py-1 text-ink focus:border-brand focus:outline-none">
                                @foreach ([1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'] as $n => $dia)
                                    <button type="button" @click="briefing_dias.includes({{ $n }}) ? briefing_dias = briefing_dias.filter(d => d !== {{ $n }}) : briefing_dias.push({{ $n }})"
                                            :class="briefing_dias.includes({{ $n }}) ? 'bg-brand text-on-brand border-brand' : 'border-btn-line text-ink-dim'"
                                            class="h-[32px] rounded-control border px-2.5 text-[12.5px] font-semibold">{{ $dia }}</button>
                                @endforeach
                            </span>
                        </span>
                    </div>
                    <div class="flex items-center gap-3 border-t border-line pt-3">
                        <button type="button" @click="salvar()" class="h-[36px] rounded-control bg-brand px-4 text-[13px] font-semibold text-on-brand hover:bg-brand-bright">Salvar</button>
                        <span x-show="salvo" x-text="salvo" class="text-[12.5px] text-good"></span>
                        <span x-show="erro" x-text="erro" class="text-[12.5px] text-crit"></span>
                    </div>
                </div>
            </section>

            {{-- Etapa 2: a conta Google da própria pessoa. Tokens nunca chegam à tela. --}}
            <section id="google" class="scroll-mt-20 rounded-panel border border-line bg-surface">
                <button type="button" @click="aberto.google = !aberto.google" class="flex w-full items-center gap-2.5 border-b border-line px-4 py-3 text-left">
                    <span class="h-5 w-5 text-ink-dim"><x-nav-icon name="calendar" peso="1.7" /></span>
                    <span class="flex-1 font-display text-[16px] font-semibold text-ink">Google Agenda</span>
                    {!! $estado(...$googleEstado) !!}
                    <span class="text-ink-faint" x-text="aberto.google ? '▴' : '▾'"></span>
                </button>
                <div x-show="aberto.google" class="px-4 py-4">
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
                </div>
            </section>

            {{-- Etapa 5: caixas de e-mail (só leitura). A senha não volta para a tela. --}}
            <section id="emails" class="scroll-mt-20 rounded-panel border border-line bg-surface">
                <button type="button" @click="aberto.email = !aberto.email" class="flex w-full items-center gap-2.5 border-b border-line px-4 py-3 text-left">
                    <span class="h-5 w-5 text-ink-dim"><x-nav-icon name="envelope" peso="1.7" /></span>
                    <span class="flex-1 font-display text-[16px] font-semibold text-ink">E-mail (somente leitura)</span>
                    {!! $estado(...$emailEstado) !!}
                    <span class="text-ink-faint" x-text="aberto.email ? '▴' : '▾'"></span>
                </button>
                <div x-show="aberto.email" class="px-4 py-4">
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
                    <input x-model="f.rotulo" placeholder="Rótulo (ex.: VixCard comercial)" class="rounded-control border border-btn-line bg-canvas px-2.5 py-2 text-[13.5px] text-ink">
                    <input x-model="f.host" placeholder="Servidor IMAP (ex.: imap.gmail.com)" class="rounded-control border border-btn-line bg-canvas px-2.5 py-2 text-[13.5px] text-ink">
                    <div class="flex gap-2"><input x-model="f.porta" type="number" class="w-24 rounded-control border border-btn-line bg-canvas px-2.5 py-2 text-[13.5px] text-ink">
                        <select x-model="f.seguranca" class="flex-1 rounded-control border border-btn-line bg-canvas px-2.5 py-2 text-[13.5px] text-ink"><option value="ssl">SSL (993)</option><option value="tls">STARTTLS (143)</option></select></div>
                    <input x-model="f.usuario" placeholder="Usuário (e-mail)" autocomplete="off" class="rounded-control border border-btn-line bg-canvas px-2.5 py-2 text-[13.5px] text-ink">
                    <input x-model="f.senha" type="password" placeholder="Senha (de app)" autocomplete="new-password" class="rounded-control border border-btn-line bg-canvas px-2.5 py-2 text-[13.5px] text-ink">
                    <input x-model="f.pasta_entrada" placeholder="Pasta de entrada" class="rounded-control border border-btn-line bg-canvas px-2.5 py-2 text-[13.5px] text-ink">
                    <input x-model="f.pasta_enviados" placeholder="Pasta de enviados (para saber o que já foi respondido)" class="rounded-control border border-btn-line bg-canvas px-2.5 py-2 text-[13.5px] text-ink sm:col-span-2">
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" @click="enviar(@js(route('secretario.emails.testar')))" :disabled="ocupado" class="h-[34px] rounded-control border border-btn-line px-3 text-[12.5px] font-semibold text-ink-dim hover:border-brand hover:text-brand">Testar</button>
                    <button type="button" @click="enviar(@js(route('secretario.emails.cadastrar')))" :disabled="ocupado" class="h-[34px] rounded-control bg-brand px-3 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright">Ligar caixa</button>
                    <span x-show="recado" x-text="recado" class="text-[12.5px]" :class="erro ? 'text-crit' : 'text-good'"></span>
                </div>
            </div>
                </div>
            </section>

            <section class="rounded-panel border border-line bg-surface">
                <button type="button" @click="aberto.gestor = !aberto.gestor" class="flex w-full items-center gap-2.5 border-b border-line px-4 py-3 text-left">
                    <span class="h-5 w-5 text-ink-dim"><x-nav-icon name="building" peso="1.7" /></span>
                    <span class="flex-1 font-display text-[16px] font-semibold text-ink">Gestor (pela sua máquina)</span>
                    {!! $estado(...$gestorEstado) !!}
                    <span class="text-ink-faint" x-text="aberto.gestor ? '▴' : '▾'"></span>
                </button>
                <div x-show="aberto.gestor" x-cloak class="px-4 py-4 text-[13.5px] text-ink">
                    @if ($gestor)
                        Último retrato: {{ $gestor->lido_em->format('d/m H:i') }} (máquina {{ $gestor->maquina }}){{ $gestor->velho() ? ' — desatualizado' : '' }}.
                    @else
                        Sua máquina ainda não mandou o retrato do Gestor.
                    @endif
                    <p class="mt-1 text-[12px] text-ink-mute">A máquina lê o Gestor pela ponte de produção, só leitura (o script recusa gravar e abre o banco em modo leitura), de hora em hora e antes do briefing.</p>
                </div>
            </section>

            @include('secretario._regras')
        </div>

        {{-- Lateral: o estado real --}}
        <aside class="min-w-0 space-y-4">
            <section class="rounded-panel border border-line bg-surface">
                <div class="flex items-center gap-2 border-b border-line px-4 py-3">
                    <span class="h-5 w-5 text-brand-text"><x-nav-icon name="sun" peso="1.7" /></span>
                    <h2 class="flex-1 font-display text-[16px] font-semibold text-ink">Status do Secretário</h2>
                    {!! $estado('ok', 'Ativo') !!}
                </div>
                <ul class="divide-y divide-line">
                    @foreach ([
                        ['envelope', 'Coleta de e-mails', $caixas->isEmpty() ? ['neutro', 'Desligada'] : ($caixas->contains('situacao', 'erro') ? ['erro', 'Com erro'] : ['ok', 'Ativo'])],
                        ['calendar', 'Google Agenda', $googleEstado],
                        ['chat', 'Telegram', $telegramPareado ? ['ok', 'Conectado'] : ['erro', 'Não ligado']],
                        ['microphone', 'Transcrição de áudio', $maquinaOnline ? ['ok', 'Disponível'] : ['alerta', 'Máquina desligada']],
                        ['building', 'Gestor', $gestorEstado],
                    ] as [$icone, $rotulo, $st])
                        <li class="flex items-center gap-3 px-4 py-2.5">
                            <span class="h-5 w-5 text-ink-dim"><x-nav-icon :name="$icone" peso="1.7" /></span>
                            <span class="flex-1 text-[13.5px] text-ink">{{ $rotulo }}</span>
                            {!! $estado(...$st) !!}
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="rounded-panel border border-line bg-surface">
                <div class="flex items-center gap-2 border-b border-line px-4 py-3">
                    <span class="h-5 w-5 text-brand-text"><x-nav-icon name="bell" peso="1.7" /></span>
                    <h2 class="font-display text-[16px] font-semibold text-ink">Canais de notificação</h2>
                </div>
                <ul class="divide-y divide-line">
                    <li class="flex items-center gap-3 px-4 py-3">
                        <span class="min-w-0 flex-1"><b class="block text-[13.5px] font-semibold text-ink">No sistema</b><span class="text-[12px] text-ink-mute">O sino do AlfaMatriz, sempre ligado</span></span>
                        <span class="{{ $chave }} bg-brand opacity-60" title="Sempre ligado"><span class="inline-block h-[18px] w-[18px] translate-x-[23px] rounded-full bg-white shadow"></span></span>
                    </li>
                    <li class="flex items-center gap-3 px-4 py-3">
                        <span class="min-w-0 flex-1"><b class="block text-[13.5px] font-semibold text-ink">Telegram</b><span class="text-[12px] text-ink-mute">Lembretes, briefing e resumos de acompanhamento</span></span>
                        <button type="button" role="switch" :aria-checked="telegram" @click="telegram = !telegram; salvar()" class="{{ $chave }}" :class="telegram ? 'bg-brand' : 'bg-btn-line'"><span class="inline-block h-[18px] w-[18px] rounded-full bg-white shadow transition" :class="telegram ? 'translate-x-[23px]' : 'translate-x-[3px]'"></span></button>
                    </li>
                </ul>
                @unless ($telegramPareado)
                    <p class="border-t border-line px-4 py-2.5 text-[12px] text-warn">O Telegram ainda não está ligado: Central de IA → Ajustes → Telegram.</p>
                @endunless
            </section>

            <section class="rounded-panel border border-line bg-surface" x-data="{ dicas: true }">
                <button type="button" @click="dicas = !dicas" class="flex w-full items-center gap-2 border-b border-line px-4 py-3 text-left">
                    <span class="h-5 w-5 text-brand-text"><x-nav-icon name="duvida" peso="1.7" /></span>
                    <span class="flex-1 font-display text-[16px] font-semibold text-ink">Dicas e boas práticas</span>
                    <span class="text-ink-faint" x-text="dicas ? '▴' : '▾'"></span>
                </button>
                <ol x-show="dicas" class="space-y-2.5 px-4 py-3 text-[13px] text-ink-dim">
                    @foreach (['Conecte o Google Agenda para o Secretário ver conflitos de horário.', 'Mantenha o Telegram ligado para receber lembretes e o briefing no celular.',
                        'Use senha de app nas caixas de e-mail (Gmail, Outlook): a senha normal é recusada.', 'Revise as tarefas das reuniões: nada vira tarefa sem a sua aprovação.'] as $n => $dica)
                        <li class="flex gap-2.5"><span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-line text-[11px] font-semibold">{{ $n + 1 }}</span>{{ $dica }}</li>
                    @endforeach
                </ol>
            </section>
        </aside>
    </div>
</x-app-layout>
