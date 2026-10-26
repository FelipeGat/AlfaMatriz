@php
    /** @var \App\Models\Orcamento|null $o */
    use App\Models\Orcamento;
    use App\Services\Orcamentos\Modelos;
    $editavel = ! $o || $o->editavel();
    $campo = 'w-full rounded-control border border-btn-line bg-canvas px-2.5 py-1.5 text-[13px] text-ink focus:border-brand focus:outline-none disabled:opacity-70';
    $rotulo = 'grid gap-1 text-[11.5px] text-ink-mute';
    $botao = 'inline-flex h-[30px] items-center gap-1.5 whitespace-nowrap rounded-control border border-btn-line px-3 text-[12.5px] font-semibold text-ink-dim hover:border-brand hover:text-brand';
    $primario = 'inline-flex h-[30px] items-center gap-1.5 whitespace-nowrap rounded-control bg-brand px-3 text-[12.5px] font-semibold text-on-brand hover:bg-brand-bright';
    $cor = ['rascunho' => 'neutro', 'enviado' => 'marca', 'negociacao' => 'ambar', 'aprovado' => 'bom', 'recusado' => 'critico', 'substituido' => 'atencao'];
    $modelos = collect(Modelos::PRODUTOS)->map(fn ($m, $k) => Modelos::paraFormulario($k) + ['nome' => $m['nome']]);
    // Em que aba cai cada erro de validação: abre a primeira com erro e marca as demais.
    $abaDoCampo = fn (string $c) => match (true) {
        str_starts_with($c, 'itens') => 'itens',
        in_array(explode('.', $c)[0], ['entrada', 'parcelas', 'desconto_avista', 'pagamento_texto', 'planos', 'inclui', 'nao_inclui', 'passos', 'aceite', 'validade_dias'], true) => 'pagamento',
        in_array(explode('.', $c)[0], ['secoes', 'observacoes', 'notas_internas'], true) => 'conteudo',
        default => 'cliente',
    };
    $errosPorAba = collect($errors->keys())->countBy($abaDoCampo)->all();
    $abaInicial = array_key_first($errosPorAba) ?? 'cliente';
