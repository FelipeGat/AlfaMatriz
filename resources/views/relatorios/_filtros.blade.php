{{--
    A barra de filtros dos Relatórios — a mesma gramática da de Tarefas: tudo
    na query string (o recorte fica compartilhável por link e sobrevive ao F5),
    selects com "Todos" como vazio, "Limpar recorte" só quando há o que limpar,
    e o recorte ativo nomeado em pílulas, cada ✕ tirando só o seu filtro.

    Cada seção mostra SÓ os selects dos eixos que os painéis dela honram —
    um filtro de vendedor na seção de Sistema seria promessa vazia.

    Espera: $secao, $competencia, $filtros, $listas, $recortes.
--}}
<div class="flex flex-wrap items-center gap-2">
    <form method="GET" class="flex flex-wrap items-center gap-2">
        {{-- A seção e a competência viajam junto do filtro: sem elas o envio
             voltaria para a seção comercial do mês corrente. --}}
        <input type="hidden" name="secao" value="{{ $secao }}">
        <input type="hidden" name="competencia" value="{{ $competencia }}">

        @if ($secao === 'comercial')
            <select name="vendedor" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="">Todos os vendedores</option>
                @foreach ($listas['vendedores'] as $vendedor)
                    <option value="{{ $vendedor->id }}" @selected($filtros['vendedor'] === (string) $vendedor->id)>{{ $vendedor->name }}</option>
                @endforeach
            </select>

            <select name="origem" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="">Todas as origens</option>
                @foreach (\App\Models\Lead::ORIGENS as $origem)
                    <option value="{{ $origem }}" @selected($filtros['origem'] === $origem)>{{ $origem }}</option>
                @endforeach
            </select>

            <select name="sistema" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="">Todos os sistemas</option>
                @foreach ($listas['sistemas'] as $sistema)
                    <option value="{{ $sistema->id }}" @selected($filtros['sistema'] === (string) $sistema->id)>{{ $sistema->nome }}</option>
                @endforeach
            </select>
        @elseif ($secao === 'financeiro')
            <select name="tipo_receita" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="">Todos os tipos de receita</option>
                @foreach (['locacao_sistema' => 'Recorrente · revenda', 'locacao_cliente' => 'Recorrente · cliente', 'avulsa' => 'Avulsa', 'direta' => 'Direta'] as $chave => $rotulo)
                    <option value="{{ $chave }}" @selected($filtros['tipo_receita'] === $chave)>{{ $rotulo }}</option>
                @endforeach
            </select>

            <select name="centro_custo" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="">Todos os centros de custo</option>
                @foreach ($listas['centrosCusto'] as $centro)
                    <option value="{{ $centro->id }}" @selected($filtros['centro_custo'] === (string) $centro->id)>{{ $centro->nome }}</option>
                @endforeach
            </select>
        @elseif ($secao === 'desenvolvimento')
            <select name="sistema" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="">Todos os sistemas</option>
                @foreach ($listas['sistemas'] as $sistema)
                    <option value="{{ $sistema->id }}" @selected($filtros['sistema'] === (string) $sistema->id)>{{ $sistema->nome }}</option>
                @endforeach
            </select>

            <select name="responsavel" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="">Todos os responsáveis</option>
                @foreach ($listas['responsaveis'] as $responsavel)
                    <option value="{{ $responsavel->id }}" @selected($filtros['responsavel'] === (string) $responsavel->id)>{{ $responsavel->name }}</option>
                @endforeach
            </select>

            <select name="prioridade" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="">Todas as prioridades</option>
                @foreach (\App\Models\Tarefa::PRIORIDADES as $chave => $rotulo)
                    <option value="{{ $chave }}" @selected($filtros['prioridade'] === $chave)>{{ $rotulo }}</option>
                @endforeach
            </select>

            <select name="tipo" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="">Todos os tipos</option>
                @foreach (\App\Models\Tarefa::TIPOS as $chave => $rotulo)
                    <option value="{{ $chave }}" @selected($filtros['tipo'] === $chave)>{{ $rotulo }}</option>
                @endforeach
            </select>
        @else
            <select name="perfil" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="">Todos os perfis</option>
                @foreach ($listas['perfis'] as $perfil)
                    <option value="{{ $perfil->id }}" @selected($filtros['perfil'] === (string) $perfil->id)>{{ $perfil->nome }}</option>
                @endforeach
            </select>

            <select name="recurso" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="">Todos os recursos</option>
                @foreach ($listas['recursos'] as $recurso)
                    <option value="{{ $recurso }}" @selected($filtros['recurso'] === $recurso)>{{ $recurso }}</option>
                @endforeach
            </select>

            <select name="usuario" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
                <option value="">Todos os usuários</option>
                @foreach ($listas['atores'] as $ator)
                    <option value="{{ $ator->id }}" @selected($filtros['usuario'] === (string) $ator->id)>{{ $ator->name }}</option>
                @endforeach
            </select>
        @endif

        <button type="submit"
                class="h-[34px] px-3 rounded-control border border-btn-line text-ink-dim
                       text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
            Filtrar
        </button>

        @if ($recortes)
            {{-- Limpar é voltar à seção e à competência sem recorte nenhum —
                 não à tela zerada, que trocaria de seção junto. --}}
            <a href="{{ route('relatorios.index', array_filter(['secao' => $secao, 'competencia' => request()->query('competencia')])) }}"
               class="h-[34px] px-3 inline-flex items-center rounded-control
                      font-mono text-[10.5px] uppercase tracking-caps text-ink-faint hover:text-brand transition">
                Limpar recorte
            </a>
        @endif
    </form>

    {{-- Exportar e Imprimir abrem a PRÉVIA do documento, com o mesmo recorte
         da URL — é lá que se escolhe o formato, vendo exatamente o que vai
         sair. Nova guia para não perder o painel filtrado. Só para quem pode
         imprimir: link para 403 é pior que link ausente. --}}
    @if (auth()->user()->canPermissao('relatorios', 'imprimir'))
        <span class="ml-auto flex items-center gap-2">
            <a href="{{ route('relatorios.previa', array_merge(request()->query(), ['secao' => $secao])) }}" target="_blank"
               class="h-[34px] px-3 rounded-control border border-btn-line flex items-center gap-1.5
                      text-[12.5px] font-semibold text-ink-dim hover:text-brand hover:border-brand transition">
                <span class="h-3.5 w-3.5"><x-nav-icon name="download" :peso="1.8" /></span>
                Exportar
            </a>
            <a href="{{ route('relatorios.previa', array_merge(request()->query(), ['secao' => $secao, 'imprimir' => 1])) }}" target="_blank"
               class="h-[34px] px-3 rounded-control border border-btn-line flex items-center gap-1.5
                      text-[12.5px] font-semibold text-ink-dim hover:text-brand hover:border-brand transition">
                <span class="h-3.5 w-3.5"><x-nav-icon name="printer" :peso="1.8" /></span>
                Imprimir
            </a>
        </span>
    @endif
</div>

{{-- O recorte ativo, nomeado — as mesmas pílulas do quadro de Tarefas: o
     select guarda o valor mas não o anuncia, e cada ✕ tira só o seu. --}}
@if ($recortes)
    <div class="flex flex-wrap items-center gap-2">
        <span class="shrink-0 font-mono text-[10px] uppercase tracking-caps text-ink-faint">Recorte</span>
        @foreach ($recortes as $recorte)
            <a href="{{ request()->fullUrlWithQuery([$recorte['parametro'] => null]) }}"
               title="Tirar este filtro do recorte"
               class="shrink-0 h-[26px] px-2.5 rounded-badge border border-line flex items-center gap-1.5
                      text-[12px] text-ink-dim hover:text-ink transition">
                <span class="h-3 w-3"><x-nav-icon name="x-mark" :peso="1.8" /></span>
                {{ $recorte['rotulo'] }}
            </a>
        @endforeach
    </div>
@endif
