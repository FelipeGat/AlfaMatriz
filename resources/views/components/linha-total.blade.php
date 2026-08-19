{{--
    Linha de totais.

    ARMADILHA: basta UMA célula quebrar em duas linhas para a faixa de total
    ganhar duas alturas e desalinhar a tabela inteira — pôr `nowrap` só em
    algumas não resolve. Por isso ele é aplicado a TODAS as células daqui de
    dentro, por seletor, e não célula a célula na tela.

    A pressão era maior quando a linha vinha em mono: rótulo em caixa alta com
    `letter-spacing` largo é longo, e apertava a coluna até quebrar. Hoje a
    linha é Geist com `tabular`, que alinha os dígitos igual e ocupa menos —
    mas o `nowrap` fica, porque o que quebra a faixa é a quebra em si.
--}}
<tr {{ $attributes->merge([
    'class' => 'bg-head border-t border-line font-sans tabular text-[12px] font-semibold text-ink '
        .'[&>td]:whitespace-nowrap [&>th]:whitespace-nowrap [&>td]:px-4 [&>td]:py-3 [&>th]:px-4 [&>th]:py-3',
]) }}>
    {{ $slot }}
</tr>
