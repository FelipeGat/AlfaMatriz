{{--
    O antes/depois de uma linha de auditoria. Espera: $alteracoes (array).

    Partial compartilhado entre a tela de auditoria e a linha do tempo dentro
    de cada registro. Em duas cópias, a primeira mudança de formato deixaria
    uma delas mostrando o valor cru enquanto a outra já traduziria — e a
    divergência só apareceria para quem abrisse as duas telas no mesmo dia.

    Sem `overflow-hidden` no valor e COM `break-all`: o detalhamento de uma
    receita é um JSON de linha só, e `nowrap` aqui pintaria por cima da coluna
    vizinha em vez de cortar (armadilha 4 do README).
--}}

@if (! empty($alteracoes))
    <dl class="grid gap-x-3 gap-y-1.5" style="grid-template-columns: minmax(90px, auto) 1fr">
        @foreach ($alteracoes as $campo => $par)
            <dt class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint pt-[3px] truncate">
                {{ \App\Models\Auditoria::rotuloDoCampo($campo) }}
            </dt>

            <dd class="flex flex-wrap items-baseline gap-1.5 text-[12.5px] min-w-0">
                {{-- O valor antigo riscado e apagado, o novo em destaque: a
                     direção da mudança tem de ser legível sem ler os dois
                     lados. Na criação o "de" é nulo e vira um travessão, que
                     é o único jeito honesto de mostrar o que não existia. --}}
                <span class="text-ink-mute line-through break-all">
                    {{ \App\Models\Auditoria::valorLegivel($par['de'] ?? null) }}
                </span>

                <span class="text-ink-faint shrink-0">→</span>

                <span class="text-ink break-all">
                    {{ \App\Models\Auditoria::valorLegivel($par['para'] ?? null) }}
                </span>
            </dd>
        @endforeach
    </dl>
@endif
