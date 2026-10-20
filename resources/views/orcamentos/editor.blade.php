@php
    /** @var \App\Models\Orcamento|null $o */
    use App\Models\Orcamento;
    use App\Services\Orcamentos\Modelos;
    $editavel = ! $o || $o->editavel();
    $campo = 'w-full rounded-control border border-btn-line bg-canvas px-2.5 py-1.5 text-[13px] text-ink focus:border-brand focus:outline-none disabled:opacity-70';
    $rotulo = 'grid gap-1 text-[11.5px] text-ink-mute';
    $botao = 'inline-flex h-[30px] items-center gap-1.5 whitespace-nowrap rounded-control border border-btn-line px-3 text-[12.5px] font-semibold text-ink-dim hover:border-brand hover:text-brand';
    $primario = 'inline-flex h-[30px] items-center gap-1.5 whitespace-nowrap rounded-control bg-brand px-3 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright';
    $cor = ['rascunho' => 'neutro', 'enviado' => 'marca', 'aprovado' => 'bom', 'recusado' => 'critico', 'substituido' => 'atencao'];
    $modelos = collect(Modelos::PRODUTOS)->map(fn ($m, $k) => Modelos::paraFormulario($k) + ['nome' => $m['nome']]);
@endphp
<x-app-layout>
    <x-slot name="titulo">{{ $o ? 'Orçamento '.$o->codigo() : 'Novo orçamento' }}</x-slot>
    <x-slot name="contexto"><span>{{ $o ? $o->cliente.' · '.Orcamento::SITUACOES[$o->situacao] : 'Comercial · VixCard' }}</span></x-slot>
    <x-slot name="acoes">
        <a href="{{ route('orcamentos.index') }}" class="{{ $botao }}">Orçamentos</a>
    </x-slot>

    {{-- docs/specs/orcamentos-vixcard: o formulário à esquerda e, à direita, a prévia
         VIVA do PDF — o mesmo Blade que o Chromium imprime. Enviado não se edita:
         revisa-se (Rev. + 1, mesmo número). --}}
    <div class="mx-auto w-full max-w-[1500px] space-y-3" x-data="orcamentoEditor()" x-init="iniciar()">
        @if (session('status'))<x-aviso>{{ session('status') }}</x-aviso>@endif
        @if ($errors->any())<x-aviso tom="critico" :segundos="0">{{ $errors->first() }}</x-aviso>@endif

        @if ($o)
            <div class="flex flex-wrap items-center gap-2 rounded-panel border border-line bg-surface px-4 py-2.5">
                <x-badge :tom="$cor[$o->situacao]" ponto>{{ Orcamento::SITUACOES[$o->situacao] }}</x-badge>
                <span class="text-[12.5px] text-ink-mute">
                    {{ $o->codigo() }} · criado por {{ $o->autor?->name ?? '—' }} em {{ $o->created_at->format('d/m/Y') }}
                    @if ($o->enviado_em) · enviado em {{ $o->enviado_em->format('d/m/Y') }} · válido até {{ $o->validoAte()->format('d/m/Y') }}@endif
                </span>
                @if ($revisoes->count() > 1)
                    <span class="text-[12px] text-ink-faint">Revisões:
                        @foreach ($revisoes as $r)<a href="{{ route('orcamentos.edit', $r->id) }}" class="{{ $r->id === $o->id ? 'font-semibold text-ink' : 'text-brand-text hover:underline' }}">{{ str_pad((string) $r->revisao, 2, '0', STR_PAD_LEFT) }}</a>@if (! $loop->last) · @endif @endforeach
                    </span>
                @endif
                <div class="ml-auto flex flex-wrap gap-1.5">
                    <a href="{{ route('orcamentos.pdf', $o) }}" target="_blank" rel="noopener" class="{{ $botao }}"><span class="h-4 w-4"><x-nav-icon name="document" /></span> PDF</a>
                    @if ($o->situacao === 'enviado')
                        <form method="POST" action="{{ route('orcamentos.resposta', $o) }}">@csrf<input type="hidden" name="resposta" value="aprovado"><button class="{{ $botao }}">Aprovado</button></form>
                        <form method="POST" action="{{ route('orcamentos.resposta', $o) }}">@csrf<input type="hidden" name="resposta" value="recusado"><button class="{{ $botao }}">Recusado</button></form>
                    @endif
                    @if (in_array($o->situacao, ['enviado', 'aprovado', 'recusado'], true))
                        <form method="POST" action="{{ route('orcamentos.revisar', $o) }}">@csrf<button class="{{ $botao }}">Revisar</button></form>
                    @endif
                    <form method="POST" action="{{ route('orcamentos.duplicar', $o) }}">@csrf<button class="{{ $botao }}">Duplicar</button></form>
                </div>
            </div>
            @if (! $editavel)
                <p class="text-[12.5px] text-ink-mute">Este orçamento está {{ mb_strtolower(Orcamento::SITUACOES[$o->situacao]) }} e não se altera. Para mudar algo, use <strong>Revisar</strong>: nasce a revisão seguinte, com o mesmo número.</p>
            @endif
        @endif

        <div class="grid items-start gap-3 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)]">
            <form id="form-orcamento" method="POST" action="{{ $o ? route('orcamentos.update', $o) : route('orcamentos.store') }}" class="grid gap-3" @input.debounce.600ms="previa()" @change="previa()">
                @csrf @if ($o) @method('PUT') @endif
                <input type="hidden" name="depois" x-ref="depois" value="">
                <fieldset class="contents" @disabled(! $editavel)>
                    <section class="grid gap-2.5 rounded-panel border border-line bg-surface p-4">
                        <h2 class="flex items-baseline justify-between gap-2 text-[13px] font-semibold text-ink">Produto <small class="text-[11.5px] font-normal text-ink-faint">preenche capa, escopo, passos e itens — tudo editável</small></h2>
                        <input type="hidden" name="produto" :value="produto">
                        <div class="flex flex-wrap gap-1.5">
                            <template x-for="(m, chave) in modelos" :key="chave">
                                <button type="button" @click="escolher(chave)" :aria-pressed="produto === chave" x-text="m.nome"
                                        :class="produto === chave ? 'border-brand bg-brand text-on-brand' : 'border-btn-line text-ink-dim hover:border-brand hover:text-brand'"
                                        class="rounded-full border px-3 py-1 text-[12px] font-semibold disabled:pointer-events-none" @disabled(! $editavel)></button>
                            </template>
                        </div>
                        <p x-show="aviso" x-cloak x-text="aviso" class="text-[12px] text-warn"></p>
                    </section>

                    <section class="grid gap-2.5 rounded-panel border border-line bg-surface p-4">
                        <h2 class="flex items-baseline justify-between gap-2 text-[13px] font-semibold text-ink">Cliente <small class="text-[11.5px] font-normal text-ink-faint">{{ $o ? $o->codigo() : 'o número sai ao salvar' }}</small></h2>
                        <div class="grid gap-2.5 sm:grid-cols-2">
                            <label class="{{ $rotulo }}" for="cliente">Cliente / empresa<input id="cliente" name="cliente" required maxlength="160" value="{{ old('cliente', $dados['cliente']) }}" class="{{ $campo }}"></label>
                            <label class="{{ $rotulo }}" for="contato">Contato<input id="contato" name="contato" maxlength="120" value="{{ old('contato', $dados['contato']) }}" class="{{ $campo }}"></label>
                            <label class="{{ $rotulo }}" for="cidade">Cidade / UF<input id="cidade" name="cidade" maxlength="120" value="{{ old('cidade', $dados['cidade']) }}" placeholder="Vitória · ES" class="{{ $campo }}"></label>
                            <label class="{{ $rotulo }}" for="validade_dias">Validade
                                <select id="validade_dias" name="validade_dias" class="{{ $campo }}">@foreach (Orcamento::VALIDADES as $v)<option value="{{ $v }}" @selected((int) old('validade_dias', $dados['validade_dias']) === $v)>{{ $v }} dias</option>@endforeach</select>
                            </label>
                        </div>
                    </section>

                    <section class="grid gap-2.5 rounded-panel border border-line bg-surface p-4">
                        <h2 class="flex items-baseline justify-between gap-2 text-[13px] font-semibold text-ink">Capa <small class="text-[11.5px] font-normal text-ink-faint">título, palavra em destaque e uma frase</small></h2>
                        <div class="grid gap-2.5 sm:grid-cols-2">
                            <label class="{{ $rotulo }}" for="titulo">Título<input id="titulo" name="titulo" required maxlength="120" x-model="f.titulo" class="{{ $campo }}"></label>
                            <label class="{{ $rotulo }}" for="destaque">Destaque (azul)<input id="destaque" name="destaque" maxlength="80" x-model="f.destaque" class="{{ $campo }}"></label>
                        </div>
                        <label class="{{ $rotulo }}" for="lead">Frase da capa<textarea id="lead" name="lead" rows="2" maxlength="600" x-model="f.lead" class="{{ $campo }}"></textarea></label>
                    </section>

                    <section class="grid gap-2 rounded-panel border border-line bg-surface p-4">
                        <h2 class="flex items-baseline justify-between gap-2 text-[13px] font-semibold text-ink">Itens <small class="text-[11.5px] font-normal text-ink-faint">quantidade aceita decimal (m, un, CNPJ)</small></h2>
                        <div class="hidden grid-cols-[minmax(0,1fr)_70px_64px_100px_104px_28px] gap-1.5 font-mono text-[10px] uppercase tracking-caps text-ink-faint sm:grid">
                            <span>Descrição</span><span>Qtd</span><span>Unid.</span><span>Unitário</span><span>Cobrança</span><span></span>
                        </div>
                        <template x-for="(it, n) in itens" :key="it.k">
                            <div class="grid grid-cols-2 gap-1.5 sm:grid-cols-[minmax(0,1fr)_70px_64px_100px_104px_28px]">
                                <input :name="`itens[${n}][descricao]`" x-model="it.descricao" maxlength="255" aria-label="Descrição" class="{{ $campo }} col-span-2 sm:col-span-1">
                                <input :name="`itens[${n}][quantidade]`" x-model="it.quantidade" type="number" step="0.001" min="0" aria-label="Quantidade" class="{{ $campo }}">
                                <input :name="`itens[${n}][unidade]`" x-model="it.unidade" maxlength="20" aria-label="Unidade" class="{{ $campo }}">
                                <input :name="`itens[${n}][valor_unitario]`" x-model="it.valor_unitario" type="number" step="0.01" min="0" aria-label="Valor unitário" class="{{ $campo }}">
                                <select :name="`itens[${n}][cobranca]`" x-model="it.cobranca" aria-label="Cobrança" class="{{ $campo }}"><option value="unico">Valor único</option><option value="mensal">Mensal</option></select>
                                <button type="button" @click="itens.splice(n, 1); previa()" aria-label="Remover item" class="text-[18px] leading-none text-ink-faint hover:text-crit">×</button>
                            </div>
                        </template>
                        <div class="flex flex-wrap gap-1.5">
                            <button type="button" @click="adicionar('unico')" class="{{ $botao }}">+ Item</button>
                            <button type="button" @click="adicionar('mensal')" class="{{ $botao }}">+ Mensalidade</button>
                        </div>
                        <div class="flex items-baseline justify-between border-t border-dashed border-line pt-2">
                            <span class="text-[12px] text-ink-mute">Total</span>
                            <b class="font-display text-[17px] tabular-nums text-ink" x-text="brl(total('unico')) + (total('mensal') ? ' + ' + brl(total('mensal')) + '/mês' : '')"></b>
                        </div>
                    </section>

                    <section class="grid gap-2.5 rounded-panel border border-line bg-surface p-4">
                        <h2 class="flex items-baseline justify-between gap-2 text-[13px] font-semibold text-ink">Pagamento <small class="text-[11.5px] font-normal text-ink-faint">a frase sai pronta no PDF</small></h2>
                        <div class="grid gap-2.5 sm:grid-cols-3">
                            <label class="{{ $rotulo }}" for="entrada">Entrada (R$)<input id="entrada" name="entrada" type="number" min="0" step="0.01" x-model="f.entrada" class="{{ $campo }}"></label>
                            <label class="{{ $rotulo }}" for="parcelas">Saldo em
                                <select id="parcelas" name="parcelas" x-model="f.parcelas" class="{{ $campo }}">@foreach (Orcamento::PARCELAS as $p)<option value="{{ $p }}">{{ $p === 1 ? 'à vista' : $p.'x' }}</option>@endforeach</select>
                            </label>
                            <label class="{{ $rotulo }}" for="desconto_avista">Desconto à vista (%)<input id="desconto_avista" name="desconto_avista" type="number" min="0" max="50" step="0.5" x-model="f.desconto_avista" class="{{ $campo }}"></label>
                        </div>
                    </section>

                    <section class="grid gap-2.5 rounded-panel border border-line bg-surface p-4">
                        <h2 class="text-[13px] font-semibold text-ink">Escopo e próximos passos</h2>
                        <div class="grid gap-2.5 sm:grid-cols-2">
                            <label class="{{ $rotulo }}" for="inclui">Inclui (um por linha)<textarea id="inclui" name="inclui" rows="4" x-model="f.inclui" class="{{ $campo }}"></textarea></label>
                            <label class="{{ $rotulo }}" for="nao_inclui">Não inclui<textarea id="nao_inclui" name="nao_inclui" rows="4" x-model="f.nao_inclui" class="{{ $campo }}"></textarea></label>
                        </div>
                        <label class="{{ $rotulo }}" for="passos">Próximos passos (um por linha)<textarea id="passos" name="passos" rows="4" x-model="f.passos" class="{{ $campo }}"></textarea></label>
                        <label class="{{ $rotulo }}" for="observacoes">Observações (opcional, sai no PDF)<textarea id="observacoes" name="observacoes" rows="2" maxlength="3000" class="{{ $campo }}">{{ old('observacoes', $dados['observacoes']) }}</textarea></label>
                        <label class="flex items-center gap-2 text-[12.5px] text-ink"><input type="hidden" name="aceite" value="0"><input type="checkbox" name="aceite" value="1" @checked(old('aceite', $dados['aceite'])) class="rounded border-btn-line"> Incluir bloco de aceite com assinaturas</label>
                    </section>
                </fieldset>

                @if ($editavel)
                    <div class="sticky bottom-0 z-10 flex flex-wrap items-center justify-end gap-2 rounded-panel border border-line bg-surface px-4 py-2.5" x-data="{ apagar: false }">
                        @if ($o)
                            <button type="button" x-show="! apagar" @click="apagar = true" class="{{ $botao }} mr-auto hover:!border-crit hover:!text-crit">Apagar rascunho</button>
                            <span x-show="apagar" x-cloak class="mr-auto flex items-center gap-1.5 text-[12.5px] text-crit">Apagar {{ $o->codigo() }}?
                                <button type="submit" form="apagar-orcamento" class="{{ $botao }} !border-crit !text-crit">Apagar</button>
                                <button type="button" @click="apagar = false" class="{{ $botao }}">Cancelar</button></span>
                        @endif
                        <button class="{{ $botao }}" @click="$refs.depois.value = ''">Salvar rascunho</button>
                        <button class="{{ $botao }}" @click="$refs.depois.value = 'pdf'">Salvar e gerar PDF</button>
                        {{-- Salva o que está na tela e então congela: o PDF desta revisão vai para Documentos. --}}
                        <button class="{{ $primario }}" @click="$refs.depois.value = 'enviar'" title="Salva, congela o PDF desta revisão e guarda na pasta do cliente em Documentos">Salvar e marcar como enviado</button>
                    </div>
                @endif
            </form>
            @if ($o && $editavel)
                <form id="apagar-orcamento" method="POST" action="{{ route('orcamentos.destroy', $o) }}" class="hidden">@csrf @method('DELETE')</form>
            @endif

            {{-- A prévia: o mesmo HTML do PDF, num iframe isolado, reduzido para caber. --}}
            <div class="sticky top-3 grid gap-2">
                <div class="flex items-center justify-between font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                    <span>Prévia do PDF · A4</span><span x-text="carregando ? 'atualizando…' : 'atualizada'"></span>
                </div>
                <div x-ref="moldura" class="h-[calc(100vh-110px)] min-h-[480px] overflow-auto rounded-panel border border-line bg-head">
                    <iframe x-ref="previa" title="Prévia do PDF" sandbox="allow-same-origin" class="block origin-top-left border-0" style="width: 820px; height: 3600px"></iframe>
                </div>
            </div>
        </div>
    </div>

    <script>
        function orcamentoEditor() {
            const modelos = @json($modelos);
            const dados = @json($dados);
            let k = 0;
            const comChave = lista => (lista || []).map(i => ({ k: ++k, descricao: i.descricao ?? '', quantidade: i.quantidade ?? 1, unidade: i.unidade ?? 'un', valor_unitario: i.valor_unitario ?? 0, cobranca: i.cobranca ?? 'unico' }));
            return {
                modelos, produto: dados.produto, aviso: '', carregando: false, pedido: 0,
                f: { titulo: dados.titulo ?? '', destaque: dados.destaque ?? '', lead: dados.lead ?? '', inclui: dados.inclui ?? '', nao_inclui: dados.nao_inclui ?? '',
                     passos: dados.passos ?? '', entrada: dados.entrada ?? 0, parcelas: String(dados.parcelas ?? 1), desconto_avista: dados.desconto_avista ?? 0 },
                itens: comChave(dados.itens),
                iniciar() {
                    new ResizeObserver(() => this.escalar()).observe(this.$refs.moldura);
                    // Depois do x-model preencher os campos: antes disso o formulário sai vazio.
                    this.$nextTick(() => this.previa());
                    @if (session('abrir_pdf') && $o) window.open(@json(route('orcamentos.pdf', $o)), '_blank', 'noopener'); @endif
                },
                escalar() {
                    const largura = this.$refs.moldura.clientWidth - 2;
                    this.$refs.previa.style.transform = `scale(${Math.min(1, largura / 820)})`;
                },
                escolher(chave) {
                    const m = this.modelos[chave];
                    this.produto = chave;
                    Object.assign(this.f, { titulo: m.titulo, destaque: m.destaque, lead: m.lead, inclui: m.inclui, nao_inclui: m.nao_inclui, passos: m.passos });
                    this.itens = comChave(m.itens);
                    this.aviso = 'Capa, escopo, passos e itens trocados pelo modelo de ' + m.nome + '. Cliente e pagamento ficaram.';
                    this.$nextTick(() => this.previa());
                },
                adicionar(cobranca) { this.itens.push({ k: ++k, descricao: cobranca === 'mensal' ? 'Mensalidade' : '', quantidade: 1, unidade: cobranca === 'mensal' ? 'mês' : 'un', valor_unitario: 0, cobranca }); },
                total(c) { return this.itens.filter(i => i.cobranca === c).reduce((s, i) => s + (parseFloat(i.quantidade) || 0) * (parseFloat(i.valor_unitario) || 0), 0); },
                brl(v) { return v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); },
                async previa() {
                    const meu = ++this.pedido;
                    this.carregando = true;
                    // Rascunho: a prévia do que está na tela. Enviado/aprovado: a do que está salvo (campos travados não vão no formulário).
                    const corpo = @js($editavel) ? new FormData(document.getElementById('form-orcamento')) : new FormData();
                    corpo.delete('_method');
                    @if ($o) corpo.append('id', {{ $o->id }}); @endif
                    @if (! $editavel) corpo.append('salvo', '1'); @endif
                    try {
                        const r = await fetch(@json(route('orcamentos.previa')), { method: 'POST', body: corpo,
                            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, Accept: 'application/json' } });
                        // Erro de validação volta 422 (JSON), nunca a página do sistema dentro da prévia.
                        if (meu === this.pedido && r.ok) { this.$refs.previa.srcdoc = await r.text(); this.escalar(); }
                    } catch (e) {}
                    if (meu === this.pedido) this.carregando = false;
                },
            };
        }
    </script>
</x-app-layout>
