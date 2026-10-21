@php
    use App\Models\SuporteNota;
    $campo = 'rounded-control border border-btn-line bg-canvas py-1 pl-2 pr-7 text-[12.5px] text-ink';
    $botao = 'inline-flex h-[28px] items-center gap-1 whitespace-nowrap rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim hover:border-brand hover:text-brand';
    $tons = ['atendimento' => 'bg-head text-ink-dim', 'cliente' => 'bg-brand/10 text-brand-text', 'procedimento' => 'bg-head text-ink', 'conhecimento' => 'bg-head text-ink', 'empresa' => 'bg-head text-ink'];
    $clienteDe = fn (SuporteNota $n) => collect([$n->cliente ? ($n->cliente->nome_fantasia ?: $n->cliente->nome) : null,
        $n->gestor_cliente_id ? ($gestor[$n->gestor_cliente_id] ?? 'Gestor nº '.$n->gestor_cliente_id) : null])->filter()->unique()->implode(' · ');
@endphp
<x-app-layout>
    <x-slot name="titulo">Suporte WhatsApp</x-slot>
    <x-slot name="contexto"><span>A base do suporte: o que o Pedro consulta, alimentada pelos atendimentos</span></x-slot>

    {{-- docs/specs/suporte-pedro-whatsapp/base-do-suporte.md (T-476): a base mora no Matriz. O Pedro propõe
         o registro de cada atendimento, quem atendeu confirma, e ele vale na próxima conversa do cliente. --}}
    <div class="mx-auto w-full max-w-6xl space-y-4">
        @include('suporte._abas', ['aba' => 'base'])
        @if (session('status'))<x-aviso>{{ session('status') }}</x-aviso>@endif
        @if ($errors->any())<x-aviso tom="critico" :segundos="0">{{ $errors->first() }}</x-aviso>@endif

        {{-- Registros a confirmar: o que o Pedro propôs e ainda não vale. --}}
        <section class="rounded-panel border border-line bg-surface">
            <h2 class="border-b border-line px-4 py-2.5 font-display text-[15px] font-semibold text-ink">Registros a confirmar
                <span class="text-[12.5px] font-normal text-ink-mute">· o Pedro propõe, quem atendeu confere; até lá ele não usa o registro</span></h2>
            @forelse ($propostas as $n)
                <a href="{{ route('suporte.base.ver', $n->id) }}" class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-2 text-[13px] last:border-b-0 hover:bg-chip">
                    <span class="font-mono text-[11.5px] text-ink-faint">{{ $n->codigo }}</span>
                    <span class="min-w-0 flex-1 truncate font-semibold text-ink">{{ $n->titulo }}</span>
                    @if ($n->conversa)
                        <span class="text-[12px] text-ink-mute">{{ $n->conversa->cliente ? ($n->conversa->cliente->nome_fantasia ?: $n->conversa->cliente->nome) : ($n->conversa->nome_perfil ?: $n->conversa->telefoneLegivel()) }}</span>
                    @endif
                    <span class="rounded-badge px-1.5 py-0.5 text-[11px] {{ $n->situacao === 'resolvido' ? 'bg-good-tint text-good' : 'bg-head text-ink-dim' }}">{{ SuporteNota::SITUACOES[$n->situacao] ?? $n->situacao }}</span>
                    <span class="text-[11.5px] text-ink-faint">{{ $n->updated_at->format('d/m H:i') }}</span>
                </a>
            @empty
                <p class="px-4 py-3 text-[12.5px] text-ink-mute">Nenhum registro esperando confirmação.</p>
            @endforelse
        </section>

        <form method="GET" action="{{ route('suporte.base') }}" class="flex flex-wrap items-center gap-2 rounded-panel border border-line bg-surface px-3 py-2">
            <input name="q" value="{{ $filtros['q'] ?? '' }}" maxlength="120" placeholder="Buscar na base…" class="w-56 rounded-control border border-btn-line bg-canvas px-2 py-1 text-[12.5px] text-ink">
            <select name="tipo" class="{{ $campo }}">
                <option value="">Todos os tipos</option>
                @foreach (SuporteNota::TIPOS as $k => $v)<option value="{{ $k }}" @selected(($filtros['tipo'] ?? null) === $k)>{{ $v }}</option>@endforeach
            </select>
            <select name="validacao" class="{{ $campo }}">
                <option value="">Validadas e rascunhos</option>
                <option value="validado" @selected(($filtros['validacao'] ?? null) === 'validado')>Só validadas</option>
                <option value="rascunho" @selected(($filtros['validacao'] ?? null) === 'rascunho')>Só rascunhos</option>
            </select>
            <button class="{{ $botao }}">Filtrar</button>
            @if ($podeEscrever)
                <span class="ml-auto flex flex-wrap items-center gap-1.5 text-[12px] text-ink-mute">Nova nota:
                    @foreach (SuporteNota::TIPOS as $k => $v)
                        <a href="{{ route('suporte.base.nova', ['tipo' => $k]) }}" class="{{ $botao }}">{{ $v }}</a>
                    @endforeach
                </span>
            @endif
        </form>

        <section class="rounded-panel border border-line bg-surface">
            @forelse ($notas as $n)
                <a href="{{ route('suporte.base.ver', $n->id) }}" class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-2 text-[13px] last:border-b-0 hover:bg-chip">
                    <span class="w-[92px] shrink-0"><span class="rounded-badge px-1.5 py-0.5 text-[11px] font-semibold {{ $tons[$n->tipo] ?? '' }}">{{ SuporteNota::TIPOS[$n->tipo] ?? $n->tipo }}</span></span>
                    <span class="min-w-0 flex-1 truncate text-ink">@if ($n->codigo)<span class="font-mono text-[11.5px] text-ink-faint">{{ $n->codigo }}</span> @endif{{ $n->titulo }}</span>
                    @if ($c = $clienteDe($n))<span class="max-w-[220px] truncate text-[12px] text-ink-mute">{{ $c }}</span>@endif
                    @if ($n->tipo === 'atendimento')
                        <span class="rounded-badge px-1.5 py-0.5 text-[11px] {{ $n->situacao === 'resolvido' ? 'bg-good-tint text-good' : 'bg-head text-ink-dim' }}">{{ SuporteNota::SITUACOES[$n->situacao] ?? '—' }}</span>
                    @elseif ($n->geral())
                        <span class="rounded-badge px-1.5 py-0.5 text-[11px] {{ $n->validada() ? 'bg-good-tint text-good' : 'bg-warn-tint text-warn' }}">{{ $n->validada() ? 'Validada' : 'Rascunho' }}</span>
                    @endif
                    <span class="w-[150px] shrink-0 truncate text-right text-[11.5px] text-ink-faint">{{ $n->updated_at->format('d/m/Y') }}{{ $n->atualizadoPor ? ' · '.$n->atualizadoPor->name : ' · Pedro' }}</span>
                </a>
            @empty
                <p class="px-4 py-6 text-center text-[13px] text-ink-mute">
                    {{ array_filter($filtros) ? 'Nada na base com esse filtro.' : 'A base está vazia. Ela cresce com os atendimentos confirmados e com as notas que a equipe escreve.' }}</p>
            @endforelse
        </section>
        {{ $notas->links() }}
    </div>
</x-app-layout>
