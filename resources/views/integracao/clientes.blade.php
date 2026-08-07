<x-app-layout>
    <x-slot name="titulo">Clientes nos sistemas</x-slot>
    <x-slot name="contexto">{{ number_format($clientes->total(), 0, ',', '.') }} registros · {{ $semVinculo }} sem vínculo</x-slot>

    {{--
        A coluna que importa é a última: a quem este cliente corresponde na
        matriz. É ela que responde a pergunta que motivou a integração inteira
        — "o cliente ativo lá dentro está sendo cobrado aqui?".
    --}}
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="search" name="busca" value="{{ $filtros['busca'] ?? '' }}"
                       placeholder="Nome ou documento"
                       class="h-8 w-[220px] rounded-control border-line bg-input text-[12px] text-ink placeholder:text-ink-faint">

                <select name="sistema" class="h-8 rounded-control border-line bg-input text-[12px] text-ink">
                    <option value="">Todos os sistemas</option>
                    @foreach ($sistemas as $sistema)
                        <option value="{{ $sistema->id }}" @selected(($filtros['sistema'] ?? null) == $sistema->id)>{{ $sistema->nome }}</option>
                    @endforeach
                </select>

                <select name="status" class="h-8 rounded-control border-line bg-input text-[12px] text-ink">
                    <option value="">Qualquer situação</option>
                    @foreach (['ativo' => 'Ativo', 'pendente' => 'Pendente', 'bloqueado' => 'Bloqueado', 'cancelado' => 'Cancelado'] as $valor => $rotulo)
                        <option value="{{ $valor }}" @selected(($filtros['status'] ?? null) === $valor)>{{ $rotulo }}</option>
                    @endforeach
                </select>

                <select name="vinculo" class="h-8 rounded-control border-line bg-input text-[12px] text-ink">
                    <option value="">Com ou sem vínculo</option>
                    <option value="sem" @selected(($filtros['vinculo'] ?? null) === 'sem')>Só sem vínculo</option>
                    <option value="com" @selected(($filtros['vinculo'] ?? null) === 'com')>Só vinculados</option>
                </select>

                <label class="flex items-center gap-1.5 text-[12px] text-ink-dim">
                    <input type="checkbox" name="ausentes" value="sim" @checked(($filtros['ausentes'] ?? null) === 'sim')
                           class="rounded border-line bg-input text-brand">
                    incluir quem sumiu na origem
                </label>

                <button type="submit"
                        class="h-8 px-3 rounded-control border border-line bg-input text-[12px] text-ink-dim hover:text-ink transition">
                    Filtrar
                </button>
            </form>

            <div class="ml-auto">
                <x-atualizado-em :em="$atualizadoEm" vazio="nunca sincronizado" />
            </div>
        </div>

        <x-tabela min="1120px">
            <thead>
                <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                    <th class="px-4 py-2.5 font-semibold">Sistema</th>
                    <th class="px-4 py-2.5 font-semibold">Cliente no sistema</th>
                    <th class="px-4 py-2.5 font-semibold">Documento</th>
                    <th class="px-4 py-2.5 font-semibold">Revenda</th>
                    <th class="px-4 py-2.5 font-semibold">Situação</th>
                    <th class="px-4 py-2.5 font-semibold text-right">Unidades</th>
                    <th class="px-4 py-2.5 font-semibold">Cliente na matriz</th>
                </tr>
            </thead>

            <tbody>
                @forelse ($clientes as $cliente)
                    <tr class="border-b border-line last:border-0 hover:bg-chip transition">
                        <td class="px-4 py-2.5">
                            <span class="inline-flex items-center gap-2">
                                <span class="h-5 w-5 shrink-0"><x-marca-sistema :sistema="$cliente->sistema" /></span>
                                <span class="text-[12px] text-ink-dim">{{ $cliente->sistema?->nome }}</span>
                            </span>
                        </td>

                        <td class="px-4 py-2.5">
                            <p class="text-[13px] text-ink">{{ $cliente->nome }}</p>
                            @if ($cliente->cidade)
                                <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                                    {{ $cliente->cidade }}{{ $cliente->uf ? ' · '.$cliente->uf : '' }}
                                </p>
                            @endif
                        </td>

                        <td class="px-4 py-2.5 font-mono text-[12px] text-ink-dim tabular whitespace-nowrap">
                            {{ \App\Services\Integracao\Documento::formatar($cliente->cpf_cnpj) ?? '—' }}
                        </td>

                        <td class="px-4 py-2.5 text-[12px] text-ink-dim">
                            {{ $cliente->sistemaRevenda?->nome ?? 'venda direta' }}
                        </td>

                        <td class="px-4 py-2.5">
                            @if ($cliente->ausenteNaOrigem())
                                <x-badge tom="critico" title="Sumiu do sistema em {{ $cliente->ausente_em_origem_em?->format('d/m/Y') }}">
                                    sumiu na origem
                                </x-badge>
                            @else
                                <x-badge :tom="match ($cliente->status) {
                                    'ativo' => 'bom',
                                    'pendente' => 'atencao',
                                    'bloqueado', 'cancelado' => 'critico',
                                    default => 'neutro',
                                }">{{ $cliente->status }}</x-badge>
                            @endif
                        </td>

                        <td class="px-4 py-2.5 text-right font-mono text-[12px] text-ink tabular">
                            {{ number_format($cliente->unidades_ativas, 0, ',', '.') }}
                        </td>

                        <td class="px-4 py-2.5">
                            @if ($cliente->cliente)
                                <a href="{{ route('clientes.edit', $cliente->cliente) }}"
                                   class="text-[12px] text-ink hover:text-brand-text transition">
                                    {{ $cliente->cliente->nome }}
                                </a>
                                @if ($cliente->vinculoEhManual())
                                    <span class="ml-1 font-mono text-[10px] uppercase tracking-caps text-ink-faint">manual</span>
                                @endif
                            @else
                                <a href="{{ route('integracao.conferencia', ['sistema' => $cliente->sistema_id]) }}"
                                   class="inline-flex items-center gap-1.5">
                                    <x-badge tom="atencao">sem vínculo</x-badge>
                                </a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-[13px] text-ink-dim">
                            Nenhum cliente no retrato local. A sincronização com os sistemas ainda não trouxe dado.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-tabela>

        {{ $clientes->links() }}
    </div>
</x-app-layout>
