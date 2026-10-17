<x-app-layout>
    <x-slot name="titulo">Memória</x-slot>
    <x-slot name="contexto"><span>só o seu histórico · fonte, data e link em cada linha</span></x-slot>

    {{-- Etapa 6 (docs/specs/secretario-memoria). A memória é o que as outras
         telas já guardam; aqui só se busca. Nada é afirmado sem a fonte. --}}
    @php
        $rotulos = ['decisao' => 'Decisões de reunião', 'tarefa' => 'Tarefas de reunião', 'pendencia' => 'Pendências de reunião', 'reuniao' => 'Reuniões',
            'item' => 'Itens do Secretário', 'email' => 'E-mails', 'gestor' => 'Gestor', 'transcricao' => 'Citado em transcrição', 'conversa' => 'Conversas da Central'];
    @endphp
    <div class="mx-auto w-full max-w-3xl space-y-4">
        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif

        <form method="GET" class="flex gap-2">
            <input type="search" name="q" value="{{ $busca }}" maxlength="120" placeholder="Pessoa, empresa ou assunto (ex.: RFID, Alexandre, orçamento)"
                   class="min-w-0 flex-1 rounded-control border border-btn-line bg-surface px-3 py-2 text-[13.5px] text-ink placeholder:text-ink-faint focus:border-brand focus:outline-none">
            <button class="h-[38px] rounded-control border border-btn-line px-3 text-[13px] font-semibold text-ink-dim hover:border-brand hover:text-brand">Buscar</button>
        </form>

        @if ($busca === '')
            <p class="rounded-panel border border-line bg-subtle px-4 py-4 text-[13.5px] text-ink-mute">
                A busca olha reuniões (resumo, decisões, tarefas e transcrição), itens do Secretário, e-mails (só remetente e assunto),
                o último retrato do Gestor e as conversas da Central. Pelo chat ou Telegram também dá para perguntar:
                “o que ficou decidido na reunião de ontem?”, “o que combinamos com o cliente X?”, “quais compromissos assumi?”.
            </p>
        @elseif (! $termos)
            <p class="rounded-panel border border-line bg-subtle px-4 py-4 text-[13.5px] text-ink-mute">Escreva ao menos uma palavra com significado (um nome, uma empresa, um assunto).</p>
        @elseif ($total === 0)
            <p class="rounded-panel border border-line bg-subtle px-4 py-4 text-[13.5px] text-ink-mute">Nenhum registro com “{{ $busca }}”. Tente outras palavras.</p>
        @else
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-[12.5px] text-ink-mute">{{ $total }} registro(s) com “{{ $busca }}”, os mais relevantes e recentes primeiro.</p>
                <form method="POST" action="{{ route('secretario.memoria.sintese') }}">
                    @csrf
                    <input type="hidden" name="q" value="{{ $busca }}">
                    <button class="h-[32px] rounded-control border border-btn-line px-3 text-[12.5px] font-semibold text-ink-dim hover:border-brand hover:text-brand">Resumir com a minha IA</button>
                </form>
            </div>

            @foreach ($grupos as $fonte => $g)
                <x-painel :titulo="$rotulos[$fonte].' ('.($g['total'] > $g['itens']->count() ? $g['itens']->count().' de '.$g['total'] : $g['total']).')'" solto>
                    <ul class="divide-y divide-line">
                        @foreach ($g['itens'] as $x)
                            <li class="px-4 py-2.5">
                                <p class="text-[13.5px] text-ink break-words">
                                    @if ($x['link'])
                                        <a href="{{ $x['link'] }}" @if (str_starts_with($x['link'], 'https://mail.')) target="_blank" rel="noopener noreferrer" @endif class="hover:text-brand hover:underline">{{ $x['texto'] }}</a>
                                    @else
                                        {{ $x['texto'] }}
                                    @endif
                                </p>
                                <p class="text-[11.5px] text-ink-mute">
                                    {{ $x['rotulo'] }} · {{ $x['data']?->format('d/m/Y') ?? 's/data' }}
                                    @if ($x['aberto']) · <span class="text-warn">em aberto</span> @endif
                                    @if ($x['natureza'] !== 'fato') · <span class="text-ink-dim">{{ $x['natureza'] }}</span> @endif
                                </p>
                            </li>
                        @endforeach
                    </ul>
                </x-painel>
            @endforeach
        @endif
    </div>
</x-app-layout>
