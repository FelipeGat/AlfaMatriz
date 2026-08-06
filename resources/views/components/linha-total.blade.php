{{--
    Linha de totais.

    ARMADILHA: os rótulos de total vêm em mono, caixa alta e com
    `letter-spacing` largo — eles quebram em duas linhas assim que a coluna
    aperta, e aí a faixa de total ganha duas alturas diferentes e desalinha a
    tabela inteira. Não adianta pôr `nowrap` em algumas células: basta uma
    quebrar. Por isso o `whitespace-nowrap` é aplicado a TODAS as células daqui
    de dentro, por seletor, e não célula a célula na tela.
--}}
<tr {{ $attributes->merge([
    'class' => 'bg-head border-t border-line font-mono text-[12px] font-semibold text-ink '
        .'[&>td]:whitespace-nowrap [&>th]:whitespace-nowrap [&>td]:px-4 [&>td]:py-3 [&>th]:px-4 [&>th]:py-3',
]) }}>
    {{ $slot }}
</tr>
