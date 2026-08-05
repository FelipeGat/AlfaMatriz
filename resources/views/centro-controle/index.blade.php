<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-ink leading-tight">Centro de Controle</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-6">
                <p class="font-display text-lg text-ink">Bom dia, {{ explode(' ', auth()->user()->name)[0] }}.</p>
                <p class="text-sm text-ink-mute mt-1">Aqui está o que precisa da sua atenção hoje.</p>
            </div>

            {{-- Alertas acionáveis --}}
            <div class="space-y-2">
                @foreach ($alertas as $alerta)
                    @php
                        $estilo = [
                            'critico' => ['dot' => 'bg-status-critical', 'text' => 'text-ink'],
                            'atencao' => ['dot' => 'bg-status-warning', 'text' => 'text-ink'],
                            'positivo' => ['dot' => 'bg-status-good', 'text' => 'text-ink-dim'],
                        ][$alerta['nivel']];
                    @endphp
                    <a href="{{ $alerta['rota'] ?? '#' }}" class="flex items-center gap-3 bg-panel border border-white/5 rounded-lg px-4 py-3 hover:border-brand/30 transition {{ !$alerta['rota'] ? 'pointer-events-none' : '' }}">
                        <span class="h-2.5 w-2.5 rounded-full shrink-0 {{ $estilo['dot'] }}"></span>
                        <span class="text-sm {{ $estilo['text'] }}">{{ $alerta['mensagem'] }}</span>
                    </a>
                @endforeach
            </div>

            {{-- Resumo do dia --}}
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
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
    </div>
</x-app-layout>
