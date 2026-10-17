<x-app-layout>
    <x-slot name="titulo">Reuniões</x-slot>
    <x-slot name="contexto"><span>transcritas na sua máquina · nada vira tarefa sem você aprovar</span></x-slot>

    {{-- Etapa 4 (docs/specs/secretario-reunioes). O áudio é apagado depois da
         transcrição; ficam o texto, a análise e as evidências. --}}
    <div class="mx-auto w-full max-w-3xl space-y-4" x-data="secretarioReunioes()">
        @unless ($maquinaOnline)
            <p class="rounded-panel border border-warn-line bg-warn-tint px-4 py-2.5 text-[13px] text-warn">Sua máquina está desligada agora: o áudio fica guardado e a transcrição começa quando ela voltar.</p>
        @endunless

        <x-painel titulo="Nova reunião">
            <p class="mb-3 rounded-ctl border border-line bg-surface px-3 py-2 text-[12.5px] text-ink-mute">
                Antes de gravar, avise os participantes de que a reunião está sendo gravada (LGPD).
            </p>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <label class="inline-flex h-[38px] cursor-pointer items-center rounded-control border border-btn-line px-3 text-[13px] font-semibold text-ink-dim hover:border-brand hover:text-brand">
                    Enviar áudio
                    <input type="file" class="sr-only" accept=".mp3,.wav,.m4a,.ogg,.opus,.oga,.webm,audio/*" @change="enviar($event.target.files[0], 'upload'); $event.target.value = ''">
                </label>
                <button type="button" x-show="!gravando" @click="gravar()" :disabled="enviando"
                        class="h-[38px] rounded-control bg-brand px-4 text-[13px] font-semibold text-on-brand hover:bg-brand-bright disabled:opacity-60">● Gravar agora</button>
                <button type="button" x-show="gravando" x-cloak @click="parar()"
                        class="h-[38px] rounded-control border border-crit px-4 text-[13px] font-semibold text-crit">■ Parar e enviar <span x-text="tempo"></span></button>
                <span class="text-[12px] text-ink-mute">MP3, WAV, M4A, OGG, OPUS ou WEBM, até 12 MB (a gravação daqui cabe ~1 h).</span>
            </div>
            <p x-show="recado" x-cloak x-text="recado" class="mt-2 text-[12.5px]" :class="erro ? 'text-crit' : 'text-ink-mute'"></p>
        </x-painel>

        <form method="GET" class="flex gap-2">
            <input type="search" name="q" value="{{ $busca }}" placeholder="Pesquisar nas reuniões (título, resumo, transcrição)"
                   class="min-w-0 flex-1 rounded-control border border-btn-line bg-surface px-3 py-2 text-[13.5px] text-ink placeholder:text-ink-faint focus:border-brand focus:outline-none">
            <button class="h-[38px] rounded-control border border-btn-line px-3 text-[13px] font-semibold text-ink-dim hover:border-brand hover:text-brand">Pesquisar</button>
        </form>

        <x-painel titulo="Reuniões" solto>
            @if ($reunioes->isEmpty())
                <p class="px-4 py-4 text-[13px] text-ink-mute">{{ $busca !== '' ? 'Nenhuma reunião com “'.$busca.'”.' : 'Nenhuma reunião ainda.' }}</p>
            @else
                <ul class="divide-y divide-line">
                    @foreach ($reunioes as $r)
                        <li>
                            <a href="{{ route('secretario.reunioes.mostrar', $r->id) }}" class="flex flex-col gap-0.5 px-4 py-3 hover:bg-surface sm:flex-row sm:items-center sm:gap-3">
                                <span class="min-w-0 flex-1 break-words text-[14px] font-semibold text-ink">{{ $r->titulo }}</span>
                                <span class="text-[12px] text-ink-mute">{{ $r->created_at->format('d/m H:i') }}{{ $r->tipo_reuniao ? ' · '.$r->tipo_reuniao : '' }}</span>
                                <span class="text-[12px] {{ $r->status === 'falhou' ? 'text-crit' : ($r->status === 'pronta' ? 'text-warn' : 'text-ink-mute') }}">
                                    {{ \App\Models\SecretarioReuniao::ROTULOS_STATUS[$r->status] ?? $r->status }}{{ $r->status === 'pronta' && $r->tarefas_pendentes ? ' · '.$r->tarefas_pendentes.' a revisar' : '' }}
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
                <div class="px-4 py-2">{{ $reunioes->links() }}</div>
            @endif
        </x-painel>
    </div>

    <script>
        function secretarioReunioes() {
            return {
                gravando: false, enviando: false, recado: '', erro: false, tempo: '', gravador: null, pedacos: [], inicio: 0, relogio: null,
                async enviar(arquivo, origem) {
                    if (!arquivo) return;
                    if (arquivo.size > 12 * 1024 * 1024) { this.erro = true; this.recado = 'Áudio acima de 12 MB. Grave em OGG/Opus ou divida o arquivo.'; return; }
                    this.enviando = true; this.erro = false; this.recado = 'Enviando…';
                    const corpo = new FormData(); corpo.append('audio', arquivo); corpo.append('origem', origem);
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
