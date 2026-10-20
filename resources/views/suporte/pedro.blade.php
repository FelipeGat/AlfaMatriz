@php
    use App\Models\SuporteRoteiro;
    $campo = 'w-full rounded-control border border-btn-line bg-canvas px-2.5 py-1.5 text-[13px] text-ink focus:border-brand focus:outline-none disabled:opacity-70';
    $rotulo = 'mb-1 block font-mono text-[10.5px] uppercase tracking-caps text-ink-faint';
    $botao = 'inline-flex h-[30px] items-center rounded-control bg-brand px-3.5 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright';
    $botaoLinha = 'inline-flex h-[30px] items-center rounded-control border border-btn-line px-3 text-[12.5px] font-semibold text-ink-dim hover:border-brand hover:text-brand';
    $tons = ['baixa' => 'bg-head text-ink-dim', 'media' => 'bg-head text-ink', 'alta' => 'bg-warn-tint text-warn', 'critica' => 'bg-crit-tint text-crit'];
@endphp
<x-app-layout>
    <x-slot name="titulo">Suporte WhatsApp</x-slot>
    <x-slot name="contexto"><span>O que o Pedro sabe do negócio: apresentação, roteiros e cobrança</span></x-slot>

    {{-- docs/specs/suporte-pedro-whatsapp/conhecimento.md: escrito pelo Felipe, vale para todas as
         conversas a partir da próxima mensagem. O Pedro só manda link que estiver aqui. --}}
    <div class="mx-auto w-full max-w-5xl space-y-4">
        @include('suporte._abas', ['aba' => 'pedro'])
        @if (session('status'))<x-aviso>{{ session('status') }}</x-aviso>@endif
        @if ($errors->any())<x-aviso tom="critico" :segundos="0">{{ $errors->first() }}</x-aviso>@endif
        @unless ($podeEditar)
            <p class="text-[12.5px] text-ink-mute">Você vê o que o Pedro sabe; só o Felipe altera.</p>
        @endunless

        {{-- A empresa --}}
        <section class="rounded-panel border border-line bg-surface">
            <h2 class="border-b border-line px-4 py-2.5 font-display text-[15px] font-semibold text-ink">A empresa</h2>
            <form method="POST" action="{{ route('suporte.pedro.empresa') }}" class="grid gap-3 px-4 py-3 md:grid-cols-2">
                @csrf
                <label class="md:col-span-2"><span class="{{ $rotulo }}">Apresentação (quem somos, ramos de atuação)</span>
                    <textarea name="apresentacao" rows="5" maxlength="4000" class="{{ $campo }}" @disabled(! $podeEditar)
                              placeholder="Ex.: Somos o Grupo… Atuamos em segurança eletrônica (portões, câmeras, interfones), sistemas de gestão…">{{ old('apresentacao', $ajustes['apresentacao']) }}</textarea></label>
                <label class="md:col-span-2"><span class="{{ $rotulo }}">Sites (um por linha)</span>
                    <textarea name="site" rows="3" maxlength="1000" class="{{ $campo }}" @disabled(! $podeEditar) placeholder="https://…">{{ old('site', $ajustes['site']) }}</textarea></label>
                <label class="md:col-span-2"><span class="{{ $rotulo }}">Contatos por setor (um por linha)</span>
                    <textarea name="contatos" rows="4" maxlength="2000" class="{{ $campo }}" @disabled(! $podeEditar) placeholder="Financeiro — (27) …">{{ old('contatos', $ajustes['contatos']) }}</textarea></label>
                <label><span class="{{ $rotulo }}">Portal do cliente</span>
                    <input name="portal" maxlength="500" value="{{ old('portal', $ajustes['portal']) }}" placeholder="https://…" class="{{ $campo }}" @disabled(! $podeEditar)></label>
                <label><span class="{{ $rotulo }}">Cobrar os críticos a cada (minutos)</span>
                    <input name="cobranca_minutos" type="number" min="15" max="1440" value="{{ old('cobranca_minutos', $ajustes['cobranca_minutos']) }}" class="{{ $campo }}" @disabled(! $podeEditar)></label>
                <label class="md:col-span-2"><span class="{{ $rotulo }}">Folders (um por linha: nome e link)</span>
                    <textarea name="folders" rows="3" maxlength="3000" class="{{ $campo }}" @disabled(! $podeEditar)
                              placeholder="Segurança eletrônica — https://…&#10;AlfaControl — https://…">{{ old('folders', $ajustes['folders']) }}</textarea></label>
                @if ($podeEditar)<div class="md:col-span-2"><button class="{{ $botao }}">Salvar apresentação</button></div>@endif
            </form>
        </section>

        {{-- Roteiros --}}
        <section class="space-y-3">
            <div class="flex items-center gap-2">
                <h2 class="flex-1 font-display text-[15px] font-semibold text-ink">Roteiros <span class="text-[12.5px] font-normal text-ink-mute">· como o Pedro reconhece cada situação e o que responde</span></h2>
            </div>
            @php $novo = new SuporteRoteiro(['ativo' => true]); @endphp
            @foreach ($roteiros->push($novo) as $r)
                @continue(! $r->exists && ! $podeEditar)
                <form id="roteiro-{{ $r->id ?? 'novo' }}" method="POST" action="{{ $r->exists ? route('suporte.pedro.roteiro', $r->id) : route('suporte.pedro.roteiro.novo') }}"
                      x-data="{ aberto: false }"
                      class="rounded-panel border {{ $r->exists && ! $r->ativo ? 'border-dashed border-line opacity-70' : 'border-line' }} bg-surface">
                    @csrf
                    <button type="button" x-on:click="aberto = ! aberto" class="flex w-full items-center gap-2 px-4 py-2.5 text-left">
                        @if ($r->exists)
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[14px] font-semibold text-ink">{{ $r->titulo }}</span>
                                <span class="block truncate text-[12px] text-ink-mute">{{ $r->quando }}</span>
                            </span>
                            @if ($r->prioridade)<span class="rounded-badge px-1.5 py-0.5 text-[11px] font-semibold {{ $tons[$r->prioridade] ?? '' }}">{{ SuporteRoteiro::PRIORIDADES[$r->prioridade] }}</span>@endif
                            @if ($r->prazo_horas)<span class="rounded-badge bg-head px-1.5 py-0.5 text-[11px] text-ink-dim">até {{ $r->prazo_horas }} h</span>@endif
                            <span class="rounded-badge bg-head px-1.5 py-0.5 text-[11px] text-ink-dim">{{ $r->abre_atendimento ? 'Abre atendimento' : 'Só responde' }}</span>
                            @unless ($r->ativo)<span class="text-[11px] text-ink-faint">desligado</span>@endunless
                        @else
                            <span class="flex-1 text-[13.5px] font-semibold text-brand-text">+ Novo roteiro</span>
                        @endif
                    </button>
                    <div x-show="aberto" x-cloak class="grid gap-3 border-t border-line px-4 py-3 md:grid-cols-4">
                        <label class="md:col-span-3"><span class="{{ $rotulo }}">Título</span>
                            <input name="titulo" required maxlength="80" value="{{ $r->titulo }}" class="{{ $campo }}" @disabled(! $podeEditar)></label>
                        <label><span class="{{ $rotulo }}">Ordem</span>
                            <input name="ordem" type="number" min="0" max="999" value="{{ $r->ordem }}" class="{{ $campo }}" @disabled(! $podeEditar)></label>
                        <label class="md:col-span-4"><span class="{{ $rotulo }}">Quando usar</span>
                            <textarea name="quando" required rows="2" maxlength="1000" class="{{ $campo }}" placeholder="Ex.: portão que não abre, não fecha ou travou" @disabled(! $podeEditar)>{{ $r->quando }}</textarea></label>
                        <label class="md:col-span-4"><span class="{{ $rotulo }}">O que fazer</span>
                            <textarea name="orientacao" rows="3" maxlength="3000" class="{{ $campo }}" @disabled(! $podeEditar)>{{ $r->orientacao }}</textarea></label>
                        <label class="md:col-span-4"><span class="{{ $rotulo }}">Mensagem padrão (o Pedro adapta só o necessário)</span>
                            <textarea name="mensagem" rows="3" maxlength="1500" class="{{ $campo }}" @disabled(! $podeEditar)>{{ $r->mensagem }}</textarea></label>
                        <label><span class="{{ $rotulo }}">Prioridade</span>
                            <select name="prioridade" class="{{ $campo }}" @disabled(! $podeEditar)>
                                <option value="">—</option>
                                @foreach (SuporteRoteiro::PRIORIDADES as $k => $v)<option value="{{ $k }}" @selected($r->prioridade === $k)>{{ $v }}</option>@endforeach
                            </select></label>
                        <label><span class="{{ $rotulo }}">Prazo para atender (h)</span>
                            <input name="prazo_horas" type="number" min="1" max="720" value="{{ $r->prazo_horas }}" class="{{ $campo }}" @disabled(! $podeEditar)></label>
                        <label class="flex items-center gap-2 self-end pb-1.5 text-[13px] text-ink"><input type="checkbox" name="abre_atendimento" value="1" @checked($r->abre_atendimento) @disabled(! $podeEditar)> Abre atendimento</label>
                        <label class="flex items-center gap-2 self-end pb-1.5 text-[13px] text-ink"><input type="checkbox" name="ativo" value="1" @checked($r->ativo) @disabled(! $podeEditar)> Ativo</label>
                        <div class="flex flex-wrap items-center gap-2 md:col-span-4">
                            @if ($podeEditar)
                                <button class="{{ $botao }}">{{ $r->exists ? 'Salvar roteiro' : 'Criar roteiro' }}</button>
                                @if ($r->exists)
                                    <button type="submit" form="apagar-{{ $r->id }}" class="{{ $botaoLinha }}" onclick="return confirm('Apagar o roteiro &quot;{{ e($r->titulo) }}&quot;?')">Apagar</button>
                                @endif
                            @endif
                            @if ($r->exists && $r->atualizadoPor)<span class="text-[11.5px] text-ink-faint">Alterado por {{ $r->atualizadoPor->name }} em {{ $r->updated_at->format('d/m H:i') }}</span>@endif
                        </div>
                    </div>
                </form>
                @if ($r->exists && $podeEditar)
                    <form id="apagar-{{ $r->id }}" method="POST" action="{{ route('suporte.pedro.roteiro.apagar', $r->id) }}" class="hidden">@csrf</form>
                @endif
            @endforeach
        </section>
    </div>
</x-app-layout>