@endphp
<x-app-layout>
    <x-slot name="titulo">{{ $o ? 'Orçamento '.$o->codigo() : 'Novo orçamento' }}</x-slot>
    <x-slot name="contexto"><span>{{ $o ? $o->cliente.' · '.Orcamento::SITUACOES[$o->situacao] : 'Comercial · VixCard' }}</span></x-slot>
    <x-slot name="acoes">
        <a href="{{ route('orcamentos.index') }}" class="{{ $botao }}">Orçamentos</a>
    </x-slot>

    {{-- docs/specs/orcamentos-vixcard: o formulário em abas, como no Gestor (Felipe, 20/10/2026:
         "a tela está muito comprida […] quero em abas igual é no Gestor"), com o resumo
         financeiro fixo ao lado. Enviado não se edita: revisa-se (mesmo número, Rev. + 1). --}}
    <div class="mx-auto w-full max-w-[1500px] space-y-3" x-data="orcamentoEditor()" x-init="iniciar()">
        @if (session('status'))<x-aviso>{{ session('status') }}</x-aviso>@endif
        @if ($errors->any())<x-aviso tom="critico" :segundos="0">{{ $errors->first() }}</x-aviso>@endif

        @if ($o)
            <div class="flex flex-wrap items-center gap-2 rounded-panel border border-line bg-surface px-4 py-2.5">
                <x-badge :tom="$cor[$o->situacao]" ponto>{{ Orcamento::SITUACOES[$o->situacao] }}</x-badge>
                <span class="text-[12.5px] text-ink-mute">
                    {{ $o->codigo() }} · criado por {{ $o->autor?->name ?? '—' }} em {{ $o->created_at->format('d/m/Y') }}
                    @if ($o->enviado_em) · enviado em {{ $o->enviado_em->format('d/m/Y') }}@if ($o->validoAte()) · válido até {{ $o->validoAte()->format('d/m/Y') }}@endif @endif

                </span>
                @if ($revisoes->count() > 1)
                    <span class="text-[12px] text-ink-faint">Revisões:
                        @foreach ($revisoes as $r)<a href="{{ route('orcamentos.edit', $r->id) }}" class="{{ $r->id === $o->id ? 'font-semibold text-ink' : 'text-brand-text hover:underline' }}">{{ str_pad((string) $r->revisao, 2, '0', STR_PAD_LEFT) }}</a>@if (! $loop->last) · @endif @endforeach
                    </span>
                @endif
                <div class="ml-auto flex flex-wrap gap-1.5">
                    <a href="{{ route('orcamentos.pdf', $o) }}" target="_blank" rel="noopener" class="{{ $botao }}"><span class="h-4 w-4"><x-nav-icon name="document" /></span> PDF</a>
                    @if ($o->situacao === 'enviado')
                        <form method="POST" action="{{ route('orcamentos.resposta', $o) }}">@csrf<input type="hidden" name="resposta" value="negociacao"><button class="{{ $botao }}">Em negociação</button></form>
                    @endif
                    @if (in_array($o->situacao, ['enviado', 'negociacao'], true))
                        <form method="POST" action="{{ route('orcamentos.resposta', $o) }}">@csrf<input type="hidden" name="resposta" value="aprovado"><button class="{{ $botao }}">Aprovado</button></form>
                        <form method="POST" action="{{ route('orcamentos.resposta', $o) }}">@csrf<input type="hidden" name="resposta" value="recusado"><button class="{{ $botao }}">Recusado</button></form>
                    @endif
                    @if ($o->situacao !== 'substituido')
                        <form method="POST" action="{{ route('orcamentos.revisar', $o) }}" title="Cria {{ str_pad((string) $o->numero, 3, '0', STR_PAD_LEFT) }}.Rev{{ $o->revisao + 1 }} e guarda esta versão">@csrf<button class="{{ $botao }}">Revisar</button></form>
                    @endif
                    <form method="POST" action="{{ route('orcamentos.duplicar', $o) }}">@csrf<button class="{{ $botao }}">Duplicar</button></form>
                </div>
            </div>
            @if (! $editavel)
                <p class="text-[12.5px] text-ink-mute">Este orçamento está {{ mb_strtolower(Orcamento::SITUACOES[$o->situacao]) }} e não se altera. Para mudar algo, use <strong>Revisar</strong>: nasce a revisão seguinte, com o mesmo número.</p>
            @endif
        @endif

        <form id="form-orcamento" method="POST" enctype="multipart/form-data" action="{{ $o ? route('orcamentos.update', $o) : route('orcamentos.store') }}" class="grid gap-3">
                @csrf @if ($o) @method('PUT') @endif
                <input type="hidden" name="depois" x-ref="depois" value="">

                {{-- As abas: o número do lado mostra quantos erros a aba tem. --}}
                <nav class="sticky top-0 z-20 flex flex-wrap gap-1.5 rounded-panel border border-line bg-surface p-1.5 shadow-sm" role="tablist">
                    @foreach (['cliente' => '1 · Cliente & capa', 'itens' => '2 · Itens & valores', 'pagamento' => '3 · Pagamento & escopo', 'conteudo' => '4 · Conteúdo & notas'] as $chave => $nome)
                        <button type="button" role="tab" @click="aba = '{{ $chave }}'" :aria-selected="(aba === '{{ $chave }}').toString()"
                                :class="aba === '{{ $chave }}' ? 'bg-brand text-on-brand shadow-sm' : 'text-ink-dim hover:bg-head'"
                                class="inline-flex items-center gap-1.5 rounded-full px-4 py-1.5 text-[13px] font-semibold">
                            {{ $nome }}
                            @if (($errosPorAba[$chave] ?? 0) > 0)<span class="rounded-full bg-crit px-1.5 text-[11px] font-bold text-white">{{ $errosPorAba[$chave] }}</span>@endif
                        </button>
                    @endforeach
                </nav>

                <div class="grid items-start gap-3 lg:grid-cols-[minmax(0,1fr)_300px]">
                <div class="grid min-w-0 gap-3">
                <fieldset class="contents" @disabled(! $editavel)>
                    <div x-show="aba === 'cliente'" class="grid gap-3">
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
                        {{-- O pré-cliente do Funil de Vendas (padrao-e-historico.md, FR-H1): escolher preenche o que ele já tem. --}}
                        <label class="{{ $rotulo }}" for="lead_id">Pré-cliente (Funil de Vendas)
                            <select id="lead_id" name="lead_id" x-model="leadId" @change="usarPreCliente()" class="{{ $campo }}">
                                <option value="">— sem vínculo —</option>
                                @foreach ($preClientes as $pc)<option value="{{ $pc['id'] }}">{{ $pc['nome'] }}{{ $pc['projeto'] ? ' · '.$pc['projeto'] : '' }}</option>@endforeach
                            </select>
                        </label>
                        <div class="grid gap-2.5 sm:grid-cols-2">
                            <label class="{{ $rotulo }}" for="cliente">Cliente / empresa<input id="cliente" name="cliente" required maxlength="160" x-model="f.cliente" class="{{ $campo }}"></label>
                            <label class="{{ $rotulo }}" for="contato">Contato<input id="contato" name="contato" maxlength="120" value="{{ old('contato', $dados['contato']) }}" class="{{ $campo }}"></label>
                            <label class="{{ $rotulo }}" for="cidade">Cidade / UF<input id="cidade" name="cidade" maxlength="120" value="{{ old('cidade', $dados['cidade']) }}" placeholder="Vitória · ES" class="{{ $campo }}"></label>
                            <label class="{{ $rotulo }}" for="validade_dias">Validade
                                <select id="validade_dias" name="validade_dias" class="{{ $campo }}">
                                    @if (! $dados['validade_dias'])<option value="" selected>— sem validade —</option>@endif
                                    @foreach (Orcamento::VALIDADES as $v)<option value="{{ $v }}" @selected((int) old('validade_dias', $dados['validade_dias']) === $v)>{{ $v }} dias</option>@endforeach
                                </select>
                            </label>
                        </div>
                        <details class="rounded-control border border-line px-3 py-2" @if (array_filter((array) $dados['dados_cliente']) || $dados['objeto']) open @endif>
                            <summary class="cursor-pointer text-[12.5px] font-semibold text-ink-dim">Cliente e objeto <small class="font-normal text-ink-faint">dados cadastrais e o que está sendo contratado — saem numa página própria</small></summary>
                            <div class="mt-2 grid gap-2.5 sm:grid-cols-2">
                                @foreach (Orcamento::DADOS_CLIENTE as $chave => $nome)
                                    <label class="{{ $rotulo }}" for="dc-{{ $chave }}">{{ $nome }}<input id="dc-{{ $chave }}" name="dados_cliente[{{ $chave }}]" maxlength="255" x-model="dc.{{ $chave }}" class="{{ $campo }}"></label>
                                @endforeach
                                <label class="{{ $rotulo }}" for="natureza">Natureza
                                    <select id="natureza" name="natureza" class="{{ $campo }}"><option value="">—</option>@foreach (Orcamento::NATUREZAS as $k => $n)<option value="{{ $k }}" @selected($dados['natureza'] === $k)>{{ $n }}</option>@endforeach</select>
                                </label>
                            </div>
                            <label class="{{ $rotulo }} mt-2" for="objeto">Objeto (um item por linha)<textarea id="objeto" name="objeto" rows="3" maxlength="3000" class="{{ $campo }}">{{ $dados['objeto'] }}</textarea></label>
                        </details>
                    </section>

                    <section class="grid gap-2.5 rounded-panel border border-line bg-surface p-4">
                        <h2 class="flex items-baseline justify-between gap-2 text-[13px] font-semibold text-ink">Capa <small class="text-[11.5px] font-normal text-ink-faint">título, palavra em destaque e uma frase</small></h2>
                        <div class="grid gap-2.5 sm:grid-cols-2">
                            <label class="{{ $rotulo }}" for="titulo">Título<input id="titulo" name="titulo" required maxlength="120" x-model="f.titulo" class="{{ $campo }}"></label>
                            <label class="{{ $rotulo }}" for="destaque">Destaque (azul)<input id="destaque" name="destaque" maxlength="80" x-model="f.destaque" class="{{ $campo }}"></label>
                        </div>
                        <label class="{{ $rotulo }}" for="lead">Frase da capa<textarea id="lead" name="lead" rows="2" maxlength="600" x-model="f.lead" class="{{ $campo }}"></textarea></label>
                        <div class="grid gap-2.5 sm:grid-cols-3">
                            <label class="{{ $rotulo }}" for="tipo">Tipo
                                <input id="tipo" name="tipo" list="tipos-orcamento" maxlength="40" value="{{ $dados['tipo'] }}" placeholder="Proposta comercial" class="{{ $campo }}">
                                <datalist id="tipos-orcamento">@foreach (Orcamento::TIPOS as $t)<option value="{{ $t }}">@endforeach</datalist>
                            </label>
                            <label class="{{ $rotulo }}" for="rotulo_3">3ª coluna da capa (rótulo)<input id="rotulo_3" name="rotulo_3" maxlength="30" value="{{ $dados['rotulo_3'] }}" placeholder="Produto" class="{{ $campo }}"></label>
                            <label class="{{ $rotulo }}" for="valor_3">3ª coluna (valor)<input id="valor_3" name="valor_3" maxlength="160" value="{{ $dados['valor_3'] }}" placeholder="o nome do produto" class="{{ $campo }}"></label>
                        </div>
                        {{-- O desenho do PDF (modelo-vixhouse.md): VixHouse para sistema, clássico para cartões e o que já existia. --}}
                        <label class="{{ $rotulo }}" for="modelo">Modelo do PDF
                            <select id="modelo" name="modelo" x-model="modelo" class="{{ $campo }}">
                                @foreach (Orcamento::MODELOS as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach
                            </select>
                            <small class="text-[11px] text-ink-faint" x-show="modelo === 'vixhouse'">Capa marinho com a marca VixHouse, uma página por assunto, telas com selo de tela ilustrativa e parecer do cliente no fim.</small>
                        </label>
                        {{-- A foto de fundo da capa: sem ela, vale a capa padrão dos ajustes. No modelo VixHouse ela aparece emoldurada no meio da capa. --}}
                        <div class="grid gap-2.5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                            <label class="{{ $rotulo }}" for="capa">Foto de fundo da capa (JPG, PNG ou WebP, até 8 MB)
                                <input id="capa" name="capa" type="file" accept="image/jpeg,image/png,image/webp" class="{{ $campo }} file:mr-2 file:rounded file:border-0 file:bg-head file:px-2 file:py-1 file:text-[12px]">
                            </label>
                            @if ($o?->capa_imagem)
                                <label class="flex items-center gap-1.5 pb-2 text-[12px] text-ink-dim"><input type="checkbox" name="remover_capa" value="1" class="rounded border-btn-line"> tirar a foto atual</label>
                            @endif
                        </div>
                    </section>

                    </div>
                    <div x-show="aba === 'itens'" x-cloak class="grid gap-3">
                    {{-- Material, implantação e recorrente separados desde o começo (Felipe, 20/10/2026). --}}
                    <section class="grid gap-2 rounded-panel border border-line bg-surface p-4">
                        <h2 class="flex items-baseline justify-between gap-2 text-[13px] font-semibold text-ink">Itens <small class="text-[11.5px] font-normal text-ink-faint">cada item é material, implantação ou recorrente · opcional fica fora do total</small></h2>
                        <div class="hidden grid-cols-[minmax(0,1fr)_64px_56px_92px_118px_44px_24px] gap-1.5 font-mono text-[10px] uppercase tracking-caps text-ink-faint sm:grid">
                            <span>Descrição</span><span>Qtd</span><span>Unid.</span><span>Unitário</span><span>Categoria</span><span title="Fora do total">Opc.</span><span></span>
                        </div>
                        <template x-for="(it, n) in itens" :key="it.k">
                            <div class="grid grid-cols-2 gap-1.5 sm:grid-cols-[minmax(0,1fr)_64px_56px_92px_118px_44px_24px]" :class="it.opcional ? 'opacity-70' : ''">
                                <input :name="`itens[${n}][descricao]`" x-model="it.descricao" maxlength="255" aria-label="Descrição" class="{{ $campo }} col-span-2 sm:col-span-1">
                                <input :name="`itens[${n}][quantidade]`" x-model="it.quantidade" type="number" step="0.001" min="0" aria-label="Quantidade" class="{{ $campo }}">
                                <input :name="`itens[${n}][unidade]`" x-model="it.unidade" maxlength="20" aria-label="Unidade" class="{{ $campo }}">
                                <input :name="`itens[${n}][valor_unitario]`" x-model="it.valor_unitario" type="number" step="0.01" min="0" aria-label="Valor unitário" class="{{ $campo }}">
                                <select :name="`itens[${n}][categoria]`" x-model="it.categoria" aria-label="Categoria" class="{{ $campo }}">
                                    @foreach (\App\Models\OrcamentoItem::CATEGORIAS as $k => $nome)<option value="{{ $k }}">{{ $nome }}</option>@endforeach
                                </select>
                                <label class="flex items-center justify-center" title="Opcional: aparece com o preço, fora do total">
                                    <input type="hidden" :name="`itens[${n}][opcional]`" :value="it.opcional ? 1 : 0">
                                    <input type="checkbox" x-model="it.opcional" @change="previa()" aria-label="Opcional (fora do total)" class="rounded border-btn-line">
                                </label>
                                <button type="button" @click="itens.splice(n, 1); previa()" aria-label="Remover item" class="text-[18px] leading-none text-ink-faint hover:text-crit">×</button>
                            </div>
                        </template>
                        <div class="flex flex-wrap gap-1.5">
                            <button type="button" @click="adicionar('material')" class="{{ $botao }}">+ Material</button>
                            <button type="button" @click="adicionar('implantacao')" class="{{ $botao }}">+ Implantação</button>
                            <button type="button" @click="adicionar('recorrente')" class="{{ $botao }}">+ Recorrente</button>
                        </div>
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 border-t border-dashed border-line pt-2 text-[12.5px] sm:grid-cols-4">
                            <div><dt class="text-ink-mute">Material</dt><dd class="font-semibold tabular-nums text-ink" x-text="brl(total('material'))"></dd></div>
                            <div><dt class="text-ink-mute">Implantação</dt><dd class="font-semibold tabular-nums text-ink" x-text="brl(total('implantacao'))"></dd></div>
                            <div><dt class="text-ink-mute">Total do projeto</dt><dd class="font-display text-[15px] font-semibold tabular-nums text-ink" x-text="brl(total('material') + total('implantacao'))"></dd></div>
                            <div><dt class="text-ink-mute">Recorrente</dt><dd class="font-semibold tabular-nums text-ink" x-text="brl(total('recorrente')) + '/mês'"></dd></div>
                        </dl>
                    </section>

                    </div>
                    <div x-show="aba === 'pagamento'" x-cloak class="grid gap-3">
                    <section class="grid gap-2.5 rounded-panel border border-line bg-surface p-4">
                        <h2 class="flex items-baseline justify-between gap-2 text-[13px] font-semibold text-ink">Pagamento <small class="text-[11.5px] font-normal text-ink-faint">a frase sai pronta no PDF</small></h2>
                        <div class="grid gap-2.5 sm:grid-cols-3">
                            <label class="{{ $rotulo }}" for="entrada">Entrada (R$)<input id="entrada" name="entrada" type="number" min="0" step="0.01" x-model="f.entrada" class="{{ $campo }}"></label>
                            <label class="{{ $rotulo }}" for="parcelas">Saldo em
                                <select id="parcelas" name="parcelas" x-model="f.parcelas" class="{{ $campo }}">@foreach (Orcamento::PARCELAS as $p)<option value="{{ $p }}">{{ $p === 1 ? 'à vista' : $p.'x' }}</option>@endforeach</select>
                            </label>
                            <label class="{{ $rotulo }}" for="desconto_avista">Desconto à vista (%)<input id="desconto_avista" name="desconto_avista" type="number" min="0" max="50" step="0.5" x-model="f.desconto_avista" class="{{ $campo }}"></label>
                        </div>
                        <label class="{{ $rotulo }}" for="pagamento_texto">Condições em texto livre (substitui a frase gerada)<textarea id="pagamento_texto" name="pagamento_texto" rows="2" maxlength="3000" class="{{ $campo }}">{{ $dados['pagamento_texto'] }}</textarea></label>
                        <label class="{{ $rotulo }}" for="planos">Planos alternativos (um por linha: nome | valor | período | observação)<textarea id="planos" name="planos" rows="2" maxlength="3000" placeholder="Anual | 350 | mês por CNPJ | cobrado por ano" class="{{ $campo }}">{{ $dados['planos'] }}</textarea></label>
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

                    </div>
                    <div x-show="aba === 'conteudo'" x-cloak class="grid gap-3">
                    {{-- As seções opcionais do padrão (padrao-e-historico.md, FR-H3): cada uma liga e desliga. --}}
                    <section class="grid gap-2 rounded-panel border border-line bg-surface p-4">
                        <h2 class="flex items-baseline justify-between gap-2 text-[13px] font-semibold text-ink">Seções opcionais <small class="text-[11.5px] font-normal text-ink-faint">listas: uma linha por item · tabelas: "a | b" por linha</small></h2>
                        @php
                            $rotulosCampos = ['titulo' => 'Título da seção', 'texto' => 'Texto', 'citacao' => 'Citação do cliente', 'numeros' => 'Números (valor | rótulo, por linha)',
                                'itens' => 'Dores (uma por linha; no modelo VixHouse, "ponto | o que vimos | o que causa | como resolve" vira a tabela do diagnóstico)', 'passos' => 'Passos (um por linha; "Título: descrição")', 'linhas' => 'Linhas (a | b, por linha)', 'etapas' => 'Etapas (uma por linha)',
                                'prazo' => 'Prazo', 'frase' => 'Frase de fechamento', 'mensagem' => 'Mensagem ao contato'];
                        @endphp
                        @foreach (Orcamento::SECOES as $chave => $secao)
                            @php $valores = (array) data_get($dados['secoes'], $chave, []); @endphp
                            <details class="rounded-control border border-line px-3 py-2" @if (! empty($valores['ativo'])) open @endif>
                                <summary class="flex cursor-pointer items-center gap-2 text-[12.5px] font-semibold text-ink-dim">
                                    <input type="hidden" name="secoes[{{ $chave }}][ativo]" value="0">
                                    <input type="checkbox" name="secoes[{{ $chave }}][ativo]" value="1" @checked(! empty($valores['ativo'])) @click.stop class="rounded border-btn-line">
                                    {{ $secao['nome'] }}
                                    @if ($chave === 'extras')<small class="font-normal text-ink-faint">cada seção começa com uma linha "## Título"</small>@endif
                                    @if ($chave === 'perguntas')<small class="font-normal text-ink-faint">pergunta | resposta, por linha</small>@endif
                                </summary>
                                <div class="mt-2 grid gap-2">
                                    @foreach ($secao['campos'] as $c)
                                        @php $longo = in_array($c, ['texto', 'numeros', 'itens', 'passos', 'linhas', 'etapas', 'mensagem'], true); @endphp
                                        <label class="{{ $rotulo }}">{{ $chave === 'perguntas' && $c === 'linhas' ? 'Perguntas (pergunta | resposta, por linha)' : $rotulosCampos[$c] }}
                                            @if ($longo)
                                                <textarea name="secoes[{{ $chave }}][{{ $c }}]" rows="{{ $chave === 'extras' ? 8 : 3 }}" maxlength="12000" class="{{ $campo }}">{{ $valores[$c] ?? '' }}</textarea>
                                            @else
                                                <input name="secoes[{{ $chave }}][{{ $c }}]" maxlength="500" value="{{ $valores[$c] ?? '' }}" class="{{ $campo }}">
                                            @endif
                                        </label>
                                    @endforeach
                                </div>
                            </details>
                        @endforeach
                    </section>

                    {{-- As imagens do corpo (20/10/2026, no padrão da proposta da FRID): cada uma escolhe a página onde entra. --}}
                    @php $lugares = $o ? $o->lugaresDeImagem() : Orcamento::LUGARES_DE_IMAGEM; @endphp
                    <section class="grid gap-2 rounded-panel border border-line bg-surface p-4">
                        <h2 class="flex items-baseline justify-between gap-2 text-[13px] font-semibold text-ink">Imagens da proposta <small class="text-[11.5px] font-normal text-ink-faint">fotos, telas e folhetos: cada uma na página que escolher</small></h2>
                        @foreach ($o?->imagensDoCorpo() ?? [] as $n => $img)
                            <div class="grid gap-2.5 rounded-control border border-line p-2 sm:grid-cols-[96px_minmax(0,1fr)]">
                                <img src="{{ route('orcamentos.imagem', [$o->id, $n]) }}" alt="" class="h-[72px] w-[96px] rounded object-cover">
                                <div class="grid gap-1.5">
                                    <input type="hidden" name="imagens[{{ $n }}][arquivo]" value="{{ $img['arquivo'] }}">
                                    <input name="imagens[{{ $n }}][legenda]" maxlength="300" value="{{ $img['legenda'] }}" placeholder="Legenda (opcional)" aria-label="Legenda" class="{{ $campo }}">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <select name="imagens[{{ $n }}][onde]" aria-label="Onde entra" class="{{ $campo }} max-w-[320px]">
                                            @foreach ($lugares as $chave => $nome)<option value="{{ $chave }}" @selected($img['onde'] === $chave)>{{ $nome }}</option>@endforeach
                                        </select>
                                        <label class="flex items-center gap-1.5 text-[12px] text-ink-dim"><input type="checkbox" name="imagens[{{ $n }}][remover]" value="1" class="rounded border-btn-line"> tirar</label>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                        <div class="grid gap-2.5 sm:grid-cols-[minmax(0,1fr)_minmax(0,220px)] sm:items-end">
                            <label class="{{ $rotulo }}" for="imagens_novas">Acrescentar imagens (JPG, PNG ou WebP, até 8 MB cada)
                                <input id="imagens_novas" name="imagens_novas[]" type="file" multiple accept="image/jpeg,image/png,image/webp" class="{{ $campo }} file:mr-2 file:rounded file:border-0 file:bg-head file:px-2 file:py-1 file:text-[12px]">
                            </label>
                            <label class="{{ $rotulo }}" for="imagens_novas_onde">Entram em
                                <select id="imagens_novas_onde" name="imagens_novas_onde" class="{{ $campo }}">@foreach ($lugares as $chave => $nome)<option value="{{ $chave }}">{{ $nome }}</option>@endforeach</select>
                            </label>
                        </div>
                    </section>

                    <section class="grid gap-2 rounded-panel border border-line bg-surface p-4">
                        <h2 class="flex items-baseline justify-between gap-2 text-[13px] font-semibold text-ink">Notas internas <small class="text-[11.5px] font-normal text-ink-faint">nunca vão para o PDF</small></h2>
                        <textarea name="notas_internas" rows="3" maxlength="6000" class="{{ $campo }}">{{ $dados['notas_internas'] }}</textarea>
                    </section>
                    </div>
                </fieldset>
                </div>

                {{-- O resumo financeiro, fixo ao lado como no Gestor, com as ações. --}}
                <aside class="grid gap-3 lg:sticky lg:top-16">
                    <section class="rounded-panel border border-line bg-surface p-4">
                        <h2 class="mb-2.5 text-[13px] font-semibold text-ink">Resumo financeiro</h2>
                        <dl class="grid gap-1.5 text-[13px]">
                            <div class="flex justify-between"><dt class="text-ink-mute">Material</dt><dd class="tabular-nums text-ink" x-text="brl(total('material'))"></dd></div>
                            <div class="flex justify-between"><dt class="text-ink-mute">Implantação</dt><dd class="tabular-nums text-ink" x-text="brl(total('implantacao'))"></dd></div>
                            <div class="flex items-end justify-between border-t border-line pt-2"><dt class="text-[11px] font-semibold uppercase tracking-caps text-ink-mute">Total do projeto</dt>
                                <dd class="font-display text-[22px] font-semibold tabular-nums text-brand-text" x-text="brl(total('material') + total('implantacao'))"></dd></div>
                            <div class="flex justify-between"><dt class="text-ink-mute">Recorrente</dt><dd class="font-semibold tabular-nums text-ink" x-text="brl(total('recorrente')) + '/mês'"></dd></div>
                            <div class="flex justify-between text-[12px]" x-show="itens.some(i => i.opcional)" x-cloak><dt class="text-ink-faint">Opcionais (fora do total)</dt><dd class="tabular-nums text-ink-faint" x-text="itens.filter(i => i.opcional).length"></dd></div>
                        </dl>
                    </section>
                    <section class="grid gap-2 rounded-panel border border-line bg-surface p-4" x-data="{ apagar: false }">
                        @if ($editavel)
                            <button class="{{ $primario }} h-[34px] w-full justify-center" @click="$refs.depois.value = ''">Salvar</button>
                            <button class="{{ $botao }} w-full justify-center" @click="$refs.depois.value = 'pdf'">Salvar e ver o PDF</button>
                            {{-- Salva o que está na tela e então congela: o PDF desta revisão vai para Documentos. --}}
                            <button class="{{ $botao }} w-full justify-center" @click="$refs.depois.value = 'enviar'" title="Salva, congela o PDF desta revisão e guarda na pasta do cliente em Documentos">Salvar e marcar como enviado</button>
                        @elseif ($o)
                            <a href="{{ route('orcamentos.pdf', $o) }}" target="_blank" rel="noopener" class="{{ $primario }} h-[34px] w-full justify-center">Ver o PDF</a>
                        @endif
                        <a href="{{ route('orcamentos.index') }}" class="{{ $botao }} w-full justify-center">Voltar aos orçamentos</a>
                        @if ($o && $editavel)
                            <button type="button" x-show="! apagar" @click="apagar = true" class="mt-1 text-[12px] text-ink-faint hover:text-crit">Apagar rascunho</button>
                            <span x-show="apagar" x-cloak class="flex items-center justify-center gap-1.5 text-[12.5px] text-crit">Apagar {{ $o->codigo() }}?
                                <button type="submit" form="apagar-orcamento" class="{{ $botao }} !border-crit !text-crit">Apagar</button>
                                <button type="button" @click="apagar = false" class="{{ $botao }}">Não</button></span>
                        @endif
                    </section>
                </aside>
                </div>
            </form>
            @if ($o && $editavel)
                <form id="apagar-orcamento" method="POST" action="{{ route('orcamentos.destroy', $o) }}" class="hidden">@csrf @method('DELETE')</form>
            @endif
    </div>

    <script>
        function orcamentoEditor() {
            const modelos = @json($modelos);
            const dados = @json($dados);
            let k = 0;
            const comChave = lista => (lista || []).map(i => ({ k: ++k, descricao: i.descricao ?? '', quantidade: i.quantidade ?? 1, unidade: i.unidade ?? 'un', valor_unitario: i.valor_unitario ?? 0,
                categoria: i.categoria ?? (i.cobranca === 'mensal' ? 'recorrente' : 'material'), opcional: !! i.opcional || i.cobranca === 'opcao' }));
            const preClientes = @json(collect($preClientes)->keyBy('id'));
            return {
                modelos, produto: dados.produto, modelo: dados.modelo ?? 'vixhouse', aviso: '', aba: @js($abaInicial),
                leadId: dados.lead_id ? String(dados.lead_id) : '',
                dc: Object.assign({ razao_social: '', cnpj: '', ie: '', endereco: '', cargo: '', telefone: '', email: '', atividade: '' }, dados.dados_cliente || {}),
                f: { cliente: dados.cliente ?? '', titulo: dados.titulo ?? '', destaque: dados.destaque ?? '', lead: dados.lead ?? '', inclui: dados.inclui ?? '', nao_inclui: dados.nao_inclui ?? '',
                     passos: dados.passos ?? '', entrada: dados.entrada ?? 0, parcelas: String(dados.parcelas ?? 1), desconto_avista: dados.desconto_avista ?? 0 },
                itens: comChave(dados.itens),
                iniciar() {
                    @if (session('abrir_pdf') && $o) window.open(@json(route('orcamentos.pdf', $o)), '_blank', 'noopener'); @endif
                },
                // A prévia ao lado saiu (20/10/2026); o PDF abre pelo "Salvar e ver o PDF".
                previa() {},
                escolher(chave) {
                    const m = this.modelos[chave];
                    this.produto = chave;
                    this.modelo = m.modelo;
                    Object.assign(this.f, { titulo: m.titulo, destaque: m.destaque, lead: m.lead, inclui: m.inclui, nao_inclui: m.nao_inclui, passos: m.passos });
                    this.itens = comChave(m.itens);
                    this.aviso = 'Capa, escopo, passos e itens trocados pelo modelo de ' + m.nome + '. Cliente e pagamento ficaram.';
                    this.$nextTick(() => this.previa());
                },
                // Escolher o pré-cliente preenche o que ele tem, sem apagar o que já foi digitado.
                usarPreCliente() {
                    const p = preClientes[this.leadId];
                    if (! p) return;
                    this.f.cliente = p.nome;
                    if (p.cnpj) this.dc.cnpj = p.cnpj;
                    if (p.telefone) this.dc.telefone = p.telefone;
                    if (p.email) this.dc.email = p.email;
                    this.$nextTick(() => this.previa());
                },
                adicionar(categoria) { this.itens.push({ k: ++k, descricao: categoria === 'recorrente' ? 'Mensalidade' : '', quantidade: 1, unidade: categoria === 'recorrente' ? 'mês' : (categoria === 'implantacao' ? 'serv' : 'un'), valor_unitario: 0, categoria, opcional: false }); },
                total(c) { return this.itens.filter(i => i.categoria === c && ! i.opcional).reduce((s, i) => s + (parseFloat(i.quantidade) || 0) * (parseFloat(i.valor_unitario) || 0), 0); },
                brl(v) { return v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); },
            };
        }
    </script>
</x-app-layout>
