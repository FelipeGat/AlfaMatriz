@props([
    'registro',          // o modelo cujo rastro se quer ver
    'limite' => 12,      // quantos eventos cabem antes de mandar para a tela cheia
])

@php
    /**
     * O histórico DESTE registro, dentro da tela dele.
     *
     * A pergunta "quem mexeu nisto?" nasce olhando para o cliente, para a
     * receita, para o sistema — e não na tela de auditoria. Obrigar a sair
     * daqui, abrir a auditoria e reconstruir o filtro certo é o que faz uma
     * tela de auditoria existir e ninguém usar.
     *
     * O componente busca o próprio dado em vez de recebê-lo pronto, e é uma
     * decisão consciente: passá-lo pelo controller seria uma linha a repetir em
     * cada tela que ganhar a linha do tempo, esperando a primeira ser
     * esquecida — o mesmo motivo pelo qual o sino usa um View composer. O custo
     * é uma consulta por tela, indexada por `auditavel_type` + `auditavel_id`.
     *
     * Fechado por permissão, e não por "quem edita já pode ver": o rastro
     * mostra o que outras pessoas fizeram, incluindo valores anteriores que
     * quem edita hoje nunca teve acesso a ver.
     */
    $podeVer = auth()->user()?->canPermissao('auditoria', 'ler') ?? false;

    $eventos = $podeVer
        ? \App\Models\Auditoria::doRegistro($registro)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            // Um a mais que o limite, só para saber se há mais — contar a
            // tabela inteira para descobrir isso custaria uma segunda consulta
            // para uma informação que cabe numa linha extra.
            ->limit($limite + 1)
            ->get()
        : collect();

    $temMais = $eventos->count() > $limite;
    $eventos = $eventos->take($limite);
@endphp

@if ($podeVer)
    <x-painel titulo="Histórico" sub="{{ $eventos->count() }} {{ $eventos->count() === 1 ? 'evento' : 'eventos' }}">
        @if ($eventos->isEmpty())
            <p class="text-[13px] text-ink-mute">
                Nada registrado sobre este cadastro ainda.
            </p>
        @else
            {{-- O fio vertical é `border-l` na lista, e não um elemento próprio:
                 como pseudo-elemento posicionado ele passaria por baixo do
                 último marcador, deixando um risco solto embaixo do rodapé. --}}
            <ol class="border-l border-line ml-[5px] space-y-4">
                @foreach ($eventos as $evento)
                    <li class="relative pl-4">
                        {{-- O marcador COBRE o fio em vez de abrir um furo nele
                             com uma borda da cor do painel: `--subtle` é
                             translúcido no tema escuro, então a borda deixaria
                             o risco aparecer por baixo do ponto. Um círculo
                             opaco de 9px sobre um fio de 1px resolve nos dois
                             temas sem depender da cor do fundo. --}}
                        <span class="absolute -left-[5px] top-[5px] h-[9px] w-[9px] rounded-full"
                              style="background: rgb(var(--{{ match ($evento->tomDaAcao()) {
                                  'critico' => 'crit',
                                  'bom' => 'good',
                                  'ambar' => 'amber',
                                  'marca' => 'brand-text',
                                  default => 'ink-mute',
                              } }}))"></span>

                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                            <span class="text-[13px] font-medium text-ink">{{ $evento->rotuloDaAcao() }}</span>
                            <span class="text-[13px] text-ink-dim">{{ $evento->usuario_nome }}</span>
                            <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint whitespace-nowrap">
                                {{ $evento->created_at->format('d/m/Y H:i') }}
                            </span>
                        </div>

                        @if ($evento->alteracoes)
                            <div class="mt-1.5">
                                @include('auditoria._alteracoes', ['alteracoes' => $evento->alteracoes])
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>

            @if ($temMais)
                {{-- O link já vai FILTRADO. Mandar para a auditoria crua faria
                     quem clicasse chegar na primeira página do sistema inteiro
                     e ter de reconstruir à mão o recorte que já estava vendo. --}}
                <a href="{{ route('auditoria.index', ['busca' => $registro->descricaoDeAuditoria()]) }}"
                   class="mt-4 inline-block text-[12.5px] font-semibold text-brand hover:text-brand-bright transition">
                    Ver o histórico completo →
                </a>
            @endif
        @endif
    </x-painel>
@endif
