{{--
    O modelo do relato de bug (tarefa #204) — aparece quando o tipo é Bug.

    As #185 e #191 chegaram só com o sintoma: sem aluno, sem data, uma sem
    academia. Bug que afeta uma pessoa só não se investiga sem o caso
    concreto, e quem abre a tarefa é o único que tem esse caso na mão — meia
    hora depois ele já não lembra o minuto. Por isso o modelo aparece NA
    abertura, e "quem" e "quando" são exigidos (`TarefaService::comORelatoDoBug`,
    que também recusa o envio forjado e o do MCP com a mesma frase).

    "O que esperava" e "o que aconteceu" saíram no segundo ajuste da #204: eram
    três caixas de texto para a mesma história, e o resumo já existia. No Bug
    ele passa a se chamar "O que aconteceu" (`_form`), e o relato fica só com o
    que o resumo não diz — quem e quando.

    O print não tem campo próprio: é anexo, e a seção de anexos já está logo
    abaixo no mesmo formulário. A linha final aponta para ela.

    `x-show` e não `@if`: o tipo troca com o modal aberto, e o `tipo` vem do
    `x-data` do formulário (`_form`). O `required` acompanha o tipo — campo
    escondido e obrigatório travaria o Salvar de uma tarefa de desenvolvimento
    sem dizer por quê.
--}}
@php
    $quando = old('defeito_quando', $tarefa?->defeito_quando?->format('Y-m-d\TH:i') ?? '');
@endphp

<div x-show="tipo === 'bug'" @if ($tipoInicial !== 'bug') x-cloak @endif
     class="pt-4 border-t border-rule-strong flex flex-col gap-3" data-relato-bug>
    <div>
        <h4 class="font-mono text-[11.5px] font-semibold uppercase tracking-caps-wide text-ink">Relato do bug</h4>
        <p class="mt-1 text-[11px] leading-[1.4] text-ink-faint">
            Sem o caso concreto não dá para investigar. Quem e quando são obrigatórios.
        </p>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label for="defeito_quem-{{ $sufixo }}" class="block mb-[5px] text-[12px] font-medium text-ink-dim">Quem</label>
            <input id="defeito_quem-{{ $sufixo }}" name="defeito_quem" type="text" maxlength="255"
                   :required="tipo === 'bug'"
                   value="{{ old('defeito_quem', $tarefa->defeito_quem ?? '') }}"
                   placeholder="Cliente, aluno ou academia"
                   class="block w-full h-9 px-2.5 py-0 rounded-control bg-input border-line text-ink text-[13.5px]">
        </div>

        <div>
            <label for="defeito_quando-{{ $sufixo }}" class="block mb-[5px] text-[12px] font-medium text-ink-dim">Quando</label>
            <input id="defeito_quando-{{ $sufixo }}" name="defeito_quando" type="datetime-local"
                   :required="tipo === 'bug'"
                   value="{{ $quando }}"
                   class="block w-full h-9 py-0 rounded-control bg-input border-line text-ink text-[13px]">
        </div>
    </div>

    <p class="text-[11px] leading-[1.4] text-ink-faint">
        Print da tela: anexe em Anexos, mais abaixo.
    </p>
</div>
