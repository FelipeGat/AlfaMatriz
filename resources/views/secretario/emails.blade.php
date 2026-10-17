<x-app-layout>
    <x-slot name="titulo">Mensagens</x-slot>
    <x-slot name="contexto"><span>só leitura · nada é enviado daqui</span></x-slot>

    {{-- Etapa 5 (docs/specs/secretario-emails-gestor). Só metadados guardados;
         o corpo é lido na hora quando a IA precisa, e nunca fica. --}}
    <div class="mx-auto w-full max-w-3xl space-y-4" x-data="{ recado: '' }">
        @if ($contas->isEmpty())
            <p class="rounded-panel border border-line bg-subtle px-4 py-4 text-[13.5px] text-ink-mute">
                Nenhuma caixa ligada. Ligue em <a href="{{ route('secretario.configuracoes') }}" class="font-semibold text-brand-text">Secretário → Configurações → E-mail</a>.
            </p>
        @else
            <div class="flex flex-wrap gap-2 text-[12.5px]">
                @foreach (['importantes' => 'Importantes', 'aguardando' => 'Aguardando resposta', 'todas' => 'Todas (7 dias)'] as $k => $rot)
                    <a href="{{ route('secretario.emails.index', ['ver' => $k]) }}"
                       class="h-[32px] inline-flex items-center rounded-control border px-3 font-semibold {{ $filtro === $k ? 'border-brand text-brand-text' : 'border-btn-line text-ink-dim hover:text-brand' }}">{{ $rot }}</a>
                @endforeach
            </div>
            <p class="text-[12px] text-ink-mute">{{ app(\App\Services\Secretario\ConsultaDeEmails::class)->rodape(auth()->user()) }}</p>
            <p x-show="recado" x-cloak x-text="recado" class="rounded-panel border border-line bg-subtle px-4 py-2.5 text-[13px] text-ink"></p>

            <x-painel titulo="Mensagens" solto>
                @forelse ($emails as $e)
                    <div class="border-b border-line px-4 py-3 last:border-0">
                        <p class="text-[14px] font-semibold text-ink break-words">{{ $e->assunto }}</p>
                        <p class="text-[12px] text-ink-mute">
                            {{ $e->de_nome ? $e->de_nome.' · ' : '' }}{{ $e->de_email }} · {{ $e->recebido_em->format('d/m H:i') }} · {{ $e->conta?->rotulo }}
                            @if ($e->tipo !== 'outro') · {{ $e->tipo }} @endif
                            @if ($e->urgencia === 'alta') · <span class="text-crit">urgente</span> @endif
                            @if ($e->pede_retorno && ! $e->respondido) · <span class="text-warn">aguardando resposta</span> @endif
                            @if ($e->respondido) · <span class="text-good">respondido</span> @endif
                        </p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @php $acao = fn ($rota) => "fetch('".$rota."', { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } }).then(r => r.json()).then(j => recado = j.mensagem || 'Feito.')"; @endphp
                            <button type="button" @click="{{ $acao(route('secretario.emails.rascunho', $e->id)) }}" class="h-[30px] rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim hover:border-brand hover:text-brand">Preparar resposta (IA)</button>
                            <button type="button" @click="{{ $acao(route('secretario.emails.lembrar', $e->id)) }}" class="h-[30px] rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim hover:border-brand hover:text-brand">Lembrar de responder amanhã</button>
                            @if ($link = $e->link())
                                <a href="{{ $link }}" target="_blank" rel="noopener noreferrer" class="h-[30px] inline-flex items-center rounded-control border border-btn-line px-2.5 text-[12px] font-semibold text-ink-dim hover:border-brand hover:text-brand">Abrir no Gmail</a>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="px-4 py-4 text-[13px] text-ink-mute">Nada neste filtro nos últimos 7 dias.</p>
                @endforelse
                <div class="px-4 py-2">{{ $emails->links() }}</div>
            </x-painel>
        @endif
    </div>
</x-app-layout>
