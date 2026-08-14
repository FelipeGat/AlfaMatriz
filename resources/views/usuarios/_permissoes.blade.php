{{-- A grade de cada perfil. Espera: $perfisComGrade, $permissoes.

    Uma tabela por perfil, empilhadas — mesmo arranjo dos cadastros auxiliares.
    A alternativa seria uma matriz única com um bloco de cinco colunas por
    perfil: vinte e cinco colunas de caixa, larga demais para caber e impossível
    de ler sem contar cabeçalho. --}}

@php
    // `Editar` vem logo depois de `Incluir` porque é dele que ela se separou em
    // 15/08/2026: até então, cadastrar era o mesmo que reescrever tudo o que já
    // estava cadastrado. Lado a lado, a diferença entre as duas se lê sem
    // precisar de legenda.
    $ações = [
        'ler' => 'Ler',
        'incluir' => 'Incluir',
        'editar' => 'Editar',
        'imprimir' => 'Imprimir',
        'excluir' => 'Excluir',
    ];
@endphp

{{-- ARMADILHA: nada de `bg-input` na caixa. O `:checked` do plugin de forms
     pinta o fundo com `currentColor`, e uma classe de fundo o vence — o tique
     é branco, então no tema CLARO a caixa marcada fica branca sobre branco e a
     grade inteira aparece desmarcada. No escuro o acidente passa despercebido,
     porque o branco do tique sobrevive no fundo escuro. --}}

<div class="space-y-4">
    @foreach ($perfisComGrade as $perfil)
        @php
            $imutável = $perfil->slug === 'admin';
            $grade = $perfil->permissoes->keyBy('recurso');
        @endphp

        <form method="POST" action="{{ $imutável ? '#' : route('perfis.permissoes', $perfil) }}">
            @unless ($imutável)
                @csrf
                @method('PUT')
            @endunless

            <x-tabela min="720px" class="tabela-zebrada" :titulo="$perfil->nome">
                <x-slot name="cabecalho">
                    @if ($imutável)
                        {{-- O admin APARECE, em leitura. Sumido, quem procura
                             conclui que o perfil deixou de existir ou que a tela
                             quebrou — e a explicação de por que ele não se edita
                             não teria onde ser dada. --}}
                        <span class="text-[12px] text-ink-mute">
                            Acesso total por definição — é o perfil que reabre esta tela.
                        </span>
                    @else
                        <button type="submit"
                                class="h-[30px] px-3 rounded-control bg-brand text-on-brand text-[12px] font-semibold hover:bg-brand-bright transition">
                            Salvar
                        </button>
                    @endif
                </x-slot>

                <thead>
                    <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                        <th class="px-4 py-2.5 font-semibold">Recurso</th>
                        @foreach ($ações as $rótulo)
                            <th class="px-4 py-2.5 font-semibold text-center w-[110px]">{{ $rótulo }}</th>
                        @endforeach
                    </tr>
                </thead>

                <tbody>
                    @foreach ($permissoes as $permissao)
                        @php $pivô = $grade->get($permissao->recurso)?->pivot; @endphp

                        <tr class="border-b border-rule hover:bg-chip transition">
                            <td class="px-4 py-2.5">
                                <p class="text-[13px] text-ink">{{ $permissao->descricao }}</p>
                                <p class="font-mono text-[10.5px] text-ink-faint">{{ $permissao->recurso }}</p>
                            </td>

                            @foreach ($ações as $acao => $rótulo)
                                <td class="px-4 py-2.5 text-center">
                                    <input type="checkbox"
                                           name="grade[{{ $permissao->recurso }}][{{ $acao }}]" value="1"
                                           class="h-4 w-4 rounded border-btn-line text-brand focus:ring-brand disabled:opacity-60"
                                           @checked($imutável || ($pivô?->{$acao} ?? false))
                                           @disabled($imutável)
                                           aria-label="{{ $rótulo }} — {{ $permissao->descricao }}">
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </x-tabela>
        </form>
    @endforeach
</div>
