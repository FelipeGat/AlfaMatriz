<x-app-layout>
    <x-slot name="titulo">Histórico de tarefas</x-slot>
    <x-slot name="contexto">
        {{ $tarefas->total() < $totalNoHistorico
            ? $tarefas->total().' de '.$totalNoHistorico.' tarefas no histórico'
            : $tarefas->total().' tarefas no histórico' }}
    </x-slot>

    <div class="flex flex-col gap-4">
        @include('tarefas._abas', ['ativa' => 'historico'])

        {{-- Aqui o recorte ganha um campo a mais que o quadro: concluída e
             cancelada são as duas únicas linhas possíveis, e separar uma da
             outra é a primeira pergunta de quem audita. --}}
        @include('tarefas._filtros', [
            'filtros' => $filtros,
            'sistemas' => $sistemas,
            'usuarios' => $usuarios,
            'comDesfecho' => true,
        ])

    {{--
        O quadro é só o trabalho em curso: tarefa encerrada sai de lá e passa a
        viver aqui (AC-082, AC-096). Esta tela é o caminho de auditoria — a
        listagem inteira, sem nenhum recorte por período (AC-097) — e também a
        porta de volta: reabrir uma concluída só era possível pelo menu do card
        no quadro, e sem a coluna Concluída esse caminho deixaria de existir
        (AC-131).
    --}}
    <x-tabela titulo="Histórico completo" sub="sem recorte de período" min="1040px">
        <thead>
            <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                <th class="px-4 py-2.5 font-semibold">Tarefa</th>
                <th class="px-4 py-2.5 font-semibold">Sistema</th>
                <th class="px-4 py-2.5 font-semibold">Responsável</th>
                <th class="px-4 py-2.5 font-semibold">Prioridade</th>
                <th class="px-4 py-2.5 font-semibold">Desfecho / versão</th>
                <th class="px-4 py-2.5 font-semibold text-right">Ciclo</th>
                <th class="px-4 py-2.5 font-semibold">Encerrada em</th>
                <th class="px-4 py-2.5 font-semibold text-right">Ação</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($tarefas as $tarefa)
                <tr class="border-b border-rule hover:bg-chip transition">
                    {{-- Título e resumo na mesma célula, como no card: quem
                         audita precisa saber o QUE era a tarefa, não só o nome. --}}
                    <td class="px-4 py-3">
                        {{-- Número e selo de operacional acompanham o título
                             como no card. O número, porque tarefa encerrada é
                             justamente a que se cita por ele — em release note e
                             em conversa de suporte —, e é aqui que se procura o
                             que já saiu do quadro. O selo, porque sem ele uma
                             tarefa encerrada sem nenhum relatório de teste
                             pareceria falha de processo, e não o processo
                             dela. --}}
                        <p class="text-[13.5px] text-ink">
                            <span class="font-mono text-[10.5px] text-ink-faint">{{ $tarefa->codigo() }}</span>
                            {{ $tarefa->titulo }}
                            @if ($tarefa->tipo === 'operacional')
                                <x-badge class="ml-1 align-middle">Operacional</x-badge>
                            @endif
                        </p>
                        @if (filled($tarefa->resumo))
                            <p class="mt-0.5 text-[12px] leading-snug text-ink-mute">{{ $tarefa->resumo }}</p>
                        @endif

                        {{-- A conversa é justamente o que explica o desfecho:
                             auditar uma tarefa cancelada sem poder ler o que
                             foi dito nela é ler o resultado sem o motivo. Só
                             leitura — para voltar a escrever, reabre-se a
                             tarefa (AC-131). --}}
                        @if (($totalComentarios = $tarefa->comentarios->count()) > 0)
                            <button type="button" x-data
                                    @click="$dispatch('open-modal', 'comentarios-tarefa-{{ $tarefa->id }}')"
                                    class="mt-1 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint hover:text-brand transition">
                                {{ $totalComentarios }} {{ $totalComentarios === 1 ? 'comentário' : 'comentários' }} ▾
                            </button>
                        @endif
                    </td>
                    <td class="px-4 py-3 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                        {{ $tarefa->sistema?->nome ?? 'Sem sistema' }}
                    </td>
                    <td class="px-4 py-3 text-[13px] text-ink-dim">{{ $tarefa->responsavel?->name ?? 'Sem responsável' }}</td>
                    <td class="px-4 py-3">
                        {{-- Mesma escala de tons do card, agora de uma fonte só
                             (AC-126): copiada, ela já divergiu uma vez. --}}
                        <x-badge :tom="\App\Models\Tarefa::TOM_DA_PRIORIDADE[$tarefa->prioridade] ?? 'neutro'">
                            {{ \App\Models\Tarefa::PRIORIDADES[$tarefa->prioridade] ?? $tarefa->prioridade }}
                        </x-badge>
                    </td>
                    <td class="px-4 py-3">
                        <x-badge :tom="$tarefa->status === 'concluida' ? 'bom' : 'critico'">
                            {{ \App\Models\Tarefa::STATUS[$tarefa->status] ?? $tarefa->status }}
                        </x-badge>

                        {{-- A versão é o que liga a tarefa à tag que subiu, e é
                             ela que responde "desde quando o cliente tem isso"
                             — a pergunta que chega pelo suporte e que sem ela
                             só se responde procurando no histórico do git. --}}
                        @if (filled($tarefa->versao_producao))
                            <p class="mt-1 font-mono text-[11.5px] text-ink-mute">{{ $tarefa->versao_producao }}</p>
                        @endif
                    </td>
                    {{-- O número que justifica cronometrar cada etapa: quanto a
                         tarefa levou da criação até encerrar (AC-133). --}}
                    <td class="px-4 py-3 text-right font-mono text-[13px] text-ink-dim whitespace-nowrap"
                        title="Da criação em {{ $tarefa->created_at->format('d/m/Y H:i') }} até o encerramento">
                        {{ ($ciclo = $tarefa->duracaoDoCiclo()) === null ? '—' : \App\Models\Tarefa::duracaoCurta($ciclo) }}
                    </td>
                    <td class="px-4 py-3 font-mono text-[13px] text-ink-dim whitespace-nowrap">
                        {{ $tarefa->updated_at->format('d/m/Y') }}
                        <span class="text-ink-faint">{{ $tarefa->updated_at->format('H:i') }}</span>
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        {{--
                            As duas terminais voltam, e cada uma para o lugar
                            que faz sentido: a concluída volta para a bancada,
                            porque quem a reabre já sabe o que quer mexer; a
                            cancelada volta para a FILA, sem dono, porque
                            desistir dela foi uma decisão e retomá-la é uma nova
                            — provavelmente com outra pessoa e outra prioridade.

                            Cancelada era terminal de verdade, e a única saída
                            era recadastrar a tarefa do zero: um clique de
                            engano custava o histórico inteiro e o cronômetro
                            junto, que é exatamente o que este quadro existe
                            para guardar.
                        --}}
                        @php
                            // Reabrir é mover: quem não pode mover a tarefa
                            // também não a tira do histórico (AC-205).
                            $reabrirPara = $tarefa->motivoParaNaoMover(auth()->user())
                                ? null
                                : (['concluida' => 'em_desenvolvimento', 'cancelada' => 'aberta'][$tarefa->status] ?? null);
                        @endphp

                        @if ($reabrirPara)
                            <form method="POST" action="{{ route('tarefas.mover', $tarefa) }}">
                                @csrf
                                <input type="hidden" name="status" value="{{ $reabrirPara }}">
                                <input type="hidden" name="de_status" value="{{ $tarefa->status }}">
                                <button type="submit"
                                        title="Volta para {{ \App\Models\Tarefa::STATUS[$reabrirPara] }}"
                                        class="h-[28px] px-2.5 rounded-control border border-btn-line
                                               font-medium text-[12px] text-ink-dim hover:text-brand hover:border-brand transition">
                                    Reabrir
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    {{-- Histórico vazio e busca sem resultado são duas coisas
                         diferentes: a primeira não tem o que fazer, a segunda
                         se resolve mudando o recorte. --}}
                    <td colspan="8" class="px-4 py-8 text-center text-[13px] text-ink-mute">
                        {{ $totalNoHistorico > 0
                            ? 'Nenhuma tarefa encontrada com esse recorte.'
                            : 'Nenhuma tarefa concluída ou cancelada ainda.' }}
                    </td>
                </tr>
            @endforelse
        </tbody>

        <x-slot name="rodape">
            <span>{{ $tarefas->count() }} de {{ $tarefas->total() }} tarefas no histórico</span>
            @if ($tarefas->hasPages())
                <span>· página {{ $tarefas->currentPage() }} de {{ $tarefas->lastPage() }}</span>
            @endif
        </x-slot>
    </x-tabela>

    @if ($tarefas->hasPages())
        <div>{{ $tarefas->links() }}</div>
    @endif
    </div>

    {{-- Um modal por linha COM comentário: a página traz 20 tarefas, e montar
         modal vazio para as que não têm conversa seria peso sem leitura. --}}
    @foreach ($tarefas as $tarefa)
        @if ($tarefa->comentarios->isNotEmpty())
            <x-modal name="comentarios-tarefa-{{ $tarefa->id }}" maxWidth="lg">
                <div class="px-6 pt-6 pb-4">
                    <h3 class="font-display font-semibold text-ink text-lg mb-4">{{ $tarefa->titulo }}</h3>
                    @include('tarefas._comentarios', ['tarefa' => $tarefa, 'somenteLeitura' => true])
                </div>
                <div class="px-6 pb-6 flex justify-end">
                    <x-secondary-button x-on:click="$dispatch('close')">Fechar</x-secondary-button>
                </div>
            </x-modal>
        @endif
    @endforeach
</x-app-layout>
