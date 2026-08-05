<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-ink leading-tight">Centro de Controle</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-6">
                <p class="font-display text-lg text-ink">Bom dia, {{ explode(' ', auth()->user()->name)[0] }}.</p>
                <p class="text-sm text-ink-mute mt-1">Aqui está o que precisa da sua atenção hoje.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">

                {{-- Coluna esquerda: alertas + resumo --}}
                <div class="lg:col-span-2 space-y-6">
                    <div class="space-y-2">
                        @foreach ($alertas as $alerta)
                            @php
                                $estilo = [
                                    'critico' => ['bg' => 'bg-status-critical/10', 'ring' => 'ring-status-critical/25', 'icon' => 'text-status-critical', 'text' => 'text-ink'],
                                    'atencao' => ['bg' => 'bg-status-warning/10', 'ring' => 'ring-status-warning/25', 'icon' => 'text-status-warning', 'text' => 'text-ink'],
                                    'positivo' => ['bg' => 'bg-status-good/10', 'ring' => 'ring-status-good/25', 'icon' => 'text-status-good', 'text' => 'text-ink-dim'],
                                ][$alerta['nivel']];
                            @endphp
                            <div class="flex items-center gap-3 {{ $estilo['bg'] }} ring-1 {{ $estilo['ring'] }} rounded-lg px-4 py-3">
                                <span class="h-8 w-8 shrink-0 rounded-full bg-panel flex items-center justify-center {{ $estilo['icon'] }}">
                                    <span class="h-4.5 w-4.5">
                                        <x-nav-icon :name="$alerta['icone']" />
                                    </span>
                                </span>
                                <span class="text-sm {{ $estilo['text'] }} flex-1">{{ $alerta['mensagem'] }}</span>
                                @if (! empty($alerta['quando']))
                                    <span class="shrink-0 text-[11px] text-ink-mute whitespace-nowrap">{{ $alerta['quando'] }}</span>
                                @endif
                                @if ($alerta['rota'])
                                    <a href="{{ $alerta['rota'] }}" class="shrink-0 text-xs font-semibold {{ $estilo['icon'] }} hover:underline whitespace-nowrap">
                                        {{ $alerta['acao'] ?? 'Ver' }} &rarr;
                                    </a>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    {{-- Resumo do dia --}}
                    <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-4">
                        <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-4">
                            <p class="text-[10px] text-ink-mute uppercase">MRR atual</p>
                            <p class="text-lg font-display font-semibold text-ink">R$ {{ number_format($resumo['mrr'], 0, ',', '.') }}</p>
                        </div>
                        <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-4">
                            <p class="text-[10px] text-ink-mute uppercase">Saldo em caixa</p>
                            <p class="text-lg font-display font-semibold {{ $resumo['saldo'] >= 0 ? 'text-ink' : 'text-status-critical' }}">R$ {{ number_format($resumo['saldo'], 0, ',', '.') }}</p>
                        </div>
                        <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-4">
                            <p class="text-[10px] text-ink-mute uppercase">Clientes ativos</p>
                            <p class="text-lg font-display font-semibold text-ink">{{ $resumo['clientes_ativos'] }}</p>
                        </div>
                        <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-4">
                            <p class="text-[10px] text-ink-mute uppercase">A receber (7 dias)</p>
                            <p class="text-lg font-display font-semibold text-status-good">R$ {{ number_format($resumo['receitas_7dias'], 0, ',', '.') }}</p>
                        </div>
                        <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-4">
                            <p class="text-[10px] text-ink-mute uppercase">A pagar (7 dias)</p>
                            <p class="text-lg font-display font-semibold text-status-warning">R$ {{ number_format($resumo['despesas_7dias'], 0, ',', '.') }}</p>
                        </div>
                        <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-4">
                            <p class="text-[10px] text-ink-mute uppercase">Pipeline aberto</p>
                            <p class="text-lg font-display font-semibold text-ink">R$ {{ number_format($resumo['pipeline_aberto'], 0, ',', '.') }}</p>
                        </div>
                    </div>
                </div>

                {{-- Coluna direita: atalhos e mini-listas --}}
                <div class="space-y-6">
                    <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-mute mb-3">Próximos vencimentos</p>
                        @if ($proximosVencimentos->isEmpty())
                            <p class="text-sm text-ink-mute">Nada vencendo nos próximos dias.</p>
                        @else
                            <div class="space-y-3">
                                @foreach ($proximosVencimentos as $item)
                                    <a href="{{ $item['rota'] }}" class="flex items-center gap-3 group">
                                        <span class="h-6 w-6 shrink-0 rounded-full flex items-center justify-center {{ $item['tipo'] === 'receita' ? 'bg-status-good/10 text-status-good' : 'bg-status-warning/10 text-status-warning' }}">
                                            <span class="h-3.5 w-3.5">
                                                <x-nav-icon :name="$item['tipo'] === 'receita' ? 'trending-up' : 'trending-down'" />
                                            </span>
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-sm text-ink truncate group-hover:text-brand-dim">{{ $item['descricao'] }}</span>
                                            <span class="block text-[11px] text-ink-mute">{{ \Illuminate\Support\Carbon::parse($item['data'])->format('d/m') }}</span>
                                        </span>
                                        <span class="shrink-0 text-xs font-semibold {{ $item['tipo'] === 'receita' ? 'text-status-good' : 'text-status-warning' }}">
                                            R$ {{ number_format($item['valor'], 0, ',', '.') }}
                                        </span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-5">
                        <div class="flex items-center justify-between mb-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-ink-mute">Últimos clientes</p>
                            <a href="{{ route('clientes.index') }}" class="text-xs text-brand-dim hover:underline">Ver todos</a>
                        </div>
                        @if ($ultimosClientes->isEmpty())
                            <p class="text-sm text-ink-mute">Nenhum cliente cadastrado ainda.</p>
                        @else
                            <div class="space-y-3">
                                @foreach ($ultimosClientes as $cliente)
                                    <a href="{{ route('clientes.edit', $cliente) }}" class="flex items-center gap-3 group">
                                        <span class="h-7 w-7 rounded-full bg-brand/15 text-brand-dim flex items-center justify-center text-[11px] font-semibold shrink-0">
                                            {{ Str::of($cliente->nome_exibicao)->substr(0, 1)->upper() }}
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-sm text-ink truncate group-hover:text-brand-dim">{{ $cliente->nome_exibicao }}</span>
                                            <span class="block text-[11px] text-ink-mute">{{ $cliente->created_at->format('d/m/Y') }}</span>
                                        </span>
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <a href="{{ route('leads.index') }}" class="flex items-center justify-center gap-2 bg-brand/10 hover:bg-brand/15 ring-1 ring-brand/25 text-brand-dim rounded-xl p-4 text-sm font-semibold transition">
                        + Novo lead no funil
                    </a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
