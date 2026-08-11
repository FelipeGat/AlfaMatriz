{{--
    Barra de busca e filtros das duas abas de Tarefas (quadro e histórico).

    Uma partial só porque o recorte é o mesmo nas duas telas: quem procurou
    algo no quadro e não achou repete a busca no histórico, e o formulário tem
    de aceitar as mesmas perguntas nos dois lugares. O `desfecho` é a única
    diferença — no quadro não existe status terminal para escolher.

    Tudo na query string, como em Revendas e Clientes: o recorte fica
    compartilhável por link e sobrevive ao F5.

    Espera: $filtros, $sistemas, $usuarios. `$comDesfecho` só o histórico passa.
--}}
@props(['comDesfecho' => false])

@php
    // Se nada foi pedido, não há o que limpar — e um botão "Limpar" aceso
    // sobre uma tela sem filtro só ensina que ele não faz nada.
    $temFiltro = collect($filtros)->contains(fn ($valor) => $valor !== '');
@endphp

<form method="GET" class="shrink-0 flex flex-wrap items-center gap-2">
    <label class="flex items-center gap-2 h-[34px] flex-1 min-w-[220px] max-w-[380px] px-3
                  rounded-control bg-input border border-line text-ink-faint focus-within:border-brand transition">
        <span class="h-4 w-4 shrink-0"><x-nav-icon name="search" /></span>
        <input type="search" name="busca" value="{{ $filtros['busca'] }}"
               placeholder="Buscar título, resumo ou detalhes…"
               class="flex-1 min-w-0 bg-transparent border-0 p-0 text-[13px] text-ink placeholder-ink-faint focus:ring-0">
    </label>

    <select name="sistema" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
        <option value="">Todos os sistemas</option>
        {{-- "Nenhum" e não "Sem sistema": essa frase é o texto que o CARD usa
             para anunciar a falta (AC-084), e repeti-la aqui a colocaria na
             página mesmo quando nenhum card tem lacuna nenhuma. --}}
        <option value="sem" @selected($filtros['sistema'] === 'sem')>Nenhum sistema</option>
        @foreach ($sistemas as $sistema)
            <option value="{{ $sistema->id }}" @selected($filtros['sistema'] === (string) $sistema->id)>{{ $sistema->nome }}</option>
        @endforeach
    </select>

    <select name="responsavel" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
        <option value="">Todos os responsáveis</option>
        {{-- A fila de triagem em uma opção: é a pergunta que a coluna Aberta
             faz. "Nenhum" pelo mesmo motivo do select acima (AC-130). --}}
        <option value="sem" @selected($filtros['responsavel'] === 'sem')>Nenhum responsável</option>
        @foreach ($usuarios as $usuario)
            <option value="{{ $usuario->id }}" @selected($filtros['responsavel'] === (string) $usuario->id)>{{ $usuario->name }}</option>
        @endforeach
    </select>

    {{-- Com desenvolvimento e operacional no mesmo quadro, isolar um dos dois
         passou a ser pergunta de rotina: o quadro do ciclo continua existindo,
         agora como um recorte em vez de uma tela. --}}
    <select name="tipo" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
        <option value="">Todos os tipos</option>
        @foreach (\App\Models\Tarefa::TIPOS as $chave => $rotulo)
            <option value="{{ $chave }}" @selected($filtros['tipo'] === $chave)>{{ $rotulo }}</option>
        @endforeach
    </select>

    <select name="prioridade" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
        <option value="">Todas as prioridades</option>
        @foreach (\App\Models\Tarefa::PRIORIDADES as $chave => $rotulo)
            <option value="{{ $chave }}" @selected($filtros['prioridade'] === $chave)>{{ $rotulo }}</option>
        @endforeach
    </select>

    @if ($comDesfecho)
        <select name="desfecho" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
            <option value="">Todos os desfechos</option>
            @foreach (\App\Models\Tarefa::STATUS_TERMINAIS as $terminal)
                <option value="{{ $terminal }}" @selected($filtros['desfecho'] === $terminal)>
                    {{ \App\Models\Tarefa::STATUS[$terminal] ?? $terminal }}
                </option>
            @endforeach
        </select>
    @endif

    <button type="submit"
            class="h-[34px] px-3 rounded-control border border-btn-line text-ink-dim
                   text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
        Filtrar
    </button>

    @if ($temFiltro)
        {{-- Link e não botão: limpar é ir para a mesma tela sem query nenhuma. --}}
        <a href="{{ url()->current() }}"
           class="h-[34px] px-3 inline-flex items-center rounded-control
                  font-mono text-[10.5px] uppercase tracking-caps text-ink-faint hover:text-brand transition">
            Limpar
        </a>
    @endif
</form>
