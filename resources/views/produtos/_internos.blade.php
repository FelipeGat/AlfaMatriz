{{--
    Os sistemas de dentro de casa.

    Nenhuma das colunas que a lista de produtos tem cabe aqui: interno não tem
    MRR, ARR, base ativa, ticket nem churn — repeti-las com traço em todas as
    linhas daria a entender que o número existe e está faltando. O que ele tem
    é trabalho aberto, e é essa a coluna que sustenta a lista.

    `min-width` menor que a de produtos (620px contra 1080px) porque são seis
    colunas curtas: manter a largura da outra tabela só criaria rolagem
    horizontal para atravessar espaço vazio.
--}}
<x-tabela min="620px">
    <thead>
        <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
            <th class="px-4 py-2.5 font-semibold">Sistema</th>
            <th class="px-4 py-2.5 font-semibold">Responsável</th>
            <th class="px-4 py-2.5 font-semibold">Versão</th>
            <th class="px-4 py-2.5 font-semibold">Tarefas abertas</th>
            <th class="px-4 py-2.5 font-semibold">Status</th>
            <th class="px-4 py-2.5 font-semibold text-right">Ações</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($internos as $interno)
            <tr class="border-b border-rule hover:bg-chip transition {{ $interno->ativo ? '' : 'opacity-[0.72]' }}">
                <td class="px-4 py-3">
                    <span class="block font-display text-[14.5px] font-semibold text-ink truncate">{{ $interno->nome }}</span>
                    <span class="block font-mono text-[11px] text-ink-faint truncate">{{ $interno->slug }}</span>
                </td>

                <td class="px-4 py-3 text-[13px] text-ink-dim truncate">
                    {{ $interno->responsavel ?: '—' }}
                </td>

                <td class="px-4 py-3 font-mono text-[13px] text-ink-dim whitespace-nowrap">
                    {{ $interno->versao ?: '—' }}
                </td>

                {{-- Zero em tinta apagada, e não no mesmo peso dos demais: numa
                     coluna em que o número é o único sinal de vida, um zero
                     destacado disputa o olho com as linhas que têm trabalho. --}}
                <td class="px-4 py-3">
                    <span class="font-sans text-[13.5px] tabular {{ $interno->tarefas_count > 0 ? 'text-ink' : 'text-ink-faint' }}">
                        {{ $interno->tarefas_count }}
                    </span>
                </td>

                <td class="px-4 py-3">
                    <x-badge :tom="$interno->ativo ? 'bom' : 'neutro'" ponto>
                        {{ $interno->ativo ? 'Ativo' : 'Inativo' }}
                    </x-badge>
                </td>

                <td class="px-4 py-3">
                    <div class="flex items-center justify-end gap-1">
                        <x-acao-tabela icone="pencil" titulo="Editar sistema interno"
                                       :href="route('sistemas.edit', $interno)" />
                        <x-acao-tabela icone="view-grid" titulo="Ver tarefas deste sistema"
                                       :href="route('tarefas.index', ['sistema' => $interno->id])" />
                    </div>
                </td>
            </tr>
        @empty
            {{-- A ausência diz o que a lista É, e não só que está vazia: sem a
                 segunda frase, "nenhum sistema interno" se lê como falha de
                 carregamento de uma lista que deveria ter os produtos. --}}
            <tr>
                <td colspan="6" class="px-4 py-8 text-center">
                    <p class="text-[13px] text-ink-mute">Nenhum sistema interno cadastrado.</p>
                    <p class="mt-1 text-[12px] text-ink-faint">
                        São os sistemas que a casa usa e não vende — a própria Matriz, a infra, o site.
                        Cadastrá-los dá às tarefas deles onde apontar.
                    </p>
                </td>
            </tr>
        @endforelse
    </tbody>

    <x-slot name="rodape">
        <span>{{ $internos->count() }} {{ Str::plural('sistema', $internos->count()) }}
              {{ Str::plural('interno', $internos->count()) }}</span>
        @if (($abertas = $internos->sum('tarefas_count')) > 0)
            <span>· {{ $abertas }} {{ Str::plural('tarefa', $abertas) }} em aberto</span>
        @endif
    </x-slot>
</x-tabela>
