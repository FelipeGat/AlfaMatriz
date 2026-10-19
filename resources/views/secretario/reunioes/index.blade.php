<x-app-layout>
    <x-slot name="titulo">Reuniões</x-slot>
    <x-slot name="contexto"><span>transcritas na sua máquina · nada vira tarefa sem você aprovar</span></x-slot>

    {{-- Reuniões (desenho de 17/10/2026; docs/specs/secretario-reunioes). O áudio
         passa pelo servidor e é APAGADO depois da transcrição, feita na máquina
         do dono; ficam o texto, a análise e as evidências. --}}
    @php
        $campo = 'rounded-control border border-btn-line bg-canvas px-2.5 py-1.5 text-[13px] text-ink focus:border-brand focus:outline-none';
        $empresas = \App\Services\Secretario\CentralDeGestao::EMPRESAS;
        $corStatus = ['aguardando' => 'bg-head text-ink-dim', 'transcrevendo' => 'bg-brand/10 text-brand-text', 'analisando' => 'bg-brand/10 text-brand-text',
            'pronta' => 'bg-warn-tint text-warn', 'revisada' => 'bg-good-tint text-good', 'falhou' => 'bg-crit-tint text-crit'];
        $rotuloStatus = ['pronta' => 'Aguardando revisão'];
        $avatar = ['bg-brand/10 text-brand-text', 'bg-retorno-tint text-retorno', 'bg-good-tint text-good', 'bg-warn-tint text-warn', 'bg-exame-tint text-exame'];
        $url = fn (array $mais) => route('secretario.reunioes.index', array_filter($mais + $filtros, fn ($v) => $v !== null && $v !== ''));
    @endphp
    <div class="mx-auto w-full max-w-7xl space-y-4" x-data="secretarioReunioes()">
        {{-- Gravar --}}
        <section id="gravar" class="scroll-mt-20 rounded-panel border border-line bg-surface p-4">
            <div class="flex flex-wrap items-start gap-3">
                <span class="h-7 w-7 text-brand-text"><x-nav-icon name="speaker-wave" peso="1.7" /></span>
                <div class="min-w-0 flex-1">
                    <h2 class="font-display text-[15px] font-semibold text-ink">Gravar nova reunião</h2>
                    <p class="text-[13.5px] text-ink-mute">Grave, transcreva e receba o resumo com os próximos passos.</p>
                </div>
                <p class="flex max-w-[420px] items-start gap-2 rounded-ctl bg-brand/10 px-3 py-2 text-[12.5px] text-ink-dim">
                    <span class="mt-0.5 h-4 w-4 shrink-0"><x-nav-icon name="cadeado-fechado" /></span>
                    A transcrição é feita na sua máquina. O áudio passa pelo servidor só até ser transcrito e então é apagado.
                </p>
            </div>
            @unless ($maquinaOnline)
                <p class="mt-3 rounded-ctl border border-warn-line bg-warn-tint px-3 py-2 text-[13px] text-warn">Sua máquina está desligada agora: o áudio fica guardado e a transcrição começa quando ela voltar (até 7 dias).</p>
            @endunless
            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-[300px_minmax(0,1fr)_320px]">
                <div class="flex flex-col items-center justify-center rounded-ctl border border-line py-4">
                    <span class="flex h-12 w-12 items-center justify-center rounded-full" :class="gravando ? 'bg-crit text-white animate-pulse' : 'bg-crit-tint text-crit'"><span class="h-6 w-6"><x-nav-icon name="microphone" peso="1.7" /></span></span>
                    <p class="mt-3 font-mono text-[21px] font-semibold text-ink" x-text="tempo || '00:00'"></p>
                    <p class="text-[12.5px] text-ink-mute" x-text="gravando ? 'Gravando…' : (enviando ? 'Enviando…' : 'Pronto para gravar')"></p>
                </div>
                <ul class="space-y-2.5 self-center text-[13.5px] text-ink-dim">
                    <li class="flex gap-2"><span class="h-4 w-4 shrink-0 text-ink-faint"><x-nav-icon name="duvida" /></span>Avise os participantes de que a reunião está sendo gravada (LGPD).</li>
                    <li class="flex gap-2"><span class="h-4 w-4 shrink-0 text-ink-faint"><x-nav-icon name="code" /></span>A transcrição é feita localmente, no seu computador.</li>
                    <li class="flex gap-2"><span class="h-4 w-4 shrink-0 text-ink-faint"><x-nav-icon name="clock" /></span>O áudio é apagado do servidor depois da transcrição.</li>
                    <li class="flex gap-2"><span class="h-4 w-4 shrink-0 text-ink-faint"><x-nav-icon name="check-circle" /></span>Nada vira tarefa sem a sua aprovação.</li>
                </ul>
                <div class="space-y-2.5">
                    <select x-model="empresa" class="{{ $campo }} w-full" title="Empresa da reunião (opcional)">
                        <option value="">Empresa (opcional)</option>
                        @foreach ($empresas as $k => $nome)<option value="{{ $k }}">{{ $nome }}</option>@endforeach
                    </select>
                    <button type="button" x-show="!gravando" @click="gravar()" :disabled="enviando"
                            class="flex h-[30px] w-full items-center justify-center gap-2 rounded-control bg-brand text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright disabled:opacity-60">
                        <i class="h-2.5 w-2.5 rounded-full bg-white"></i> Gravar agora
                    </button>
                    <button type="button" x-show="gravando" x-cloak @click="parar()"
                            class="flex h-[30px] w-full items-center justify-center gap-2 rounded-control border-2 border-crit text-[12.5px] font-semibold text-crit">■ Parar e enviar</button>
                    <label class="flex h-[30px] w-full cursor-pointer items-center justify-center gap-2 rounded-control border border-btn-line text-[12.5px] font-semibold text-ink-dim hover:border-brand hover:text-brand">
                        <span class="h-4 w-4"><x-nav-icon name="upload" /></span> Enviar áudio existente
                        <input type="file" class="sr-only" accept=".mp3,.wav,.m4a,.ogg,.opus,.oga,.webm,audio/*" @change="enviar($event.target.files[0], 'upload'); $event.target.value = ''">
                    </label>
                    <p class="text-[12px] text-ink-mute">MP3, WAV, M4A, OGG, OPUS ou WEBM, até 12 MB (~1 h gravando por aqui).</p>
                    <p x-show="recado" x-cloak x-text="recado" class="text-[12.5px]" :class="erro ? 'text-crit' : 'text-ink-mute'"></p>
                </div>
            </div>
        </section>

        {{-- Filtros --}}
        <form method="GET" class="grid grid-cols-1 gap-2 md:grid-cols-[minmax(0,1fr)_150px_150px_200px_auto]">
            <input type="hidden" name="aba" value="{{ $filtros['aba'] }}">
            <label class="relative">
                <span class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-ink-faint"><x-nav-icon name="search" /></span>
                <input type="search" name="q" value="{{ $busca }}" placeholder="Pesquisar nas reuniões (título, resumo, transcrição…)" class="{{ $campo }} w-full pl-9">
            </label>
            <input type="date" name="de" value="{{ $filtros['de'] ?? '' }}" class="{{ $campo }}" title="De">
            <input type="date" name="ate" value="{{ $filtros['ate'] ?? '' }}" class="{{ $campo }}" title="Até">
            <select name="empresa" class="{{ $campo }}" onchange="this.form.submit()">
                <option value="">Todas as empresas</option>
                @foreach ($empresas as $k => $nome)<option value="{{ $k }}" @selected(($filtros['empresa'] ?? '') === $k)>{{ $nome }}</option>@endforeach
            </select>
            <button class="h-[30px] rounded-control border border-btn-line px-4 text-[12.5px] font-semibold text-ink-dim hover:border-brand hover:text-brand">Filtrar</button>
        </form>

        {{-- Abas e tabela --}}
        <section class="rounded-panel border border-line bg-surface">
            <nav class="flex gap-1 overflow-x-auto border-b border-line px-3 pt-2">
                @foreach (\App\Http\Controllers\SecretarioReunioesController::ABAS as $k => [$rotulo])
                    <a href="{{ $url(['aba' => $k, 'page' => null]) }}" class="flex shrink-0 items-center gap-1.5 border-b-2 px-3 pb-2 pt-1 text-[13px] {{ $filtros['aba'] === $k ? 'border-brand font-semibold text-ink' : 'border-transparent text-ink-mute hover:text-ink' }}">
                        {{ $rotulo }}
                        <span class="rounded-full px-1.5 text-[11px] font-semibold {{ $filtros['aba'] === $k ? 'bg-brand text-on-brand' : 'bg-head text-ink-mute' }}">{{ $contagem[$k] }}</span>
                    </a>
                @endforeach
            </nav>
            <x-tabela min="860px" class="!rounded-none !border-0 !bg-transparent">
                    <thead>
                        <tr class="border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                            <th class="px-4 py-2.5 font-normal">Data / hora</th><th class="px-3 py-2.5 font-normal">Título</th><th class="px-3 py-2.5 font-normal">Participantes</th>
                            <th class="px-3 py-2.5 font-normal">Empresa</th><th class="px-3 py-2.5 font-normal">Status</th><th class="px-3 py-2.5 font-normal">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($reunioes as $r)
                            @php $pessoas = collect((array) $r->participantes)->map(fn ($p) => is_array($p) ? ($p['nome'] ?? '') : (string) $p)->filter()->values(); @endphp
                            <tr class="align-top hover:bg-head/50">
                                <td class="whitespace-nowrap px-4 py-3">
                                    <p class="text-[13.5px] font-semibold text-ink">{{ $r->created_at->format('d/m/Y') }}</p>
                                    <p class="text-[12px] text-ink-mute">{{ $r->created_at->format('H:i') }}{{ $r->duracao_s ? ' · '.max(1, (int) round($r->duracao_s / 60)).' min' : '' }}</p>
                                </td>
                                <td class="px-3 py-3">
                                    <a href="{{ route('secretario.reunioes.mostrar', $r->id) }}" class="text-[13.5px] font-semibold text-ink hover:text-brand">{{ $r->titulo }}</a>
                                    <p class="line-clamp-1 max-w-[420px] text-[12px] text-ink-mute">{{ $r->resumo ?: ($r->tipo_reuniao ? ucfirst($r->tipo_reuniao) : 'Sem resumo ainda') }}</p>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex -space-x-1.5">
                                        @foreach ($pessoas->take(3) as $n => $p)
                                            <span title="{{ $p }}" class="flex h-7 w-7 items-center justify-center rounded-full border-2 border-surface text-[11.5px] font-semibold {{ $avatar[$n % count($avatar)] }}">{{ mb_strtoupper(mb_substr($p, 0, 1)) }}</span>
                                        @endforeach
                                        @if ($pessoas->count() > 3)<span class="flex h-7 w-7 items-center justify-center rounded-full border-2 border-surface bg-head text-[11px] text-ink-mute">+{{ $pessoas->count() - 3 }}</span>@endif
                                        @if ($pessoas->isEmpty())<span class="text-[12px] text-ink-faint">—</span>@endif
                                    </div>
                                </td>
                                <td class="px-3 py-3">
                                    <form method="POST" action="{{ route('secretario.reunioes.empresa', $r->id) }}">@csrf @method('PATCH')
                                        <select name="empresa" onchange="this.form.submit()" class="rounded-control border border-transparent bg-transparent py-1 pl-1 pr-7 text-[13px] text-ink hover:border-btn-line focus:border-brand">
                                            <option value="">—</option>
                                            @foreach ($empresas as $k => $nome)<option value="{{ $k }}" @selected($r->empresa === $k)>{{ $nome }}</option>@endforeach
                                        </select>
                                    </form>
                                </td>
                                <td class="px-3 py-3">
                                    <span class="inline-block whitespace-nowrap rounded-control px-2.5 py-1 text-[12px] font-semibold {{ $corStatus[$r->status] ?? 'bg-head text-ink-dim' }}">{{ $rotuloStatus[$r->status] ?? (\App\Models\SecretarioReuniao::ROTULOS_STATUS[$r->status] ?? $r->status) }}</span>
                                    @if (in_array($r->status, ['aguardando', 'transcrevendo', 'analisando'], true))
                                        {{-- Andamento ao vivo: a barra segue o aviso da máquina; mudou a etapa, recarrega (nunca no meio de uma gravação). --}}
                                        <div class="mt-1.5 w-[180px]" x-data="{ status: @js($r->status), a: @js($r->andamento()) }"
                                             x-init="const t = setInterval(async () => { try { const j = await (await fetch(@js(route('secretario.reunioes.mostrar', ['reuniao' => $r->id, 'formato' => 'json'])), { headers: { Accept: 'application/json' } })).json(); if (j.status !== status) { if (!gravando && !enviando) { clearInterval(t); location.reload(); } return; } a = j.andamento; } catch (e) {} }, 10000)">
                                            <template x-if="a">
                                                <div>
                                                    <div class="h-1.5 overflow-hidden rounded-full bg-head"><div class="h-full rounded-full bg-brand transition-all duration-700" :class="a.percentual === null ? 'w-1/4 animate-pulse' : ''" :style="a.percentual === null ? '' : 'width:' + a.percentual + '%'"></div></div>
                                                    <p class="mt-1 text-[11.5px] text-ink-mute" x-text="a.texto"></p>
                                                </div>
                                            </template>
                                        </div>
                                    @endif
                                    @if ($r->status === 'pronta' && $r->tarefas_pendentes)<p class="mt-1 text-[11.5px] text-warn">{{ $r->tarefas_pendentes }} a revisar</p>@endif
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex gap-1.5">
                                        <a href="{{ route('secretario.reunioes.mostrar', $r->id) }}" title="Abrir" class="flex h-8 w-8 items-center justify-center rounded-full border border-btn-line text-ink-dim hover:border-brand hover:text-brand"><span class="h-4 w-4"><x-nav-icon name="play" /></span></a>
                                        @if (in_array($r->status, ['pronta', 'revisada'], true))
                                            <a href="{{ route('secretario.reunioes.ata', $r->id) }}" title="Ata" class="flex h-8 w-8 items-center justify-center rounded-full border border-btn-line text-ink-dim hover:border-brand hover:text-brand"><span class="h-4 w-4"><x-nav-icon name="document" /></span></a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-6 text-center text-[13.5px] text-ink-mute">{{ $busca !== '' ? 'Nenhuma reunião com “'.$busca.'”.' : 'Nenhuma reunião neste filtro.' }}</td></tr>
                        @endforelse
                    </tbody>
            </x-tabela>
            <div class="px-4 py-2">{{ $reunioes->links() }}</div>
        </section>
    </div>

    <script>
        function secretarioReunioes() {
            return {
                empresa: '', gravando: false, enviando: false, recado: '', erro: false, tempo: '', gravador: null, pedacos: [], inicio: 0, relogio: null,
                async enviar(arquivo, origem) {
                    if (!arquivo) return;
                    if (arquivo.size > 12 * 1024 * 1024) { this.erro = true; this.recado = 'Áudio acima de 12 MB. Grave em OGG/Opus ou divida o arquivo.'; return; }
                    this.enviando = true; this.erro = false; this.recado = 'Enviando…';
                    const corpo = new FormData(); corpo.append('audio', arquivo); corpo.append('origem', origem); if (this.empresa) corpo.append('empresa', this.empresa);
                    const r = await fetch(@json(route('secretario.reunioes.enviar')), { method: 'POST', body: corpo,
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content } });
                    const j = await r.json().catch(() => ({}));
                    this.enviando = false;
                    if (r.ok) { window.location = j.url; return; }
                    this.erro = true; this.recado = (j.errors && Object.values(j.errors)[0][0]) || j.message || 'Não deu certo. Tente de novo.';
                },
                async gravar() {
                    try {
                        const fluxo = await navigator.mediaDevices.getUserMedia({ audio: true });
                        const tipo = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/webm'].find(t => window.MediaRecorder && MediaRecorder.isTypeSupported(t)) || '';
                        // 24 kbps: ~1 h cabe nos 12 MB do servidor (research D7).
                        this.gravador = new MediaRecorder(fluxo, tipo ? { mimeType: tipo, audioBitsPerSecond: 24000 } : { audioBitsPerSecond: 24000 });
                        this.pedacos = [];
                        this.gravador.ondataavailable = e => e.data.size && this.pedacos.push(e.data);
                        this.gravador.onstop = () => {
                            fluxo.getTracks().forEach(t => t.stop());
                            const blob = new Blob(this.pedacos, { type: this.gravador.mimeType || 'audio/webm' });
                            const ext = (this.gravador.mimeType || '').includes('ogg') ? 'ogg' : 'webm';
                            this.enviar(new File([blob], 'gravacao.' + ext, { type: blob.type }), 'gravacao');
                        };
                        this.gravador.start(10000);
                        this.gravando = true; this.inicio = Date.now(); this.erro = false; this.recado = 'Gravando…';
                        this.relogio = setInterval(() => { const s = Math.floor((Date.now() - this.inicio) / 1000); this.tempo = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); }, 1000);
                    } catch (e) { this.erro = true; this.recado = 'Não consegui acessar o microfone neste navegador.'; }
                },
                parar() { clearInterval(this.relogio); this.gravando = false; this.gravador?.stop(); },
            };
        }
    </script>
</x-app-layout>
