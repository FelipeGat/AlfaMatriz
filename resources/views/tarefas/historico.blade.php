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
                {{-- A linha inteira é a porta do histórico completo (AC-293):
                     quem audita clica na TAREFA, não num alvo pequeno dentro
                     dela. Os controles que já agem por conta própria — o
                     Reabrir — seguram o clique com `@click.stop` (AC-294). --}}
                <tr x-data @click="$dispatch('open-modal', 'historico-tarefa-{{ $tarefa->id }}')"
                    class="border-b border-rule hover:bg-chip transition cursor-pointer">
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
                            <span class="font-mono text-[11.5px] font-semibold text-ink-dim">{{ $tarefa->codigo() }}</span>
                            {{ $tarefa->titulo }}
                            @if ($tarefa->tipo === 'operacional')
                                <x-badge class="ml-1 align-middle">Operacional</x-badge>
                            @endif
                        </p>
                        @if (filled($tarefa->resumo))
                            <p class="mt-0.5 text-[12px] leading-snug text-ink-mute">{{ $tarefa->resumo }}</p>
                        @endif

                        @php
                            // O rótulo anuncia o que há para ler — conversa,
                            // anexo ou os dois. Era um botão com modal próprio;
                            // agora é só o anúncio: a linha inteira abre o
                            // histórico completo, e um segundo alvo clicável
                            // dentro dela disputaria o mesmo gesto.
                            $totalComentarios = $tarefa->comentarios->count();
                            $totalAnexos = $tarefa->anexos->count();

                            $oQueHa = collect([
                                $totalComentarios > 0
                                    ? $totalComentarios.' '.($totalComentarios === 1 ? 'comentário' : 'comentários')
                                    : null,
                                $totalAnexos > 0
                                    ? $totalAnexos.' '.($totalAnexos === 1 ? 'anexo' : 'anexos')
                                    : null,
                            ])->filter();
                        @endphp

                        @if ($oQueHa->isNotEmpty())
                            <p class="mt-1 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                                {{ $oQueHa->implode(' · ') }}
                            </p>
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
                            <form method="POST" action="{{ route('tarefas.mover', $tarefa) }}" @click.stop>
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
            {{ $tarefas->links() }}
        </x-slot>
    </x-tabela>
    </div>

    {{-- O histórico completo de cada linha (US-082): linha do tempo, relatórios
         de teste, checklist, anexos e conversa — tudo somente leitura, no
         mesmo modal. Ele existe para TODA linha, e não só para quem tem
         comentário ou anexo como antes: a linha do tempo sempre existe, e é
         justamente a tarefa "vazia" que só ela explica. As demais seções só
         aparecem quando têm o que dizer (AC-300) — seção vazia é peso sem
         leitura. --}}
    @foreach ($tarefas as $tarefa)
        <x-modal name="historico-tarefa-{{ $tarefa->id }}" maxWidth="lg">
            <div class="px-6 pt-6 pb-4 flex flex-col gap-5">
                <div>
                    <h3 class="font-display font-semibold text-ink text-lg">
                        <span class="font-mono text-[12px] font-semibold text-ink-dim">{{ $tarefa->codigo() }}</span>
                        {{ $tarefa->titulo }}
                    </h3>
                    @if (filled($tarefa->resumo))
                        <p class="mt-0.5 text-[12.5px] leading-snug text-ink-mute">{{ $tarefa->resumo }}</p>
                    @endif
                </div>

                @include('tarefas._linha-do-tempo', ['tarefa' => $tarefa])

                @if ($tarefa->relatoriosTeste->isNotEmpty())
                    <div>
                        <div class="flex items-center gap-2 mb-2.5">
                            <h4 class="flex-1 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Relatórios de teste</h4>
                        </div>
                        <ul class="flex flex-col gap-2">
                            @foreach ($tarefa->relatoriosTeste->sortBy('created_at') as $relatorio)
                                <li>
                                    <p class="flex items-center gap-2">
                                        <x-badge :tom="$relatorio->aprovado ? 'bom' : 'critico'">
                                            {{ $relatorio->aprovado ? 'Aprovado' : 'Reprovado' }}
                                        </x-badge>
                                        {{-- Quem testou é a assinatura do veredito (AC-304).
                                             Relatório antigo não tem autor — e não inventa um. --}}
                                        @if ($relatorio->autor)
                                            <span class="text-[11.5px] text-ink-mute">por {{ $relatorio->autor->name }}</span>
                                        @endif
                                        <span class="font-mono text-[11.5px] text-ink-faint">{{ $relatorio->created_at->format('d/m/Y H:i') }}</span>
                                    </p>
                                    @if (filled($relatorio->notas))
                                        <p class="mt-0.5 text-[12px] leading-snug text-ink-mute">{{ $relatorio->notas }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Listagem própria, e não a `_checklist`: aquela é o modo de
                     edição e vive presa ao Alpine do quadro. Aqui o checklist
                     é registro — o que ficou feito e o que não ficou. --}}
                @if ($tarefa->itens->isNotEmpty())
                    @php $progresso = $tarefa->progressoDoChecklist(); @endphp
                    <div>
                        <div class="flex items-center gap-2 mb-2.5">
                            <h4 class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Checklist</h4>
                            <span class="font-mono text-[10.5px] text-ink-mute">
                                {{ $progresso['feitos'] }}/{{ $progresso['total'] }}
                            </span>
                        </div>
                        <ul class="flex flex-col gap-1.5">
                            @foreach ($tarefa->itens->sortBy('ordem') as $item)
                                <li class="flex items-start gap-2 text-[12.5px]">
                                    <span class="mt-[5px] h-[7px] w-[7px] rounded-full shrink-0"
                                          style="background: rgb(var(--{{ $item->feito ? 'good' : 'line' }}))"></span>
                                    <span class="{{ $item->feito ? 'text-ink-faint line-through' : 'text-ink' }}">{{ $item->texto }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($tarefa->anexos->isNotEmpty())
                    @include('tarefas._anexos', ['tarefa' => $tarefa, 'somenteLeitura' => true])
                @endif

                @if ($tarefa->comentarios->isNotEmpty())
                    @include('tarefas._comentarios', ['tarefa' => $tarefa, 'somenteLeitura' => true])
                @endif
            </div>
            <div class="px-6 pb-6 flex justify-end">
                <x-secondary-button x-on:click="$dispatch('close')">Fechar</x-secondary-button>
            </div>
        </x-modal>
    @endforeach
</x-app-layout>
