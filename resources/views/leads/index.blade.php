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
            <div class="shrink-0 rounded-panel border px-4 py-2.5 text-[13px]"
                 style="background: rgb(var(--good) / var(--tint-alpha)); border-color: rgb(var(--good) / 0.25); color: rgb(var(--good))">
                {{ session('status') }}
            </div>
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

        {{-- Quadro ---------------------------------------------------------- --}}
        <div x-data="funil" class="flex-1 min-h-0 flex flex-col rounded-panel border border-line bg-board overflow-hidden">
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
            {{-- A roda do mouse rola o quadro na horizontal: num kanban a
                 direção que importa é a lateral, e mouse comum não tem eixo X.
                 A coluna sob o cursor tem prioridade — ver rolarQuadro(). --}}
            <div x-ref="quadro" @wheel="rolarQuadro($event)"
                 class="flex-1 min-h-0 flex gap-3 items-stretch overflow-x-auto p-3.5">
                @foreach ($estagios as $estagio)
                    @php $cards = $colunas[$estagio['chave']]; @endphp

                    <section class="shrink-0 flex flex-col min-h-0 rounded-control bg-panel border border-line overflow-hidden"
                             style="width: 276px; border-top: 3px solid rgb(var(--{{ $estagio['cor'] }}))"
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

                        <div data-coluna-lista class="flex-1 min-h-0 overflow-y-auto p-2 space-y-2">
                            @forelse ($cards as $lead)
                                @php
                                    $temperatura = $lead->temperatura();
                                    $tomTemp = ['quente' => 'good', 'esfriando' => 'warn', 'frio' => 'crit'][$temperatura] ?? null;
                                @endphp

                                <article draggable="true"
                                         data-lead="{{ $lead->id }}"
                                         @dragstart="arrastando = {{ $lead->id }}"
                                         @dragend="arrastando = null; sobre = null"
                                         x-data="{ menuAberto: false, destino: '{{ $estagio['chave'] }}' }"
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
                                    </div>

                                    {{--
                                        Arrastar não serve para todo mundo: quem
                                        navega por teclado, quem está no celular
                                        e a coluna "perdido" (que precisa do
                                        motivo) dependem deste menu. Ele não é
                                        um resto do desenho antigo — é o caminho
                                        acessível.
                                    --}}
                                    <div class="mt-2 pt-2 border-t border-rule">
                                        <button type="button" @click="menuAberto = ! menuAberto"
                                                class="font-mono text-[10.5px] uppercase tracking-caps text-ink-mute hover:text-brand transition">
                                            Mover ▾
                                        </button>

                                        <form x-show="menuAberto" x-cloak method="POST"
                                              action="{{ route('leads.mover', $lead) }}" class="mt-2 space-y-2">
                                            @csrf
                                            <select name="estagio" x-model="destino"
                                                    class="w-full h-8 text-[12px] rounded-control bg-input border-line text-ink">
                                                @foreach (\App\Models\Lead::ESTAGIOS as $chave => $label)
                                                    <option value="{{ $chave }}">{{ $label }}</option>
                                                @endforeach
                                            </select>

                                            <template x-if="destino === 'perdido'">
                                                <select name="motivo_perda" required
                                                        class="w-full h-8 text-[12px] rounded-control bg-input border-line text-ink">
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
                                <div class="rounded-ctl border border-dashed border-line px-2 py-6 text-center">
                                    <p class="text-[11.5px] text-ink-faint">Nenhum lead aqui</p>
                                    @if ($estagio['chave'] === 'lead')
                                        <button type="button" x-data @click="$dispatch('open-modal', 'novo-lead')"
                                                class="mt-1.5 font-mono text-[10.5px] uppercase tracking-caps text-brand-text hover:underline">
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

                /**
                 * Roda do mouse → rolagem lateral do quadro.
                 *
                 * Com uma ressalva que é o ponto todo: se o cursor está sobre
                 * uma coluna que AINDA tem card para mostrar, quem rola é a
                 * coluna. Sequestrar a roda ali tiraria o único jeito de ver o
                 * fim de uma coluna cheia — trocaria um incômodo por outro
                 * pior.
                 *
                 * Gesto horizontal de trackpad também passa direto: ali o
                 * navegador já faz a coisa certa sozinho.
                 */
                rolarQuadro(evento) {
                    if (Math.abs(evento.deltaX) > Math.abs(evento.deltaY)) return;

                    const coluna = evento.target.closest('[data-coluna-lista]');
                    if (coluna) {
                        const folgaAbaixo = coluna.scrollHeight - coluna.clientHeight - coluna.scrollTop;
                        const desce = evento.deltaY > 0 && folgaAbaixo > 1;
                        const sobe = evento.deltaY < 0 && coluna.scrollTop > 1;
                        if (desce || sobe) return;
                    }

                    const quadro = this.$refs.quadro;
                    if (! quadro || quadro.scrollWidth <= quadro.clientWidth) return;

                    evento.preventDefault();
                    quadro.scrollLeft += evento.deltaY;
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
</x-app-layout>
