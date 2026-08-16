{{-- A seção fala da ADMINISTRAÇÃO — quem usa, o que está instalado, o que se
     mexeu. Só a auditoria tem competência; o resto é a foto de hoje. --}}
<div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))">
    <x-kpi-card rotulo="Sistemas ativos" :valor="number_format($sistemasAtivos, 0, ',', '.')"
                acento="accent" icone="cube-outline" />
    <x-kpi-card rotulo="Clientes ativos" :valor="number_format($clientesAtivos, 0, ',', '.')"
                acento="brand" icone="users" />
    <x-kpi-card rotulo="Revendas ativas" :valor="number_format($revendasAtivas, 0, ',', '.')"
                acento="amber" icone="building" />
    <x-kpi-card rotulo="Usuários ativos" :valor="number_format($usuariosAtivos, 0, ',', '.')"
                delta="contas que entram no painel"
                acento="brand" icone="key" />
    <x-kpi-card rotulo="Ações de auditoria" :valor="number_format($acoesAuditoria, 0, ',', '.')"
                delta="na competência"
                acento="chart-out" icone="eye" />
</div>

<div class="grid gap-4 items-start" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr))">
    {{-- "Licenças ativas", e não "clientes": um cliente com dois sistemas
         entra duas vezes — mesma ressalva do Painel Comercial. --}}
    <x-ranking :ranking="$rankingBaseInstalada"
               titulo="Base instalada por sistema"
               nota="cliente com dois sistemas conta duas vezes"
               rotuloTotal="Licenças ativas"
               compacto />

    <x-ranking :ranking="$rankingUfs"
               titulo="Clientes ativos por UF"
               nota="onde a base mora"
               compacto />

    {{-- Vínculos, não contas: usuário com dois perfis aparece nos dois — o
         card "Usuários ativos" é quem conta contas. --}}
    <x-ranking :ranking="$rankingPerfis"
               titulo="Usuários por perfil"
               nota="vínculos de contas ativas"
               compacto />
</div>

<div class="grid gap-4 items-start" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr))">
    <x-ranking :ranking="$rankingAuditoria"
               titulo="Auditoria por recurso"
               nota="onde mexeram, na competência"
               compacto />

    {{-- O mesmo rastro pelo outro eixo: recurso diz onde, ação diz o quê. --}}
    <x-ranking :ranking="$rankingAcoesAuditoria"
               titulo="Auditoria por ação"
               nota="o que fizeram, na competência"
               compacto />
</div>

{{-- O rastro recente, sem sair do relatório: as últimas linhas do recorte —
     a tela de Auditoria continua sendo o lugar de investigar. --}}
<x-tabela min="820px" titulo="Últimas ações registradas" sub="na competência · 12 mais recentes">
    <thead>
        <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
            <th class="px-4 py-2.5 font-semibold">Quando</th>
            <th class="px-4 py-2.5 font-semibold">Quem</th>
            <th class="px-4 py-2.5 font-semibold">Ação</th>
            <th class="px-4 py-2.5 font-semibold">Recurso</th>
            <th class="px-4 py-2.5 font-semibold">Descrição</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($ultimasAcoes as $acao)
            <tr class="border-b border-rule hover:bg-chip transition">
                <td class="px-4 py-3 font-mono text-[13px] text-ink-dim whitespace-nowrap">{{ $acao->created_at->format('d/m/Y H:i') }}</td>
                <td class="px-4 py-3 text-[13px] text-ink">{{ $acao->usuario_nome ?? 'Sistema' }}</td>
                <td class="px-4 py-3 text-[13px] text-ink-dim">{{ ucfirst($acao->acao) }}</td>
                <td class="px-4 py-3 font-mono text-[12.5px] text-ink-dim">{{ $acao->recurso }}</td>
                <td class="px-4 py-3 text-[13px] text-ink-dim">{{ $acao->descricao }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-4 py-6 text-[13px] text-ink-mute">Nenhuma ação registrada neste recorte.</td></tr>
        @endforelse
    </tbody>
</x-tabela>
