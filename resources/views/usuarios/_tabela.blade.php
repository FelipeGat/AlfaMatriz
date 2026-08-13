{{-- Lista de contas do painel. Espera: $usuarios, $filtros, $perfis. --}}

<form method="GET" class="flex flex-wrap items-center gap-2">
    <input type="hidden" name="aba" value="usuarios">

    <label class="flex items-center gap-2 h-[34px] flex-1 min-w-[220px] max-w-[360px] px-3
                  rounded-control bg-input border border-line text-ink-faint focus-within:border-brand transition">
        <span class="h-4 w-4 shrink-0"><x-nav-icon name="search" /></span>
        <input type="search" name="busca" value="{{ $filtros['busca'] }}"
               placeholder="Buscar nome ou e-mail…"
               class="flex-1 min-w-0 bg-transparent border-0 p-0 text-[13px] text-ink placeholder-ink-faint focus:ring-0">
    </label>

    <select name="perfil" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
        <option value="">Todos os perfis</option>
        @foreach ($perfis as $perfil)
            <option value="{{ $perfil->slug }}" @selected($filtros['perfil'] === $perfil->slug)>{{ $perfil->nome }}</option>
        @endforeach
    </select>

    <select name="situacao" class="h-[34px] py-0 text-[13px] rounded-control bg-input border-line text-ink-dim">
        <option value="">Todas as situações</option>
        <option value="ativo" @selected($filtros['situacao'] === 'ativo')>Ativas</option>
        <option value="desativado" @selected($filtros['situacao'] === 'desativado')>Desativadas</option>
        <option value="pendente" @selected($filtros['situacao'] === 'pendente')>Aguardando 1º acesso</option>
    </select>

    <button type="submit"
            class="h-[34px] px-3 rounded-control border border-btn-line text-ink-dim
                   text-[12.5px] font-semibold hover:text-brand hover:border-brand transition">
        Filtrar
    </button>
</form>

<x-tabela min="940px" class="tabela-zebrada">
    <thead>
        <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
            <th class="px-4 py-2.5 font-semibold">Pessoa</th>
            <th class="px-4 py-2.5 font-semibold">Perfis</th>
            <th class="px-4 py-2.5 font-semibold">Alcance</th>
            <th class="px-4 py-2.5 font-semibold">Situação</th>
            <th class="px-4 py-2.5 font-semibold text-right">Ações</th>
        </tr>
    </thead>

    <tbody>
        @forelse ($usuarios as $usuario)
            @php $euMesmo = $usuario->id === auth()->id(); @endphp

            <tr class="border-b border-rule hover:bg-chip transition {{ $usuario->ativo ? '' : 'opacity-[0.62]' }}">
                <td class="px-4 py-3">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <span class="h-[30px] w-[30px] shrink-0 rounded-full bg-brand/20 text-brand-text flex items-center justify-center font-mono text-[11.5px] font-semibold">
                            {{ Str::of($usuario->name)->substr(0, 1)->upper() }}
                        </span>
                        <div class="min-w-0">
                            <p class="text-[13.5px] font-medium text-ink truncate">
                                {{ $usuario->name }}
                                @if ($euMesmo)
                                    <span class="font-mono text-[10px] uppercase tracking-caps text-ink-faint">· você</span>
                                @endif
                            </p>
                            <p class="font-mono text-[11px] text-ink-faint truncate">{{ $usuario->email }}</p>
                        </div>
                    </div>
                </td>

                <td class="px-4 py-3">
                    <div class="flex flex-wrap gap-1">
                        @forelse ($usuario->perfis as $perfil)
                            <x-badge :tom="$perfil->slug === 'admin' ? 'marca' : 'neutro'">{{ $perfil->nome }}</x-badge>
                        @empty
                            {{-- Conta sem perfil entra e não abre tela nenhuma: o
                                 formulário exige um, mas as contas anteriores a
                                 esta tela podem ter chegado assim. --}}
                            <x-badge tom="critico">sem perfil</x-badge>
                        @endforelse
                    </div>
                </td>

                <td class="px-4 py-3 text-[13px] text-ink-dim">
                    {{ $usuario->revenda?->nome ?? 'Matriz' }}
                </td>

                <td class="px-4 py-3">
                    @if (! $usuario->ativo)
                        <x-badge tom="critico" ponto>Desativada</x-badge>
                    @elseif ($usuario->primeiro_acesso)
                        <x-badge tom="ambar" ponto>Aguardando 1º acesso</x-badge>
                    @else
                        <x-badge tom="bom" ponto>Ativa</x-badge>
                    @endif
                </td>

                <td class="px-4 py-3">
                    <div class="flex items-center justify-end gap-1">
                        <x-acao-tabela icone="pencil" titulo="Editar conta"
                                       x-data @click="$dispatch('open-modal', 'editar-usuario-{{ $usuario->id }}')" />

                        <x-confirmar :action="route('usuarios.senha', $usuario)"
                                     icone="cadeado-aberto"
                                     titulo="Gerar nova senha para {{ $usuario->name }}?"
                                     mensagem="A senha atual para de valer na hora. A nova aparece uma única vez, para você repassar."
                                     confirmar="Gerar senha" />

                        {{-- As duas ações sobre a própria conta não aparecem em
                             vez de aparecerem e falharem: o controller recusa de
                             qualquer forma, e um botão que só sabe dizer não é
                             pior que um botão ausente. --}}
                        @unless ($euMesmo)
                            <x-confirmar :action="route('usuarios.ativo', $usuario)"
                                         :icone="$usuario->ativo ? 'pause' : 'play'"
                                         :titulo="$usuario->ativo
                                            ? 'Desativar '.$usuario->name.'?'
                                            : 'Reativar '.$usuario->name.'?'"
                                         :mensagem="$usuario->ativo
                                            ? 'O acesso cai na hora, inclusive numa sessão já aberta. A conta e o histórico ficam.'
                                            : 'A conta volta a entrar no painel com a mesma senha de antes.'"
                                         :confirmar="$usuario->ativo ? 'Desativar' : 'Reativar'"
                                         :destrutivo="$usuario->ativo" />

                            <x-confirmar :action="route('usuarios.destroy', $usuario)"
                                         method="DELETE"
                                         icone="trash"
                                         titulo="Excluir a conta de {{ $usuario->name }}?"
                                         mensagem="O e-mail continua reservado a ela. Para só tirar o acesso, prefira desativar."
                                         confirmar="Excluir"
                                         destrutivo />
                        @endunless
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="5" class="px-4 py-8 text-center text-[13px] text-ink-mute">
                    Nenhuma conta encontrada com esse recorte.
                </td>
            </tr>
        @endforelse
    </tbody>

    <x-slot name="rodape">
        <span>{{ $usuarios->count() }} de {{ $usuarios->total() }} contas</span>
        <span>· página {{ $usuarios->currentPage() }} de {{ $usuarios->lastPage() }}</span>
    </x-slot>
</x-tabela>

@if ($usuarios->hasPages())
    <div>{{ $usuarios->links() }}</div>
@endif
