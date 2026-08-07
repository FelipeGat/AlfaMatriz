<x-app-layout>
    <x-slot name="titulo">Sistemas</x-slot>
    <x-slot name="contexto">{{ $resumo['conectados'] }} de {{ $resumo['total'] }} conectados</x-slot>

    {{--
        O painel de integração responde três perguntas de relance, e é por elas
        que ele começa: está conectado? de quando é o dado? a matriz já manda
        nele? Sem as três, nenhuma outra tela desta seção é confiável — e uma
        tabela bonita de dado velho é pior que tabela nenhuma.
    --}}
    <div class="space-y-4">
        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif
        @if (session('erro'))
            <x-aviso tom="critico" :segundos="0">{{ session('erro') }}</x-aviso>
        @endif

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <x-kpi-card
                rotulo="Sistemas conectados"
                :valor="$resumo['conectados'].' / '.$resumo['total']"
                :delta="$resumo['fora_do_ar'] > 0 ? $resumo['fora_do_ar'].' fora do ar' : 'todos respondendo'"
                :sinal="$resumo['fora_do_ar'] > 0 ? 'ruim' : 'bom'"
                icone="cube" />

            <x-kpi-card
                rotulo="Clientes espelhados"
                :valor="number_format($resumo['clientes'], 0, ',', '.')"
                delta="somando todos os sistemas"
                icone="users" />

            <x-kpi-card
                rotulo="Licenças vencendo"
                :valor="number_format($resumo['vencendo'], 0, ',', '.')"
                :delta="'nos próximos '.config('integracao.dias_para_licenca_vencendo', 30).' dias'"
                :sinal="$resumo['vencendo'] > 0 ? 'ruim' : 'bom'"
                acento="warn"
                icone="clock" />

            <x-kpi-card
                rotulo="Pendências de conferência"
                :valor="number_format($resumo['pendencias'], 0, ',', '.')"
                :delta="$resumo['pendencias'] > 0 ? 'travam o corte' : 'nada pendente'"
                :sinal="$resumo['pendencias'] > 0 ? 'ruim' : 'bom'"
                acento="crit"
                icone="alert-triangle" />
        </div>

        @forelse ($cartoes as $cartao)
            @php
                $sistema = $cartao['sistema'];

                // O estágio é o que separa "estou olhando" de "estou mandando".
                // Cair de um para o outro sem perceber seria o pior erro
                // possível desta tela.
                [$estagioTom, $estagioTexto] = match ($cartao['estagio']) {
                    'matriz_manda' => ['marca', 'a matriz manda no cadastro'],
                    'conferindo' => ['atencao', 'conferindo o cadastro importado'],
                    'observando' => ['neutro', 'apenas observando'],
                    default => ['neutro', 'ainda não ligado'],
                };

                $motivoTexto = match ($cartao['motivo']) {
                    'sem_endereco' => 'falta o endereço de integração',
                    'sem_chave' => 'falta a chave de integração',
                    'chave_ilegivel' => 'a chave não pode ser lida (a chave da aplicação mudou?)',
                    'sistema_inativo' => 'o sistema está desativado no cadastro',
                    'fora_do_escopo' => 'fora do escopo da integração',
                    default => null,
                };
            @endphp

            <x-painel>
                <div class="flex flex-wrap items-start gap-4">
                    <div class="flex items-center gap-3 min-w-[220px]">
                        <span class="h-9 w-9 shrink-0">
                            <x-marca-sistema :sistema="$sistema" />
                        </span>
                        <div class="min-w-0">
                            <p class="font-display text-[15px] font-semibold text-ink truncate">{{ $sistema->nome }}</p>
                            <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint truncate">
                                {{ $sistema->unidade_cobranca ?: 'unidade não declarada' }}
                            </p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($motivoTexto)
                            <x-badge tom="atencao" ponto>não configurado</x-badge>
                            <span class="text-[12px] text-ink-dim">{{ $motivoTexto }}</span>
                        @elseif ($cartao['fora_do_ar'])
                            <x-badge tom="critico" ponto>fora do ar</x-badge>
                            <span class="text-[12px] text-ink-dim">
                                {{ $sistema->falhas_consecutivas }} {{ $sistema->falhas_consecutivas === 1 ? 'tentativa falhou' : 'tentativas seguidas falharam' }}
                            </span>
                        @else
                            <x-badge tom="bom" ponto>conectado</x-badge>
                        @endif

                        <x-badge :tom="$estagioTom">{{ $estagioTexto }}</x-badge>

                        {{-- De quando é o dado. O tom já diz se dá para confiar. --}}
                        <x-atualizado-em :em="$sistema->sincronizado_em" vazio="nunca sincronizado" />
                    </div>

                    <div class="ml-auto flex items-center gap-2">
                        @if (! $motivoTexto)
                            <form method="POST" action="{{ route('integracao.testar', $sistema) }}">
                                @csrf
                                <button type="submit"
                                        class="h-8 px-3 rounded-control border border-line bg-input text-[12px] text-ink-dim hover:text-ink transition">
                                    Testar conexão
                                </button>
                            </form>
                        @else
                            <a href="{{ route('sistemas.edit', $sistema) }}"
                               class="h-8 px-3 inline-flex items-center rounded-control border border-line bg-input text-[12px] text-ink-dim hover:text-ink transition">
                                Configurar
                            </a>
                        @endif

                        @if ($cartao['pendencias'] > 0)
                            <a href="{{ route('integracao.conferencia', ['sistema' => $sistema->id]) }}"
                               class="h-8 px-3 inline-flex items-center gap-1.5 rounded-control border border-line bg-input text-[12px] text-warn hover:text-ink transition">
                                <span class="h-3.5 w-3.5"><x-nav-icon name="alert-triangle" /></span>
                                {{ $cartao['pendencias'] }} a conferir
                            </a>
                        @endif
                    </div>
                </div>

                {{-- Os números do retrato: o que a matriz enxerga hoje lá dentro. --}}
                <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 border-t border-line pt-4">
                    @foreach ([
                        ['Clientes no sistema', number_format($cartao['clientes'], 0, ',', '.')],
                        ['Clientes ativos', number_format($cartao['clientes_ativos'], 0, ',', '.')],
                        ['Unidades cobráveis', number_format($cartao['unidades'], 0, ',', '.')],
                        ['Licenças vencendo', number_format($cartao['licencas_vencendo'], 0, ',', '.')],
                    ] as [$rotulo, $valor])
                        <div>
                            <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">{{ $rotulo }}</p>
                            <p class="mt-0.5 font-display text-[18px] font-semibold text-ink tabular">{{ $valor }}</p>
                        </div>
                    @endforeach
                </div>

                @if ($cartao['ultima'])
                    <p class="mt-3 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                        Última sincronização:
                        {{ $cartao['ultima']->status === 'sucesso' ? 'sucesso' : ($cartao['ultima']->status === 'parcial' ? 'entrou em parte' : 'falhou') }}
                        · {{ number_format($cartao['ultima']->itens_lidos, 0, ',', '.') }} itens
                        @if ($cartao['ultima']->erro_mensagem)
                            <span class="text-crit normal-case tracking-normal font-sans">— {{ $cartao['ultima']->erro_mensagem }}</span>
                        @endif
                    </p>
                @endif
            </x-painel>
        @empty
            <x-painel>
                <p class="text-[13px] text-ink-dim">
                    Nenhum produto vendido como serviço cadastrado. A integração só enxerga sistemas da
                    categoria correspondente — é o que mantém os produtos de outra natureza fora dela.
                </p>
            </x-painel>
        @endforelse
    </div>
</x-app-layout>
