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

    {{--
        Excluir fica na MESMA linha do Salvar, na ponta oposta.

        Ele já morou embaixo de tudo, obedecendo ao "rodapé do detalhe" ao pé da
        letra — e num modal que rola, depois do checklist e da conversa inteira,
        rodapé virou fora da tela: o botão nascia a 804px numa janela de 800.
        Ação destrutiva que ninguém acha é tão quebrada quanto uma fácil demais
        de apertar.

        A separação que o desenho pedia continua, só que na horizontal: quem vem
        salvar encontra o excluir sem procurar, e a largura inteira do modal
        separa um do outro. Dois passos porque é a única ação do quadro sem
        desfazer, e a confirmação abre ACIMA da linha, onde cabe a frase que
        diz a diferença para cancelar — sem ela, "excluir" e "cancelar" são
        sinônimos na cabeça de quem lê, e um deles apaga o histórico que o
        outro existe para guardar.

        O `x-data` é local ao bloco, então a confirmação se desarma ao fechar o
        modal: tarefa reaberta nunca aparece com o gatilho engatilhado.
    --}}
    <div @if ($tarefa && $podeTriar) x-data="{ confirmando: false }" @endif>
        @if ($tarefa && $podeTriar)
            <div x-show="confirmando" x-cloak
                 class="mb-3 rounded-control border p-3"
                 style="border-color: rgb(var(--crit) / 0.4); background: rgb(var(--crit) / var(--tint-alpha))">
                <p class="text-[11.5px] leading-snug text-ink-mute">
                    Excluir <strong class="text-ink">apaga o registro</strong>: a conversa, o checklist e o
                    tempo por etapa somem junto, e não há como desfazer. Para encerrar a tarefa mantendo o
                    histórico, o caminho é <strong class="text-ink">cancelar</strong>, pelo menu de mover.
                </p>
                <div class="mt-2 flex items-center gap-2">
                    <button type="submit" form="excluir-tarefa-{{ $tarefa->id }}"
                            class="h-8 px-3 rounded-control font-semibold text-[12px] text-on-brand transition"
                            style="background: rgb(var(--crit))">
                        Excluir para sempre
                    </button>
                    <button type="button" @click="confirmando = false"
                            class="h-8 px-3 rounded-control border border-btn-line text-[12px] text-ink-dim
                                   hover:text-ink transition">
                        Manter
                    </button>
                </div>
            </div>
        @endif

        <div class="flex items-center gap-3">
            @if ($tarefa && $podeTriar)
                <button type="button" @click="confirmando = true" x-show="! confirmando"
                        class="text-[12.5px] transition hover:underline"
                        style="color: rgb(var(--crit))">
                    Excluir tarefa
                </button>
            @endif

            <div class="ml-auto flex gap-3">
                <x-secondary-button x-on:click="$dispatch('close')">Cancelar</x-secondary-button>
                {{-- O rótulo literal é o que vale sem JS; com Alpine no ar, ele vira
                     o aviso de que o envio está em curso. --}}
                <x-primary-button x-bind:disabled="enviando"
                                  x-text="enviando ? 'Salvando…' : 'Salvar'">Salvar</x-primary-button>
            </div>
        </div>
    </div>
</form>
