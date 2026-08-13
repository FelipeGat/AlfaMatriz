@php
    /**
     * Os campos do sistema — cadastro e edição.
     *
     * Um formulário só para as duas telas porque a diferença entre elas é de
     * TRÊS campos, não de assunto: o slug, que só se escolhe uma vez, e a
     * natureza, que depois de haver cliente ou tier deixa de ser editável. Dois
     * formulários divergiriam no primeiro campo novo — e é assim que uma tela
     * passa a gravar o que a outra não grava.
     *
     * `$sistema` nulo é cadastro; `$natureza` só o cadastro passa.
     */
    $edicao = (bool) ($sistema ?? null);
    $naturezaAtual = old('natureza', $edicao ? $sistema->natureza : $natureza);
    $podeTrocar = ! $edicao || $sistema->podeTrocarDeNatureza();
@endphp

{{-- O bloco comercial aparece e some conforme a natureza, e quem manda é o
     Alpine. Sem JS, o servidor já renderizou o estado certo para a natureza
     atual — o `style` inline abaixo é o que o `x-show` sobrescreve ao ligar. --}}
<div x-data="{ natureza: '{{ $naturezaAtual }}' }" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div>
        <x-input-label for="nome" value="Nome" />
        <x-text-input id="nome" name="nome" type="text" class="mt-1 block w-full" required
                      value="{{ old('nome', $sistema->nome ?? '') }}" placeholder="AlfaHome" />
        <x-input-error :messages="$errors->get('nome')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="natureza" value="Natureza" />

        @if ($podeTrocar)
            {{-- As MESMAS medidas do `x-text-input` (`h-8`, `text-[12.5px]`,
                 `rounded-control`). Sem elas o select nasce com a altura padrão
                 do navegador — uns 10px mais alto que o campo Nome, que está
                 ao lado dele na mesma fileira. Cor e raio já vêm da regra base
                 de `app.css`; o `border-white/10 rounded-md shadow-sm` que
                 estava aqui só a desfazia. --}}
            <select id="natureza" name="natureza" x-model="natureza"
                    class="mt-1 block w-full h-8 py-0 text-[12.5px]">
                @foreach (\App\Models\Sistema::NATUREZAS as $chave => $rotulo)
                    <option value="{{ $chave }}" @selected($naturezaAtual === $chave)>{{ $rotulo }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-ink-mute">
                Produto é o que a Alfa vende — entra no faturamento. Interno é o que a Alfa usa e existe
                para as tarefas apontarem.
            </p>
        @else
            {{-- Travado, e dizendo por quê no mesmo lugar. Um select
                 desabilitado sem explicação vira uma pergunta que a tela não
                 responde: quem lê tenta de novo em vez de procurar a causa. --}}
            {{-- Mesma altura do select que ele substitui: trocar de sistema
                 travado para destravado não pode mexer no alinhamento da
                 fileira. --}}
            <p class="mt-1 h-8 flex items-center px-3 rounded-control bg-input text-[12.5px] text-ink-dim">
                {{ \App\Models\Sistema::NATUREZAS[$sistema->natureza] }}
            </p>
            <p class="mt-1 text-xs text-ink-mute">
                Já tem cliente ou tier de atacado: mudar agora o tiraria do faturamento sem aviso.
            </p>
        @endif

        <x-input-error :messages="$errors->get('natureza')" class="mt-2" />
    </div>

    @unless ($edicao)
        <div class="sm:col-span-2">
            <x-input-label for="slug" value="Identificador (slug)" />
            <x-text-input id="slug" name="slug" type="text" class="mt-1 block w-full"
                          value="{{ old('slug') }}" placeholder="em branco, sai do nome" />
            {{-- Só no cadastro: o slug amarra a marca na tela
                 (`x-marca-sistema`) e o `sincronizar:sistemas` na linha certa.
                 Trocá-lo depois desfaz as duas amarras em silêncio. --}}
            <p class="mt-1 text-xs text-ink-mute">
                É por ele que a marca do produto e a sincronização acham o sistema. Não muda depois.
            </p>
            <x-input-error :messages="$errors->get('slug')" class="mt-2" />
        </div>
    @endunless

    {{-- Bloco comercial: só existe para quem se vende. --}}
    <div x-show="natureza === 'produto'" @if ($naturezaAtual !== 'produto') style="display: none" @endif>
        <x-input-label for="categoria" value="Categoria" />
        <select id="categoria" name="categoria" class="mt-1 block w-full h-8 py-0 text-[12.5px]">
            <option value="saas" @selected(old('categoria', $sistema->categoria ?? 'saas') === 'saas')>SaaS</option>
            <option value="crm" @selected(old('categoria', $sistema->categoria ?? null) === 'crm')>CRM</option>
        </select>
        <x-input-error :messages="$errors->get('categoria')" class="mt-2" />
    </div>

    <div x-show="natureza === 'produto'" @if ($naturezaAtual !== 'produto') style="display: none" @endif>
        <x-input-label for="unidade_cobranca" value="Unidade de cobrança" />
        <x-text-input id="unidade_cobranca" name="unidade_cobranca" type="text" class="mt-1 block w-full"
                      value="{{ old('unidade_cobranca', $sistema->unidade_cobranca ?? '') }}"
                      placeholder="academia ativa" />
        <x-input-error :messages="$errors->get('unidade_cobranca')" class="mt-2" />
    </div>

    {{-- Versão e responsável valem para os dois: é o que a lista de internos
         mostra ao lado do nome, e até aqui só dava para preenchê-los no banco. --}}
    <div>
        <x-input-label for="versao" value="Versão" />
        <x-text-input id="versao" name="versao" type="text" class="mt-1 block w-full"
                      value="{{ old('versao', $sistema->versao ?? '') }}" placeholder="v3.4.1" />
        <x-input-error :messages="$errors->get('versao')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="responsavel" value="Responsável" />
        <x-text-input id="responsavel" name="responsavel" type="text" class="mt-1 block w-full"
                      value="{{ old('responsavel', $sistema->responsavel ?? '') }}" />
        <x-input-error :messages="$errors->get('responsavel')" class="mt-2" />
    </div>

    <div>
        {{-- Em branco, vale o dia em que a linha foi criada — que na base
             importada é o dia da migração, igual para todo o catálogo. --}}
        <x-input-label for="data_cadastro" value="No catálogo desde" />
        <x-text-input id="data_cadastro" name="data_cadastro" type="date" class="mt-1 block w-full"
                      value="{{ old('data_cadastro', $sistema?->data_cadastro?->toDateString()) }}" />
        <x-input-error :messages="$errors->get('data_cadastro')" class="mt-2" />
    </div>

    <div class="flex items-center mt-6">
        <label class="inline-flex items-center">
            <input type="checkbox" name="ativo" value="1" class="rounded border-white/20 text-brand shadow-sm"
                   {{ old('ativo', $sistema->ativo ?? true) ? 'checked' : '' }}>
            {{-- O que "ativo" significa muda com a natureza: para o produto é
                 estar no motor de faturamento, para o interno é continuar
                 aparecendo na hora de abrir tarefa. Uma frase só diria a
                 verdade para metade da tela. --}}
            <span class="ms-2 text-sm text-ink-dim"
                  x-text="natureza === 'produto'
                      ? 'Sistema ativo (participa do motor de faturamento)'
                      : 'Sistema ativo (aparece na hora de abrir tarefa)'">
                {{ $naturezaAtual === 'produto'
                    ? 'Sistema ativo (participa do motor de faturamento)'
                    : 'Sistema ativo (aparece na hora de abrir tarefa)' }}
            </span>
        </label>
    </div>

    {{-- A marca. Vale para produto e para interno: é ela que identifica o
         sistema no rodapé do card de tarefa, e sistema interno tem tarefa como
         qualquer outro — era justamente ele que nascia sem ícone nenhum. --}}
    <div class="sm:col-span-2">
        <x-input-label for="marca" value="Marca do sistema" />

        <div class="mt-1 flex items-center gap-3">
            <span class="h-[38px] w-[38px] shrink-0 rounded-tile bg-chip flex items-center justify-center overflow-hidden">
                <x-marca-sistema :sistema="$sistema ?? new \App\Models\Sistema(['nome' => 'Novo'])"
                                 tamanho="h-[26px] w-[26px]" />
            </span>

            <input id="marca" name="marca" type="file" accept="image/png,image/webp,image/jpeg"
                   class="flex-1 min-w-0 text-[12.5px] text-ink-dim file:mr-3 file:h-8 file:px-3 file:rounded-control
                          file:border file:border-btn-line file:bg-transparent file:text-ink-dim
                          file:text-[12.5px] file:font-semibold hover:file:text-brand">
        </div>

        {{-- SVG fica de fora e o texto diz isso: sem a frase, quem tem o logo
             em SVG — que é o formato em que logo costuma vir — conclui que o
             campo está quebrado. --}}
        <p class="mt-1 text-xs text-ink-mute">
            PNG, WEBP ou JPG, até 512 KB. Aparece no card de tarefa e nas listas.
            SVG não entra por aqui: ele aceita script dentro e seria servido do mesmo
            endereço do painel — os SVG que existem hoje vieram pelo repositório.
        </p>

        @if ($edicao)
            <label class="mt-2 inline-flex items-center">
                <input type="checkbox" name="remover_marca" value="1"
                       class="rounded border-white/20 text-brand shadow-sm">
                <span class="ms-2 text-sm text-ink-dim">Remover a marca atual</span>
            </label>
        @endif

        <x-input-error :messages="$errors->get('marca')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="base_url" value="Base URL da API SaaS" />
        <x-text-input id="base_url" name="base_url" type="text" class="mt-1 block w-full"
                      value="{{ old('base_url', $sistema->base_url ?? '') }}"
                      placeholder="https://api.produto.com.br" />
        <x-input-error :messages="$errors->get('base_url')" class="mt-2" />
    </div>

    <div>
        <x-input-label for="token" value="Token de integração" />
        <x-text-input id="token" name="token" type="password" class="mt-1 block w-full"
                      placeholder="{{ ($sistema->token ?? null) ? '•••••••• (preencher para trocar)' : 'não configurado' }}" />
        <x-input-error :messages="$errors->get('token')" class="mt-2" />
    </div>
</div>
