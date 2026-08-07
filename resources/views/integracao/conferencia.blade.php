<x-app-layout>
    <x-slot name="titulo">Conferência</x-slot>
    <x-slot name="contexto">{{ $total }} {{ $total === 1 ? 'pendência' : 'pendências' }}</x-slot>
    <x-slot name="caminho">
        <x-migalhas :caminho="[['rotulo' => 'Sistemas', 'rota' => route('integracao.index')]]"
                    atual="Conferência" />
    </x-slot>

    {{--
        Esta tela é o que torna o corte seguro. Cada pendência aparece pelo
        MOTIVO, e não numa lista única de "não vinculados", porque cada motivo
        pede uma ação diferente: um precisa de escolha, outro de cadastro novo,
        outro de dado que falta na origem. Uma lista só obrigaria a descobrir
        isso registro a registro.
    --}}
    <div class="space-y-4">
        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif
        @if (session('erro'))
            <x-aviso tom="critico" :segundos="0">{{ session('erro') }}</x-aviso>
        @endif

        @if ($sistemas->isEmpty())
            <x-painel>
                <p class="text-[13px] text-ink-dim">Nenhum sistema para conferir.</p>
            </x-painel>
        @else
            {{-- Escolha do sistema: a conferência e o corte são um por vez, de
                 propósito — virar todos juntos multiplicaria o estrago de um
                 engano por cinco. --}}
            <div class="flex flex-wrap items-center gap-2">
                @foreach ($sistemas as $opcao)
                    <a href="{{ route('integracao.conferencia', ['sistema' => $opcao->id]) }}"
                       class="h-8 px-3 inline-flex items-center gap-2 rounded-control border text-[12px] transition
                              {{ $sistema && $opcao->id === $sistema->id ? 'border-brand-line text-brand-text bg-brand-tint' : 'border-line bg-input text-ink-dim hover:text-ink' }}">
                        <span class="h-4 w-4"><x-marca-sistema :sistema="$opcao" /></span>
                        {{ $opcao->nome }}
                    </a>
                @endforeach
            </div>

            @php
                $rotulos = [
                    'sem_par' => ['Sem par na matriz', 'Existe no sistema e não corresponde a nenhum cliente daqui. Enquanto ficar assim, ninguém o cobra.'],
                    'varios_candidatos' => ['Mais de um candidato', 'O documento corresponde a mais de um cliente da matriz. Escolher sozinho seria escolher por ordem de cadastro.'],
                    'sem_documento' => ['Sem documento na origem', 'O sistema não informou CPF nem CNPJ. Não há como casar automaticamente — precisa de decisão humana.'],
                ];

                $motivoDoCorteTexto = match ($motivoDoCorte) {
                    'ja_aplicado' => 'A matriz já é dona do cadastro deste sistema.',
                    'sem_importacao' => 'O cadastro do sistema ainda não foi importado.',
                    'com_pendencias' => $total.' '.($total === 1 ? 'pendência precisa' : 'pendências precisam').' ser resolvida'.($total === 1 ? '' : 's').' antes do corte.',
                    default => null,
                };
            @endphp

            {{-- O corte, e por que ele ainda não pode acontecer. --}}
            <x-painel titulo="O corte" :sub="$sistema?->nome">
                <div class="flex flex-wrap items-center gap-4">
                    <div class="flex-1 min-w-[260px]">
                        <p class="text-[13px] text-ink-dim">
                            A partir do corte, o cadastro deste sistema passa a nascer no AlfaMatriz.
                            É praticamente irreversível: voltar atrás significa reconciliar duas bases
                            que divergiram.
                        </p>
                        @if ($motivoDoCorteTexto)
                            <p class="mt-2 text-[12px] text-warn">{{ $motivoDoCorteTexto }}</p>
                        @endif
                    </div>

                    @if ($motivoDoCorte === null && $sistema)
                        <x-confirmar
                            :action="route('integracao.conferencia.corte', $sistema)"
                            titulo="Aplicar o corte"
                            mensagem="A partir de agora o cadastro do {{ $sistema->nome }} passa a nascer no AlfaMatriz. Isso é praticamente irreversível. Confirma?"
                            rotulo="Aplicar o corte"
                            destrutivo />
                    @else
                        <button type="button" disabled
                                class="h-8 px-3 rounded-control border border-line bg-input text-[12px] text-ink-faint cursor-not-allowed">
                            Aplicar o corte
                        </button>
                    @endif
                </div>
            </x-painel>

            @foreach ($baldes as $motivo => $linhas)
                @php([$titulo, $explicacao] = $rotulos[$motivo])

                <x-painel :titulo="$titulo" :sub="$linhas->count().' '.($linhas->count() === 1 ? 'registro' : 'registros')">
                    <p class="text-[12px] text-ink-dim">{{ $explicacao }}</p>

                    @if ($linhas->isEmpty())
                        <p class="mt-3 font-mono text-[10.5px] uppercase tracking-caps text-good">nada pendente aqui</p>
                    @else
                        <div class="mt-3 space-y-2">
                            @foreach ($linhas as $linha)
                                @php($registro = $linha['registro'])
                                <div class="flex flex-wrap items-center gap-3 rounded-tile border border-line bg-input px-3 py-2">
                                    <div class="min-w-[200px] flex-1">
                                        <p class="text-[13px] text-ink truncate">{{ $registro->nome }}</p>
                                        <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                                            {{ $registro->cpf_cnpj ? \App\Services\Integracao\Documento::formatar($registro->cpf_cnpj) : 'sem documento' }}
                                            · {{ $registro->cidade ?: 'sem cidade' }}
                                        </p>
                                    </div>

                                    @if ($motivo === 'varios_candidatos')
                                        <form method="POST" action="{{ route('integracao.conferencia.vincular', $registro) }}"
                                              class="flex items-center gap-2">
                                            @csrf
                                            <select name="cliente_id"
                                                    class="h-8 rounded-control border-line bg-input text-[12px] text-ink">
                                                @foreach ($linha['candidatos'] as $candidato)
                                                    <option value="{{ $candidato->id }}">{{ $candidato->nome }}</option>
                                                @endforeach
                                            </select>
                                            <button type="submit"
                                                    class="h-8 px-3 rounded-control border border-line bg-input text-[12px] text-ink-dim hover:text-ink transition">
                                                Vincular
                                            </button>
                                        </form>
                                    @else
                                        <a href="{{ route('clientes.create') }}"
                                           class="h-8 px-3 inline-flex items-center rounded-control border border-line bg-input text-[12px] text-ink-dim hover:text-ink transition">
                                            Criar cliente
                                        </a>
                                        <a href="{{ route('integracao.clientes', ['busca' => $registro->nome]) }}"
                                           class="h-8 px-3 inline-flex items-center rounded-control border border-line bg-input text-[12px] text-ink-dim hover:text-ink transition">
                                            Procurar
                                        </a>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-painel>
            @endforeach

            @if ($revendasPendentes->isNotEmpty())
                <x-painel titulo="Revendas sem par na matriz" :sub="$revendasPendentes->count().' registros'">
                    <div class="space-y-2">
                        @foreach ($revendasPendentes as $revenda)
                            <div class="flex flex-wrap items-center gap-3 rounded-tile border border-line bg-input px-3 py-2">
                                <div class="min-w-[200px] flex-1">
                                    <p class="text-[13px] text-ink truncate">{{ $revenda->nome }}</p>
                                    <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                                        {{ $revenda->cnpj ? \App\Services\Integracao\Documento::formatar($revenda->cnpj) : 'sem documento' }}
                                    </p>
                                </div>
                                <form method="POST" action="{{ route('integracao.conferencia.vincularRevenda', $revenda) }}"
                                      class="flex items-center gap-2">
                                    @csrf
                                    <select name="revenda_id" class="h-8 rounded-control border-line bg-input text-[12px] text-ink">
                                        @foreach (\App\Models\Revenda::orderBy('nome')->get() as $daMatriz)
                                            <option value="{{ $daMatriz->id }}">{{ $daMatriz->nome }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit"
                                            class="h-8 px-3 rounded-control border border-line bg-input text-[12px] text-ink-dim hover:text-ink transition">
                                        Vincular
                                    </button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                </x-painel>
            @endif
        @endif
    </div>
</x-app-layout>
