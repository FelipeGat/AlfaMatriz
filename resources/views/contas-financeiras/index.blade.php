<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2 text-[14px]">
            <span class="text-mute">Financeiro</span>
            <span class="text-line">/</span>
            <span class="font-medium text-ink">Caixa</span>
        </div>
    </x-slot>

    <div class="space-y-[18px]">
        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
            <x-summary-card
                label="Saldo total"
                :value="'R$ ' . number_format($saldoTotal, 2, ',', '.')"
                :tom="$saldoTotal >= 0 ? 'ink' : 'bad'"
                contexto="somando as contas ativas" />
            <x-summary-card label="Contas ativas" :value="$contasAtivas" contexto="de {{ $contasFinanceiras->count() }} cadastradas" />
            <x-summary-card
                label="Contas negativas"
                :value="$contasNegativas"
                :tom="$contasNegativas > 0 ? 'bad' : 'ink'"
                contexto="com saldo abaixo de zero" />
            <x-summary-card label="Maior saldo" :value="'R$ ' . number_format($maiorSaldo, 2, ',', '.')" contexto="entre as contas ativas" />
        </div>

        <div class="flex justify-end">
            <a href="{{ route('contas-financeiras.create') }}"
               class="inline-flex h-8 items-center rounded-control bg-ink px-3 text-[12.5px] font-medium text-bg transition-opacity hover:opacity-90">
                Nova conta
            </a>
        </div>

        <x-painel-card :sem-padding="true">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] border-collapse">
                    <thead>
                        <tr class="bg-raised">
                            @foreach (['Conta' => '', 'Tipo' => 'w-[150px]', 'Saldo' => 'w-[140px] text-right', 'Situação' => 'w-[104px]', '' => 'w-[190px]'] as $titulo => $largura)
                                <th class="{{ $largura }} truncate whitespace-nowrap border-b border-line px-5 py-2.5 text-left font-mono text-[10px] font-medium uppercase tracking-[.08em] text-mute">
                                    {{ $titulo }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($contasFinanceiras as $conta)
                            <tr class="border-b border-line transition-colors last:border-0 hover:bg-raised">
                                <td class="px-5 py-3 text-[14px] text-ink">{{ $conta->nome }}</td>
                                <td class="px-5 py-3 font-mono text-[11px] uppercase tracking-[.06em] text-mute">{{ $conta->tipo }}</td>
                                <td class="valor px-5 py-3 text-right text-[12.5px] font-medium {{ $conta->saldo >= 0 ? 'text-ink' : 'text-bad' }}">
                                    R$ {{ number_format($conta->saldo, 2, ',', '.') }}
                                </td>
                                <td class="px-5 py-3">
                                    <x-status-pill :tom="$conta->ativo ? 'good' : 'neutro'">
                                        {{ $conta->ativo ? 'Ativa' : 'Inativa' }}
                                    </x-status-pill>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-right text-[12.5px]">
                                    <a href="{{ route('contas-financeiras.extrato', $conta) }}" class="text-dim transition-colors hover:text-ink">Extrato</a>
                                    <a href="{{ route('contas-financeiras.edit', $conta) }}" class="ml-3 text-dim transition-colors hover:text-ink">Editar</a>
                                    <form action="{{ route('contas-financeiras.destroy', $conta) }}" method="POST" class="ml-3 inline" onsubmit="return confirm('Remover esta conta?');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-dim transition-colors hover:text-bad">Remover</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-5 py-[34px] text-center text-[14px] text-mute">Nenhuma conta financeira cadastrada.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-painel-card>
    </div>
</x-app-layout>
