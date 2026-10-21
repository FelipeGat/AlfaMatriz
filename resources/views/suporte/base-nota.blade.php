@php
    use App\Models\SuporteNota;
    $campo = 'w-full rounded-control border border-btn-line bg-canvas px-2.5 py-1.5 text-[13px] text-ink focus:border-brand focus:outline-none disabled:opacity-70';
    $rotulo = 'mb-1 block font-mono text-[10.5px] uppercase tracking-caps text-ink-faint';
    $botao = 'inline-flex h-[30px] items-center rounded-control bg-brand px-3.5 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright';
    $botaoLinha = 'inline-flex h-[30px] items-center rounded-control border border-btn-line px-3 text-[12.5px] font-semibold text-ink-dim hover:border-brand hover:text-brand';
    $n = $nota;
    $proposta = $n->estado === 'proposta';
    $tipo = SuporteNota::TIPOS[$n->tipo] ?? $n->tipo;
@endphp
<x-app-layout>
    <x-slot name="titulo">Suporte WhatsApp</x-slot>
    <x-slot name="contexto"><span>{{ $n->exists ? $tipo.' nº '.$n->id : 'Nova nota: '.mb_strtolower($tipo) }}</span></x-slot>

    {{-- base-do-suporte.md (T-476). Procedimento, conhecimento e empresa só valem como instrução para o
         Pedro depois do "Validar"; o registro de atendimento só vale depois de confirmado por quem atendeu. --}}
    <div class="mx-auto w-full max-w-4xl space-y-4">
        @include('suporte._abas', ['aba' => 'base'])
        @if (session('status'))<x-aviso>{{ session('status') }}</x-aviso>@endif
        @if ($errors->any())<x-aviso tom="critico" :segundos="0">{{ $errors->first() }}</x-aviso>@endif

        <p class="text-[12.5px]"><a href="{{ route('suporte.base') }}" class="text-brand-text hover:underline">← Base do suporte</a></p>

        @if ($proposta)
            <x-aviso tom="atencao" :segundos="0">Registro proposto pelo Pedro, ainda fora da base. Confira o que ele escreveu, corrija o que for preciso e confirme.
                @if ($quemConfirma) Quem confirma é quem atendeu: {{ $quemConfirma->name }}.@else Ninguém da equipe atendeu esta conversa: qualquer pessoa do suporte confirma.@endif
                O relato é o que o cliente disse; em "Verificado" vai só o que alguém viu ou testou.</x-aviso>
        @elseif ($n->estado === 'descartada')
            <x-aviso tom="atencao" :segundos="0">Registro descartado{{ $n->confirmadoPor ? ' por '.$n->confirmadoPor->name : '' }}: não faz parte da base.</x-aviso>
        @endif

        <form method="POST" action="{{ $n->exists ? route('suporte.base.salvar', $n->id) : route('suporte.base.criar') }}" class="rounded-panel border border-line bg-surface">
            @csrf
            @unless ($n->exists)<input type="hidden" name="tipo" value="{{ $n->tipo }}">@endunless
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-2.5">
                <h2 class="min-w-0 flex-1 font-display text-[15px] font-semibold text-ink">{{ $tipo }}@if ($n->codigo) <span class="font-mono text-[12.5px] font-normal text-ink-faint">{{ $n->codigo }}</span>@endif</h2>
                @if ($n->geral() && $n->exists)
                    <span class="rounded-badge px-1.5 py-0.5 text-[11px] font-semibold {{ $n->validada() ? 'bg-good-tint text-good' : 'bg-warn-tint text-warn' }}">
                        {{ $n->validada() ? 'Validada por '.($n->validadoPor?->name ?? '—').' em '.$n->validado_em?->format('d/m/Y') : 'Rascunho: o Pedro só cita como possibilidade' }}</span>
                @endif
            </div>
            <div class="grid gap-3 px-4 py-3 md:grid-cols-2">
                <label class="md:col-span-2"><span class="{{ $rotulo }}">Título</span>
                    <input name="titulo" required maxlength="160" value="{{ old('titulo', $n->titulo) }}" class="{{ $campo }}" @disabled(! $podeEscrever)></label>

                @if (in_array($n->tipo, ['procedimento', 'conhecimento'], true))
                    <label class="md:col-span-2"><span class="{{ $rotulo }}">Quando usar (as palavras que o cliente usaria)</span>
                        <input name="quando_usar" maxlength="500" value="{{ old('quando_usar', $n->quando_usar) }}" placeholder="Ex.: portão que não fecha, volta sozinho ou para no meio" class="{{ $campo }}" @disabled(! $podeEscrever)></label>
                @endif

                @if (in_array($n->tipo, ['cliente', 'atendimento'], true))
                    <label><span class="{{ $rotulo }}">Cliente no Matriz</span>
                        <select name="cliente_id" class="{{ $campo }}" @disabled(! $podeEscrever)>
                            <option value="">—</option>
                            @foreach ($clientes as $cl)<option value="{{ $cl->id }}" @selected((int) old('cliente_id', $n->cliente_id) === $cl->id)>{{ $cl->nome_fantasia ?: $cl->nome }}</option>@endforeach
                        </select></label>
                    <label><span class="{{ $rotulo }}">Nº do cliente no Gestor{{ $n->gestor_cliente_id && isset($gestor[$n->gestor_cliente_id]) ? ' · '.$gestor[$n->gestor_cliente_id] : '' }}</span>
                        <input name="gestor_cliente_id" type="number" min="1" value="{{ old('gestor_cliente_id', $n->gestor_cliente_id) }}" class="{{ $campo }}" @disabled(! $podeEscrever)></label>
                @endif

                @if ($n->tipo === 'atendimento')
                    <label><span class="{{ $rotulo }}">Situação</span>
                        <select name="situacao" class="{{ $campo }}" @disabled(! $podeEscrever)>
                            @foreach (SuporteNota::SITUACOES as $k => $v)<option value="{{ $k }}" @selected(old('situacao', $n->situacao) === $k)>{{ $v }}</option>@endforeach
                        </select></label>
                    <div class="self-end pb-1.5 text-[12.5px] text-ink-mute">
                        @if ($n->conversa)<a href="{{ route('suporte.conversas', $n->conversa_id) }}" class="text-brand-text hover:underline">Ver a conversa</a>@endif
                        @if ($n->tarefa_id) · <a href="{{ route('tarefas.index', ['tarefa' => $n->tarefa_id]) }}" class="text-brand-text hover:underline">T-{{ $n->tarefa_id }}</a>@endif
                    </div>
                    <label class="md:col-span-2"><span class="{{ $rotulo }}">Relato do cliente (o que ele contou; não é fato)</span>
                        <textarea name="relato" rows="4" maxlength="4000" class="{{ $campo }}" @disabled(! $podeEscrever)>{{ old('relato', $n->relato) }}</textarea></label>
                    <label class="md:col-span-2"><span class="{{ $rotulo }}">Verificado (só o que alguém viu ou testou, e quem)</span>
                        <textarea name="verificado" rows="3" maxlength="4000" class="{{ $campo }}" @disabled(! $podeEscrever)>{{ old('verificado', $n->verificado) }}</textarea></label>
                    <label class="md:col-span-2"><span class="{{ $rotulo }}">Solução e resultado (o que resolveu e quem confirmou)</span>
                        <textarea name="solucao" rows="3" maxlength="4000" class="{{ $campo }}" @disabled(! $podeEscrever)>{{ old('solucao', $n->solucao) }}</textarea></label>
                @else
                    <label class="md:col-span-2"><span class="{{ $rotulo }}">Texto</span>
                        <textarea name="corpo" rows="14" maxlength="20000" required class="{{ $campo }}" @disabled(! $podeEscrever)>{{ old('corpo', $n->corpo) }}</textarea></label>
                @endif

                <div class="flex flex-wrap items-center gap-2 md:col-span-2">
                    @if ($podeEscrever)
                        @if ($proposta)
                            <button formaction="{{ route('suporte.base.confirmar', $n->id) }}" class="{{ $botao }}">Confirmar registro</button>
                            <button type="submit" form="descartar" class="{{ $botaoLinha }}" onclick="return confirm('Descartar este registro? Ele não entra na base.')">Descartar</button>
                        @else
                            <button class="{{ $botao }}">{{ $n->exists ? 'Salvar' : 'Criar nota' }}</button>
                        @endif
                    @endif
                    @if ($n->exists && ! $proposta && $n->estado === 'ativa')
                        @if ($n->geral() && ! $n->validada() && $podeValidar)
                            <button type="submit" form="validar" class="{{ $botaoLinha }}">Validar</button>
                        @endif
                        @if ($n->tipo === 'atendimento' && filled($n->solucao) && $podeEscrever)
                            <button type="submit" form="conhecimento" class="{{ $botaoLinha }}" title="Cria um rascunho de conhecimento a partir deste atendimento, para valer para outros clientes">Virar conhecimento</button>
                        @endif
                        @if ($podeValidar)
                            <button type="submit" form="apagar" class="{{ $botaoLinha }}" onclick="return confirm('Apagar esta nota da base?')">Apagar</button>
                        @endif
                    @endif
                </div>
                @if ($n->exists)
                    <p class="text-[11.5px] text-ink-faint md:col-span-2">
                        Criada {{ $n->autor ? 'por '.$n->autor->name : 'pelo Pedro' }} em {{ $n->created_at->format('d/m/Y H:i') }}
                        @if ($n->confirmadoPor && $n->estado === 'ativa') · confirmada por {{ $n->confirmadoPor->name }} em {{ $n->confirmado_em?->format('d/m/Y H:i') }}@endif
                        @if ($n->atualizadoPor) · alterada por {{ $n->atualizadoPor->name }} em {{ $n->updated_at->format('d/m/Y H:i') }}@endif
                        @if ($n->origem) · veio do atendimento <a href="{{ route('suporte.base.ver', $n->origem_id) }}" class="text-brand-text hover:underline">{{ $n->origem->titulo }}</a>@endif
                    </p>
                @endif
            </div>
        </form>
        @if ($n->exists)
            <form id="descartar" method="POST" action="{{ route('suporte.base.descartar', $n->id) }}" class="hidden">@csrf</form>
            <form id="validar" method="POST" action="{{ route('suporte.base.validar', $n->id) }}" class="hidden">@csrf</form>
            <form id="conhecimento" method="POST" action="{{ route('suporte.base.conhecimento', $n->id) }}" class="hidden">@csrf</form>
            <form id="apagar" method="POST" action="{{ route('suporte.base.apagar', $n->id) }}" class="hidden">@csrf</form>
        @endif
    </div>
</x-app-layout>
