<x-app-layout>
    <x-slot name="titulo">Funil de Vendas</x-slot>
    <x-slot name="contexto">{{ $kpis['abertos'] }} abertos · {{ $kpis['total'] }} no total</x-slot>
    <x-slot name="acoes">
        <button type="button" x-data @click="$dispatch('open-modal', 'novo-lead')"
                class="h-[34px] px-3 rounded-control bg-brand text-on-brand font-semibold text-[12.5px]
                       hover:bg-brand-bright transition whitespace-nowrap">
            + Novo lead
        </button>
    </x-slot>

    {{--
        A tela ocupa a altura da janela e o quadro cresce dentro dela. Sem
        isso o quadro é dimensionado pelo conteúdo: a coluna mais cheia estica
        a página inteira e as outras ficam curtas, sem alinhamento nenhum.
    --}}
    <div class="flex flex-col gap-4" style="height: calc(100vh - 120px); min-height: 520px">
        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif

        <div class="shrink-0 grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))">
            <x-kpi-card rotulo="Taxa de conversão" :valor="number_format($kpis['taxa_conversao'], 1, ',', '.').'%'"
                        acento="accent" icone="trending-up"
                        :delta="$kpis['fechados'].' de '.$kpis['total'].' leads'" />
            <x-kpi-card rotulo="Pipeline aberto" :valor="'R$ '.number_format($kpis['pipeline_valor'], 2, ',', '.')"
                        acento="brand" icone="clipboard"
                        :delta="$kpis['abertos'].' em negociação'" />
            <x-kpi-card rotulo="Ticket médio fechado" :valor="'R$ '.number_format($kpis['ticket_medio'], 2, ',', '.')"
                        acento="good" icone="check-circle" />
            <x-kpi-card rotulo="Abertos / Perdidos" :valor="$kpis['abertos'].' / '.$kpis['perdidos']"
                        acento="amber" icone="view-grid" />
        </div>

        {{-- Filtro de período: por quando o lead ENTROU, não uma janela que
             esconde lead antigo do quadro — por isso o padrão é "todos" e o
             filtro é sempre uma escolha explícita de quem está olhando. --}}
        <div class="shrink-0 flex flex-wrap items-center gap-2">
            <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">Período de entrada</span>

            <form method="GET" class="flex flex-wrap items-center gap-1.5">
                <select name="periodo" onchange="this.form.submit()"
                        class="h-9 py-0 text-[13px] rounded-control bg-input border-line text-ink">
                    <option value="todos" {{ $filtroPeriodo === 'todos' ? 'selected' : '' }}>Todos</option>
                    <option value="mes_anterior" {{ $filtroPeriodo === 'mes_anterior' ? 'selected' : '' }}>Mês anterior</option>
                    <option value="mes_atual" {{ $filtroPeriodo === 'mes_atual' ? 'selected' : '' }}>Mês atual</option>
                    <option value="proximo_mes" {{ $filtroPeriodo === 'proximo_mes' ? 'selected' : '' }}>Próximo mês</option>
                    <option value="personalizado" {{ $filtroPeriodo === 'personalizado' ? 'selected' : '' }}>Período personalizado</option>
                </select>

                @if ($filtroPeriodo === 'personalizado')
                    <input type="date" name="periodo_de" value="{{ $periodoDe }}" onchange="this.form.submit()"
                           class="h-9 py-0 text-[13px] rounded-control bg-input border-line text-ink">
                    <span class="font-mono text-[10.5px] text-ink-faint">até</span>
                    <input type="date" name="periodo_ate" value="{{ $periodoAte }}" onchange="this.form.submit()"
                           class="h-9 py-0 text-[13px] rounded-control bg-input border-line text-ink">
                @endif
            </form>

            @unless ($filtroPeriodo === 'todos')
                <a href="{{ route('leads.index') }}"
                   class="font-mono text-[10.5px] uppercase tracking-caps text-brand-text hover:underline">Limpar</a>
            @endunless
        </div>

        {{-- Quadro ---------------------------------------------------------- --}}
        <div x-data="funil" class="relative flex-1 min-h-0 flex flex-col rounded-panel border border-line bg-board overflow-hidden">
            {{-- Sombras nas bordas: quando o quadro não cabe, elas são a única
                 pista de que há mais coluna. Barra de rolagem fina em tema
                 escuro passa despercebida, e o corte seco de uma coluna na
                 borda parece fim do conteúdo. --}}
            <div x-show="temMaisEsquerda" x-cloak aria-hidden="true"
                 class="pointer-events-none absolute left-0 top-0 bottom-0 w-10 z-10"
                 style="background: linear-gradient(90deg, rgb(var(--canvas) / 0.55), transparent)"></div>
            <div x-show="temMaisDireita" x-cloak aria-hidden="true"
                 class="pointer-events-none absolute right-0 top-0 bottom-0 w-10 z-10"
                 style="background: linear-gradient(270deg, rgb(var(--canvas) / 0.55), transparent)"></div>
            <div class="shrink-0 flex items-center gap-3 px-4 h-[52px] border-b border-line">
                <span class="h-7 w-7 shrink-0 rounded-tile flex items-center justify-center bg-brand/15 text-brand-text">
                    <span class="h-[15px] w-[15px]"><x-nav-icon name="view-grid" /></span>
                </span>
                <div class="min-w-0">
                    <h2 class="font-display text-[15.5px] font-semibold text-ink leading-tight">Quadro do funil</h2>
                    <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint truncate">
                        {{ count($estagios) }} estágios · {{ $kpis['abertos'] }} leads abertos
                    </p>
                </div>
                <p class="ml-auto shrink-0 hidden sm:block font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                    arraste o card para mover de estágio
                </p>
            </div>

            {{-- `items-stretch` + `min-h-0` é o que faz todas as colunas terem
                 a mesma altura e cada uma rolar por dentro. --}}
            {{-- Colunas de largura fixa: o card precisa de 276px para caber
                 nome, valor e revenda sem espremer. O quadro rola na
                 horizontal, com `shift`+roda (que o navegador já faz) e a
                 sombra na borda avisando que há mais coluna adiante. --}}
            {{-- `snap-x snap-mandatory` só entra em ação em quem arrasta com o
                 dedo — mouse com roda ignora scroll-snap normalmente, então
                 não muda nada no desktop. --}}
            <div x-ref="quadro" @scroll="medirBordas()" @resize.window="medirBordas()" x-init="medirBordas()"
                 class="relative flex-1 min-h-0 flex gap-3 items-stretch overflow-x-auto p-3.5 snap-x snap-mandatory sm:snap-none">
                @foreach ($estagios as $estagio)
                    @php $cards = $colunas[$estagio['chave']]; @endphp

                    {{-- Largura da coluna: quase a tela inteira abaixo de
                         640px (uma coluna por vez, como um app de Kanban de
                         celular — Trello faz igual), 276px fixos do `sm` para
                         cima. Antes era 276px sempre, e num iPhone de 390px
                         a coluna seguinte aparecia só como uma tira cortada
                         na borda — dava para adivinhar que havia mais, não
                         para ler o que tinha lá (AC pedido em 15/08/2026). --}}
                    <section class="shrink-0 flex flex-col min-h-0 rounded-control bg-panel border border-line overflow-hidden
                                     snap-start w-[calc(100vw-3.5rem)] max-w-[360px] sm:w-[276px] sm:max-w-none"
                             style="border-top: 3px solid rgb(var(--{{ $estagio['cor'] }}))"
                             data-estagio="{{ $estagio['chave'] }}"
                             @dragover.prevent="permitir('{{ $estagio['chave'] }}')"
                             @dragleave="sobre = null"
                             @drop.prevent="soltar('{{ $estagio['chave'] }}', {{ $estagio['exigeMotivo'] ? 'true' : 'false' }})"
                             :class="sobre === '{{ $estagio['chave'] }}' && 'ring-1 ring-brand'">
                        <header class="shrink-0 px-3 py-2.5 border-b border-rule">
                            <div class="flex items-center gap-2">
                                <span class="h-[7px] w-[7px] shrink-0 rounded-full"
                                      style="background: rgb(var(--{{ $estagio['cor'] }}))"></span>
                                <h3 class="min-w-0 truncate font-display text-[14px] font-semibold text-ink">{{ $estagio['label'] }}</h3>
                                <span class="ml-auto shrink-0 h-5 min-w-[20px] px-1.5 rounded-full font-mono text-[10px] font-semibold leading-5 text-center"
                                      style="background: rgb(var(--{{ $estagio['cor'] }}) / var(--tint-alpha)); color: rgb(var(--{{ $estagio['cor'] }}))">
                                    {{ $estagio['quantidade'] }}
                                </span>
                            </div>
                            <p class="mt-0.5 pl-[15px] font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                                R$ {{ number_format($estagio['valor'], 0, ',', '.') }} em jogo
                            </p>
                        </header>

                        {{-- `overscroll-y-contain`, e não `overscroll-contain`: conter os
                             DOIS eixos bloqueava o shift+roda de chegar ao quadro, que é o
                             jeito padrão de rolar na horizontal. Aqui só o eixo vertical é
                             contido — a rolagem da coluna não empurra a página, e o gesto
                             horizontal continua passando para o quadro. --}}
                        <div data-coluna-lista class="flex-1 min-h-0 overflow-y-auto overscroll-y-contain p-2 space-y-2">
                            @forelse ($cards as $lead)
                                @php
                                    $temperatura = $lead->temperatura();
                                    $tomTemp = ['quente' => 'good', 'esfriando' => 'warn', 'frio' => 'crit'][$temperatura] ?? null;
                                @endphp

                                <article draggable="true"
                                         data-lead="{{ $lead->id }}"
                                         @dragstart="arrastando = {{ $lead->id }}"
                                         @dragend="arrastando = null; sobre = null"
                                         @click="$dispatch('open-modal', 'lead-{{ $lead->id }}')"
                                         x-data="{ menuAberto: false, destino: '{{ $estagio['chave'] }}', expandido: false }"
                                         class="rounded-ctl bg-card-grad border p-2.5 cursor-grab active:cursor-grabbing transition hover:border-brand"
                                         style="border-color: {{ $tomTemp && $tomTemp !== 'good' ? 'rgb(var(--'.$tomTemp.') / 0.4)' : 'var(--line)' }}"
                                         :class="arrastando === {{ $lead->id }} && 'opacity-50'">
                                    <div class="flex items-start gap-2">
                                        <p class="min-w-0 flex-1 truncate text-[13.5px] font-medium text-ink">{{ $lead->nome }}</p>
                                        @if ($temperatura)
                                            <x-badge :tom="['quente' => 'bom', 'esfriando' => 'atencao', 'frio' => 'critico'][$temperatura]"
                                                     :title="ucfirst($temperatura).' · '.$lead->diasNoEstagio().' dias no estágio'">
                                                {{ $lead->diasNoEstagio() }}d
                                            </x-badge>
                                        @endif
                                    </div>

                                    <p class="mt-1 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint truncate">
                                        {{ \App\Models\Lead::TIPOS_INTERESSE[$lead->tipo_interesse] ?? 'Interesse' }}@if ($lead->origem) · {{ $lead->origem }}@endif
                                    </p>

                                    <div class="mt-2 pt-2 border-t border-rule flex items-center gap-2">
                                        <span class="font-mono text-[12px] text-brand-text whitespace-nowrap">
                                            R$ {{ number_format($lead->valor_estimado ?? 0, 0, ',', '.') }}
                                        </span>
                                        <span class="min-w-0 flex-1 truncate text-right text-[11px] text-ink-faint">
                                            {{ $lead->revenda?->nome ?? 'Venda direta' }}
                                        </span>
                                        {{-- Setinha de expandir: olhada rápida sem
                                             abrir o painel inteiro. `.stop` nos
                                             dois cliques (botão e card) para não
                                             abrir o modal ao mesmo tempo. --}}
                                        {{-- `-m-1.5` compensa o `p-1.5`: a área de
                                             toque cresce para ~32px (dedo real
                                             mira, não só o ícone de 12px) sem
                                             empurrar o layout ao redor. --}}
                                        <button type="button" @click.stop="expandido = ! expandido"
                                                class="shrink-0 -m-1.5 p-1.5 flex items-center justify-center rounded-badge text-ink-faint hover:text-brand transition"
                                                :aria-label="expandido ? 'Recolher detalhes' : 'Ver mais detalhes'">
                                            <span class="h-3 w-3 transition-transform" :class="expandido && 'rotate-180'">
                                                <x-nav-icon name="chevron-down" :peso="1.8" />
                                            </span>
                                        </button>
                                    </div>

                                    {{-- Detalhes rápidos, sem abrir o painel: o
                                         que o card normal esconde (contato,
                                         próximo passo, última interação). --}}
                                    <div x-show="expandido" x-cloak @click.stop
                                         class="mt-2 pt-2 border-t border-rule space-y-1 text-[11px] text-ink-faint">
                                        @if ($lead->email)
                                            <p class="truncate">✉ {{ $lead->email }}</p>
                                        @endif
                                        @if ($lead->telefone)
                                            <p class="truncate">☎ {{ $lead->telefone }}</p>
                                        @endif
                                        @if ($lead->proximo_passo)
                                            <p class="line-clamp-2"><span class="text-ink-mute">Próximo passo:</span> {{ $lead->proximo_passo }}</p>
                                        @endif
                                        @if ($lead->comentarios->isNotEmpty())
                                            <p>Última interação: {{ $lead->comentarios->first()->created_at->format('d/m/Y') }}</p>
                                        @endif
                                        @if (! $lead->email && ! $lead->telefone && ! $lead->proximo_passo && $lead->comentarios->isEmpty())
                                            <p>Sem detalhes ainda — clique no card para adicionar.</p>
                                        @endif
                                    </div>

                                    {{--
                                        Arrastar não serve para todo mundo: quem
                                        navega por teclado, quem está no celular
                                        e a coluna "perdido" (que precisa do
                                        motivo) dependem deste menu. Ele não é
                                        um resto do desenho antigo — é o caminho
                                        acessível.
                                    --}}
                                    <div class="mt-2 pt-2 border-t border-rule" @click.stop>
                                        <button type="button" @click="menuAberto = ! menuAberto"
                                                class="font-mono text-[10.5px] uppercase tracking-caps text-ink-mute hover:text-brand transition">
                                            Mover ▾
                                        </button>

                                        <form x-show="menuAberto" x-cloak method="POST"
                                              action="{{ route('leads.mover', $lead) }}" class="mt-2 space-y-2">
                                            @csrf
                                            <select name="estagio" x-model="destino"
                                                    class="w-full h-8 py-0 text-[12px] rounded-control bg-input border-line text-ink">
                                                @foreach (\App\Models\Lead::ESTAGIOS as $chave => $label)
                                                    <option value="{{ $chave }}">{{ $label }}</option>
                                                @endforeach
                                            </select>

                                            <template x-if="destino === 'perdido'">
                                                <select name="motivo_perda" required
                                                        class="w-full h-8 py-0 text-[12px] rounded-control bg-input border-line text-ink">
                                                    <option value="">Motivo da perda…</option>
                                                    @foreach (\App\Models\Lead::MOTIVOS_PERDA as $chave => $label)
                                                        <option value="{{ $chave }}">{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </template>

                                            <button type="submit"
                                                    class="w-full h-8 rounded-control bg-brand text-on-brand font-semibold text-[12px] hover:bg-brand-bright transition">
                                                Confirmar
                                            </button>
                                        </form>
                                    </div>
                                </article>
                            @empty
                                {{-- Altura fixa nos vazios: a coluna "Lead" tem o botão de
                                     adicionar e as outras não, então elas nasciam com
                                     alturas diferentes e a fileira ficava desalinhada. Com
                                     altura própria, o conteúdo a mais cabe dentro do mesmo
                                     bloco em vez de esticá-lo. --}}
                                <div class="rounded-ctl border border-dashed border-line px-2 text-center
                                            flex flex-col items-center justify-center gap-1.5"
                                     style="height: 84px">
                                    <p class="text-[11.5px] text-ink-faint">Nenhum lead aqui</p>
                                    @if ($estagio['chave'] === 'lead')
                                        <button type="button" x-data @click="$dispatch('open-modal', 'novo-lead')"
                                                class="font-mono text-[10.5px] uppercase tracking-caps text-brand-text hover:underline">
                                            + Adicionar
                                        </button>
                                    @endif
                                </div>
                            @endforelse
                        </div>
                    </section>
                @endforeach
            </div>

            {{-- Um formulário só, apontado para o lead que foi solto. --}}
            <form x-ref="formMover" method="POST" action="" class="hidden">
                @csrf
                <input type="hidden" name="estagio" x-ref="estagioMover">
            </form>
        </div>
    </div>

    {{--
        Script clássico e inline de propósito: ele precisa registrar o
        componente ANTES de o Alpine iniciar, e o bundle do Vite é um módulo
        (portanto adiado). Como este roda durante a análise do HTML, o
        `alpine:init` sempre chega a tempo.
    --}}
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('funil', () => ({
                arrastando: null,
                sobre: null,

                // Modelo de rota com marcador: o id só é conhecido no solto.
                rotaMover: @json(route('leads.mover', ['lead' => '__ID__'])),


                // Há mais coluna fora da vista? A sombra na borda é a única
                // pista quando o quadro não cabe — barra de rolagem fina em
                // tema escuro passa despercebida.
                temMaisEsquerda: false,
                temMaisDireita: false,

                medirBordas() {
                    const q = this.$refs.quadro;
                    if (! q) return;
                    this.temMaisEsquerda = q.scrollLeft > 1;
                    this.temMaisDireita = q.scrollLeft + q.clientWidth < q.scrollWidth - 1;
                },

                permitir(estagio) {
                    if (this.arrastando !== null) {
                        this.sobre = estagio;
                    }
                },

                soltar(estagio, exigeMotivo) {
                    const lead = this.arrastando;
                    this.sobre = null;
                    this.arrastando = null;

                    if (lead === null) {
                        return;
                    }

                    // Marcar como perdido exige o motivo, e um card solto não
                    // tem como responder isso. Quem quer perder um lead usa o
                    // menu do card, onde a pergunta cabe.
                    if (exigeMotivo) {
                        return;
                    }

                    this.$refs.formMover.action = this.rotaMover.replace('__ID__', lead);
                    this.$refs.estagioMover.value = estagio;
                    this.$refs.formMover.submit();
                },
            }));
        });
    </script>

    {{-- Modal: novo lead --}}
    <x-modal name="novo-lead" maxWidth="lg">
        <form action="{{ route('leads.store') }}" method="POST" class="p-6 space-y-4">
            @csrf
            <h3 class="font-display font-semibold text-ink text-lg">Novo lead</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <x-input-label for="nome" value="Nome / Empresa" />
                    <x-text-input id="nome" name="nome" type="text" class="mt-1 block w-full" required />
                </div>
                <div>
                    <x-input-label for="email" value="E-mail" />
                    <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="telefone" value="Telefone" />
                    <x-text-input id="telefone" name="telefone" type="text" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="tipo_interesse" value="Interesse" />
                    <select id="tipo_interesse" name="tipo_interesse" class="mt-1 block w-full">
                        @foreach (\App\Models\Lead::TIPOS_INTERESSE as $chave => $label)
                            <option value="{{ $chave }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="sistema_id" value="Sistema (se for SaaS)" />
                    <select id="sistema_id" name="sistema_id" class="mt-1 block w-full">
                        <option value="">—</option>
                        @foreach ($sistemas as $sistema)
                            <option value="{{ $sistema->id }}">{{ $sistema->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="origem" value="Origem" />
                    <select id="origem" name="origem" class="mt-1 block w-full">
                        <option value="">—</option>
                        @foreach (\App\Models\Lead::ORIGENS as $origem)
                            <option value="{{ $origem }}">{{ $origem }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="valor_estimado" value="Valor estimado (R$)" />
                    <x-text-input id="valor_estimado" name="valor_estimado" type="number" step="0.01" min="0" class="mt-1 block w-full" />
                </div>
                <div>
                    <x-input-label for="revenda_id" value="Revenda (se aplicável)" />
                    <select id="revenda_id" name="revenda_id" class="mt-1 block w-full">
                        <option value="">— Venda direta —</option>
                        @foreach ($revendas as $revenda)
                            <option value="{{ $revenda->id }}">{{ $revenda->nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="observacoes" value="Observações" />
                    <textarea id="observacoes" name="observacoes" rows="2" class="mt-1 block w-full"></textarea>
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <x-secondary-button x-on:click="$dispatch('close')">Cancelar</x-secondary-button>
                <x-primary-button>Salvar</x-primary-button>
            </div>
        </form>
    </x-modal>

    {{-- Reabre o painel de onde uma ação (comentário, anexo, salvar) saiu —
         sem isto, cada envio recarregava a página e o painel fechava
         sozinho, perdendo o lugar de quem estava no meio de uma conversa. --}}
    <script>
        document.addEventListener('alpine:init', () => {
            const leadId = new URLSearchParams(window.location.search).get('lead');
            if (leadId) {
                window.addEventListener('DOMContentLoaded', () => {
                    Alpine.nextTick(() => window.dispatchEvent(
                        new CustomEvent('open-modal', { detail: 'lead-' + leadId })
                    ));
                });
            }
        });
    </script>

    {{-- Painel de detalhamento de cada lead: edição, histórico da conversa e
         provas (print de e-mail/WhatsApp). Mesma dupla comentário+anexo que
         a Tarefa já usa — pedido em 15/08/2026: "enriquecer o card com datas
         da última conversa, print do e-mail ou whatsapp, o que falta para o
         fechamento, o que está sendo acordado". --}}
    @foreach ($colunas->flatten(1) as $lead)
        <x-modal name="lead-{{ $lead->id }}" maxWidth="tarefa">
            <div class="flex flex-col max-h-[85vh]">
                <div class="shrink-0 flex items-start gap-3 p-5 border-b border-rule">
                    <div class="min-w-0 flex-1">
                        <h3 class="font-display font-semibold text-ink text-lg truncate">{{ $lead->nome }}</h3>
                        <p class="mt-0.5 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                            {{ \App\Models\Lead::ESTAGIOS[$lead->estagio] }} · {{ $lead->diasNoEstagio() }} dias no estágio
                            @if ($lead->revenda) · {{ $lead->revenda->nome }} @endif
                        </p>
                    </div>
                    <button type="button" x-on:click="$dispatch('close')"
                            class="shrink-0 h-8 w-8 flex items-center justify-center rounded-control text-ink-faint hover:text-ink hover:bg-chip transition"
                            aria-label="Fechar">✕</button>
                </div>

                <div class="flex-1 overflow-y-auto p-5 space-y-6">
                    {{-- Dados do lead + próximo passo --}}
                    <form action="{{ route('leads.update', $lead) }}" method="POST" class="space-y-4">
                        @csrf
                        @method('PUT')

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="sm:col-span-2">
                                <x-input-label value="Nome / Empresa" />
                                <x-text-input name="nome" type="text" class="mt-1 block w-full" value="{{ $lead->nome }}" required />
                            </div>
                            <div>
                                <x-input-label value="E-mail" />
                                <x-text-input name="email" type="email" class="mt-1 block w-full" value="{{ $lead->email }}" />
                            </div>
                            <div>
                                <x-input-label value="Telefone" />
                                <x-text-input name="telefone" type="text" class="mt-1 block w-full" value="{{ $lead->telefone }}" />
                            </div>
                            <div>
                                <x-input-label value="Interesse" />
                                <select name="tipo_interesse" class="mt-1 block w-full">
                                    @foreach (\App\Models\Lead::TIPOS_INTERESSE as $chave => $label)
                                        <option value="{{ $chave }}" {{ $lead->tipo_interesse === $chave ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <x-input-label value="Sistema (se for SaaS)" />
                                <select name="sistema_id" class="mt-1 block w-full">
                                    <option value="">—</option>
                                    @foreach ($sistemas as $sistema)
                                        <option value="{{ $sistema->id }}" {{ (int) $lead->sistema_id === $sistema->id ? 'selected' : '' }}>{{ $sistema->nome }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <x-input-label value="Origem" />
                                <select name="origem" class="mt-1 block w-full">
                                    <option value="">—</option>
                                    @foreach (\App\Models\Lead::ORIGENS as $origem)
                                        <option value="{{ $origem }}" {{ $lead->origem === $origem ? 'selected' : '' }}>{{ $origem }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <x-input-label value="Valor estimado (R$)" />
                                <x-text-input name="valor_estimado" type="number" step="0.01" min="0" class="mt-1 block w-full" value="{{ $lead->valor_estimado }}" />
                            </div>
                            <div class="sm:col-span-2">
                                <x-input-label value="Revenda (se aplicável)" />
                                <select name="revenda_id" class="mt-1 block w-full">
                                    <option value="">— Venda direta —</option>
                                    @foreach ($revendas as $revenda)
                                        <option value="{{ $revenda->id }}" {{ (int) $lead->revenda_id === $revenda->id ? 'selected' : '' }}>{{ $revenda->nome }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <x-input-label value="O que falta para fechar / o que está sendo acordado" />
                                <textarea name="proximo_passo" rows="2" class="mt-1 block w-full"
                                          placeholder="Ex.: aguardando retorno do financeiro deles; combinamos visita técnica dia 20/08.">{{ $lead->proximo_passo }}</textarea>
                            </div>
                            <div class="sm:col-span-2">
                                <x-input-label value="Observações" />
                                <textarea name="observacoes" rows="2" class="mt-1 block w-full">{{ $lead->observacoes }}</textarea>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <x-primary-button>Salvar</x-primary-button>
                        </div>
                    </form>

                    {{-- Anexos: print de e-mail/WhatsApp, proposta em PDF --}}
                    <div class="pt-5 border-t border-rule">
                        <h4 class="font-display text-[14px] font-semibold text-ink mb-2">Anexos</h4>

                        @if ($lead->anexos->isNotEmpty())
                            <div class="space-y-2 mb-3">
                                @foreach ($lead->anexos as $anexo)
                                    <div class="flex items-center gap-2 rounded-control border border-line bg-input px-3 py-2">
                                        @if ($anexo->eh_imagem)
                                            <a href="{{ $anexo->url }}" target="_blank" class="shrink-0">
                                                <img src="{{ $anexo->url }}" alt="{{ $anexo->nome_original }}"
                                                     class="h-10 w-10 rounded-badge object-cover border border-line">
                                            </a>
                                        @else
                                            <span class="shrink-0 h-10 w-10 rounded-badge bg-chip flex items-center justify-center text-[10px] font-mono uppercase text-ink-faint">
                                                arq
                                            </span>
                                        @endif
                                        <a href="{{ $anexo->url }}" target="_blank" class="min-w-0 flex-1 truncate text-[12.5px] text-ink hover:text-brand-text">
                                            {{ $anexo->nome_original }}
                                        </a>
                                        <span class="shrink-0 font-mono text-[10.5px] text-ink-faint">{{ $anexo->tamanho_formatado }}</span>
                                        <form method="POST" action="{{ route('leads.anexos.destroy', $anexo) }}"
                                              onsubmit="return confirm('Remover este anexo?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="shrink-0 text-ink-faint hover:text-crit-text transition" aria-label="Remover anexo">✕</button>
                                        </form>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <form method="POST" action="{{ route('leads.anexos.store', $lead) }}" enctype="multipart/form-data" class="flex items-center gap-2">
                            @csrf
                            <input type="file" name="anexos[]" multiple accept="{{ \App\Models\LeadAnexo::ACEITE_DO_SELETOR }}"
                                   class="flex-1 text-[12px] text-ink-faint file:mr-3 file:py-1.5 file:px-3 file:rounded-control file:border-0 file:bg-chip file:text-ink file:text-[11.5px] file:cursor-pointer">
                            <x-secondary-button type="submit">Anexar</x-secondary-button>
                        </form>
                    </div>

                    {{-- Histórico da conversa: data da última ligação, o que ficou combinado --}}
                    <div class="pt-5 border-t border-rule">
                        <h4 class="font-display text-[14px] font-semibold text-ink mb-2">Histórico da conversa</h4>

                        <form method="POST" action="{{ route('leads.comentarios.store', $lead) }}" class="mb-3 space-y-2">
                            @csrf
                            <textarea name="texto" rows="2" required class="block w-full"
                                      placeholder="Ex.: 12/08 falei com o financeiro, disseram que decidem até sexta."></textarea>
                            <div class="flex justify-end">
                                <x-secondary-button type="submit">Registrar</x-secondary-button>
                            </div>
                        </form>

                        @forelse ($lead->comentarios as $comentario)
                            <div class="py-2.5 border-t border-rule first:border-0">
                                <p class="text-[13px] text-ink whitespace-pre-line">{{ $comentario->texto }}</p>
                                <p class="mt-1 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                                    {{ $comentario->autor?->name ?? 'Autor removido' }} · {{ $comentario->created_at->format('d/m/Y H:i') }}
                                </p>
                            </div>
                        @empty
                            <p class="text-[12.5px] text-ink-mute">Nenhum registro ainda.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </x-modal>
    @endforeach
</x-app-layout>
