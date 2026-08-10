{{-- Cadastro de cliente: usado na tela de Clientes e na aba de clientes da
    tela de Revendas. É o ÚNICO lugar onde se cadastra cliente — havia uma
    página inteira duplicando este formulário, sem link nenhum apontando para
    ela. Espera: $revendas (só as ativas) e $sistemas. --}}

{{-- `:show` não é enfeite: sem ele, uma recusa do AlfaGym (e-mail de admin já
    usado, revenda não provisionada) devolve o usuário para a lista com o modal
    FECHADO — a tela parece simplesmente não ter feito nada, e a mensagem de
    erro morre na sessão. O sentinela `old('tipo_pessoa')` garante que só um
    erro DESTE formulário reabra o modal: as ações de licença da tabela também
    devolvem erros para a mesma página. --}}
<x-modal name="novo-cliente" maxWidth="2xl"
         :show="$errors->any() && old('tipo_pessoa') !== null">
    <form method="POST" action="{{ route('clientes.store') }}" class="p-5">
        <h2 class="font-display text-[15.5px] font-semibold text-ink mb-4">Novo cliente</h2>
        @include('clientes._form', ['emModal' => true, 'modo' => 'criar'])
    </form>
</x-modal>
