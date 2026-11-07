{{--
    As notificações em Acompanhamentos (Felipe, 07/11/2026; docs/specs/secretario-notificacoes-em-acompanhamentos).

    O sino mostra as 12 últimas numa linha cortada; aqui está a lista inteira,
    para tratar uma a uma. Abas por gravidade, e não por assunto: o `tipo` do
    aviso não separa assunto (o Suporte grava `direcionamento`, como o quadro),
    e a gravidade é o que decide a ordem de tratar.

    Tratado e Desfazer vão por fetch, sem recarregar: numa lista de cem linhas,
    recarregar devolvia a pessoa ao topo a cada aviso tratado. O número do sino
    acompanha porque esta seção escreve no `naoLidas` do `shell` (o x-data da
    moldura, que a envolve).

    Espera: $notificacoes, $notificacoesTotal, $tratadas, $notificacaoFoco.
--}}
@php
    $grupo = fn ($n) => in_array($n->nivel, ['critico', 'atencao'], true) ? $n->nivel : 'demais';
    $normalizar = fn ($n) => \App\Services\Secretario\Memoria\BuscaNaMemoria::normalizar($n->titulo.' '.$n->meta);
    $focoId = $notificacaoFoco?->id;
    $aTratar = $notificacoes->reject(fn ($n) => $n->id === $focoId)->values();
    $jaTratadas = $tratadas->reject(fn ($n) => $n->id === $focoId)->values();
    // O destaque entra nas contagens das abas, mas não é desenhado de novo na
    // lista: dois elementos com o mesmo id quebrariam a âncora do sino.
    $todas = $aTratar->concat($jaTratadas)->when($notificacaoFoco, fn ($c) => $c->concat([$notificacaoFoco]));
    $lista = fn ($n) => $n->naoLida() ? 'a_tratar' : ($n->lida_em->gte(now()->subDays(7)) ? 'tratadas' : 'antiga');
    $itens = $todas->map(fn ($n) => ['id' => $n->id, 'lista' => $lista($n), 'g' => $grupo($n), 't' => $normalizar($n)])->values();
    $feito = $todas->mapWithKeys(fn ($n) => [$n->id => ! $n->naoLida()]);
    $abas = ['todas' => 'Todas', 'critico' => 'Críticas', 'atencao' => 'Atenção', 'demais' => 'Demais', 'tratadas' => 'Tratadas · 7 dias'];
@endphp

