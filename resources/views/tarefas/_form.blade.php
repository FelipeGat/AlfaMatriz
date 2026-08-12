@php
    /**
     * Formulário de tarefa, compartilhado entre o modal de criação e o de
     * edição de cada card (T-063). `$tarefa` nulo é criação; com valor, é
     * edição — o `$sufixo` evita ids duplicados quando o modal de edição se
     * repete uma vez por card no quadro.
     */
    $sufixo = $tarefa?->id ?? 'nova';

    // Prioridade e responsável são decisões de triagem. Para quem não a tem,
    // eles não aparecem desabilitados — somem. Campo travado à vista é um
    // convite recusado toda vez que se olha para ele, e a tela passaria a
    // conversar sobre uma permissão em vez de sobre a tarefa.
    $podeTriar = auth()->user()?->podeTriarTarefas() ?? false;
@endphp

{{--
    O botão se tranca no primeiro envio: dois cliques rápidos mandavam dois
    envios, e o segundo publicava o comentário de novo (a tarefa em si só era
    regravada igual, mas comentário é linha nova a cada vez). O `submit` já
    disparou quando o `disabled` entra, então o envio em curso não é cancelado
    — o que morre é o SEGUNDO clique.
--}}
<form method="POST"
      action="{{ $tarefa ? route('tarefas.update', $tarefa) : route('tarefas.store') }}"
      class="p-6 space-y-4"
      x-data="{ enviando: false }" @submit="enviando = true">
    @csrf
    @if ($tarefa)
        @method('PUT')
    @endif

    <h3 class="font-display font-semibold text-ink text-lg">
        {{ $tarefa ? 'Editar tarefa' : 'Nova tarefa' }}
    </h3>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2">
            <x-input-label for="titulo-{{ $sufixo }}" value="Título" />
            <x-text-input id="titulo-{{ $sufixo }}" name="titulo" type="text" class="mt-1 block w-full"
                          value="{{ old('titulo', $tarefa->titulo ?? '') }}" required />
        </div>

        {{--
            O tipo vem antes de tudo porque é ele que decide o resto: a tarefa
            de desenvolvimento passa por Em testes e só fecha com teste
            aprovado; a operacional fecha direto de Em andamento. É a única
            escolha do formulário que muda o caminho do card, então ela é dita
            embaixo do campo — o select sozinho não tem como explicar isso.
        --}}
        <div class="sm:col-span-2">
            <x-input-label for="tipo-{{ $sufixo }}" value="Tipo" />
            <select id="tipo-{{ $sufixo }}" name="tipo" class="mt-1 block w-full">
                @foreach (\App\Models\Tarefa::TIPOS as $chave => $label)
                    <option value="{{ $chave }}"
                            @selected(old('tipo', $tarefa->tipo ?? 'desenvolvimento') === $chave)>
                        {{ $label }}
                    </option>
                @endforeach
            </select>
            <p class="mt-1 text-[11.5px] text-ink-faint">
                Desenvolvimento passa por testes e só fecha com relatório aprovado.
                Operacional — contatar fornecedor, renovar certificado — fecha direto.
            </p>
        </div>

        <div>
            <x-input-label for="sistema_id-{{ $sufixo }}" value="Sistema" />
            <select id="sistema_id-{{ $sufixo }}" name="sistema_id" class="mt-1 block w-full">
                <option value="">—</option>
                @foreach ($sistemas as $sistema)
                    <option value="{{ $sistema->id }}"
                            @selected(old('sistema_id', $tarefa->sistema_id ?? '') == $sistema->id)>
                        {{ $sistema->nome }}
                    </option>
                @endforeach
            </select>
        </div>

        @if ($podeTriar)
            <div>
                <x-input-label for="responsavel_id-{{ $sufixo }}" value="Responsável" />
                <select id="responsavel_id-{{ $sufixo }}" name="responsavel_id" class="mt-1 block w-full">
                    <option value="">—</option>
                    @foreach ($usuarios as $usuario)
                        <option value="{{ $usuario->id }}"
                                @selected(old('responsavel_id', $tarefa->responsavel_id ?? '') == $usuario->id)>
                            {{ $usuario->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <x-input-label for="prioridade-{{ $sufixo }}" value="Prioridade" />
                <select id="prioridade-{{ $sufixo }}" name="prioridade" class="mt-1 block w-full">
                    @foreach (\App\Models\Tarefa::PRIORIDADES as $chave => $label)
                        <option value="{{ $chave }}"
                                @selected(old('prioridade', $tarefa->prioridade ?? 'media') === $chave)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif
    </div>

    {{--
        A ausência dita UMA vez, e no lugar onde os campos estariam.

        Sem esta linha, o formulário curto se lê como versão incompleta da tela
        — e a pessoa procura o campo que "sumiu", ou pior, acha que a tarefa
        dela nasce sem prioridade porque o sistema esqueceu. Dizer quem decide
        também responde a quem pedir.
    --}}
    @unless ($podeTriar)
        <p class="text-[11.5px] leading-snug text-ink-mute">
            @if ($tarefa)
                A prioridade e o responsável desta tarefa são definidos na triagem.
            @else
                A tarefa entra como <strong class="text-ink">A definir</strong> e sem responsável:
                priorizar e direcionar são decisões da triagem.
            @endif
        </p>
    @endunless

    {{--
        A conversa entra DENTRO do formulário, e o campo de comentário é mais
        um campo dele: um botão só, no fim, publica o comentário junto com o
        cadastro. Só na edição — tarefa que ainda não existe não tem conversa,
        e o modal de criação não teria onde pendurar o comentário.
    --}}
    @if ($tarefa)
        @include('tarefas._checklist', ['tarefa' => $tarefa])
        @include('tarefas._comentarios', ['tarefa' => $tarefa])
    @endif

    <div class="flex justify-end gap-3">
        <x-secondary-button x-on:click="$dispatch('close')">Cancelar</x-secondary-button>
        {{-- O rótulo literal é o que vale sem JS; com Alpine no ar, ele vira
             o aviso de que o envio está em curso. --}}
        <x-primary-button x-bind:disabled="enviando"
                          x-text="enviando ? 'Salvando…' : 'Salvar'">Salvar</x-primary-button>
    </div>
</form>