<section id="notificacoes" class="scroll-mt-20 rounded-panel border border-line bg-surface"
         x-data="{
            filtro: 'todas', procura: '', expandida: false,
            itens: @js($itens), feito: @js((object) $feito->all()),
            casa(i) {
                const termo = this.procura.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
                if (termo && ! i.t.includes(termo)) return false;
                return this.filtro === 'tratadas' ? i.lista === 'tratadas' : i.lista === 'a_tratar' && (this.filtro === 'todas' || i.g === this.filtro);
            },
            visivel(id) {
                const pos = this.itens.filter(i => this.casa(i)).findIndex(i => i.id === id);
                return pos >= 0 && (this.expandida || this.procura !== '' || pos < 20);
            },
            quantas(aba) {
                return this.itens.filter(i => aba === 'tratadas' ? i.lista === 'tratadas'
                    : i.lista === 'a_tratar' && ! this.feito[i.id] && (aba === 'todas' || i.g === aba)).length;
            },
            async alternar(id, tratar) {
                try {
                    const r = await fetch(`/notificacoes/${id}/${tratar ? 'lida' : 'nao-lida'}`, { method: 'POST',
                        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '' } });
                    if (! r.ok) return;
                    this.naoLidas = (await r.json()).nao_lidas;
                    this.feito[id] = tratar;
                } catch (e) {
                    // Sem rede: a linha fica como estava, e a pessoa tenta de novo.
                }
            },
         }">
    <div class="flex flex-wrap items-baseline justify-between gap-2 px-4 pt-3">
        <h2 class="font-display text-[15px] font-semibold text-ink">
            Notificações <span class="ml-1 rounded-full bg-head px-1.5 text-[11px] font-semibold text-ink-mute" x-text="naoLidas">{{ $notificacoesTotal }}</span>
        </h2>
        <p class="text-[12px] text-ink-mute">Abrir leva aonde o aviso se resolve e o dá por tratado; Tratado tira da lista sem sair daqui.</p>
    </div>

    {{-- O aviso que trouxe a pessoa do sino, no topo e destacado: com cem na
         lista, cair nela sem saber qual era o aviso foi a queixa de 22/10 com
         os acompanhamentos, e vale igual aqui. --}}
    @if ($notificacaoFoco)
        <div class="mx-4 mt-3 overflow-hidden rounded-panel border border-line bg-warn-tint">
            <p class="border-b border-line px-4 py-1.5 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Do sino</p>
            <ul>@include('secretario._notificacao-linha', ['n' => $notificacaoFoco, 'destaque' => true])</ul>
        </div>
    @endif

    {{-- Abas numa linha inteira e a busca embaixo, como na tabela da tela: lado a lado, a busca
         cortava a última aba ("Tratadas") na largura do notebook. --}}
    <div class="mt-2 flex flex-wrap items-center gap-2 border-b border-line px-3">
        <nav class="flex w-full min-w-0 gap-1 overflow-x-auto">
            @foreach ($abas as $k => $rotulo)
                <button type="button" @click="filtro = '{{ $k }}'; expandida = false" class="flex shrink-0 items-center gap-1.5 border-b-2 px-2.5 pb-2 pt-1 text-[13px]"
                        :class="filtro === '{{ $k }}' ? 'border-brand font-semibold text-ink' : 'border-transparent text-ink-mute hover:text-ink'">
                    {{ $rotulo }}
                    <span class="rounded-full px-1.5 text-[11px] font-semibold" :class="filtro === '{{ $k }}' ? 'bg-brand text-on-brand' : 'bg-head text-ink-mute'" x-text="quantas('{{ $k }}')"></span>
                </button>
            @endforeach
        </nav>
        <div class="flex w-full justify-end pb-2">
            <label class="relative min-w-0 flex-1 md:max-w-[220px]">
                <span class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-ink-faint"><x-nav-icon name="search" /></span>
                <input type="search" x-model="procura" placeholder="Buscar notificações…" class="w-full rounded-control border border-btn-line bg-canvas py-2 pl-8 pr-2 text-[13px] text-ink placeholder:text-ink-faint focus:border-brand focus:outline-none">
            </label>
        </div>
    </div>

    <ul class="divide-y divide-line">
        @foreach ($aTratar->concat($jaTratadas) as $n)
            @include('secretario._notificacao-linha', ['n' => $n])
        @endforeach
    </ul>

    <p x-show="itens.filter(i => casa(i)).length === 0" x-cloak class="px-4 py-6 text-center text-[13.5px] text-ink-mute"
       x-text="filtro === 'tratadas' ? 'Nada tratado nos últimos 7 dias.' : 'Nenhuma notificação a tratar aqui.'"></p>

    <div x-show="! expandida && procura === '' && itens.filter(i => casa(i)).length > 20" x-cloak class="border-t border-line px-4 py-2 text-center">
        <button type="button" @click="expandida = true" class="text-[12.5px] font-semibold text-brand-text hover:text-brand"
                x-text="`Ver todas as ${itens.filter(i => casa(i)).length}`"></button>
    </div>

    @if ($notificacoesTotal > $notificacoes->count())
        <p class="border-t border-line px-4 py-2.5 text-[12px] text-ink-mute">
            Mostrando as {{ $notificacoes->count() }} primeiras de {{ $notificacoesTotal }} a tratar. As outras entram aqui conforme estas forem tratadas.
        </p>
    @endif
</section>
