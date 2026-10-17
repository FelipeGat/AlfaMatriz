/**
 * O que a pessoa vê enquanto o agente trabalha. Além da etapa, a ÚLTIMA AÇÃO
 * (ferramenta + alvo, curta) e a ÚLTIMA FALA do agente (até 200 caracteres)
 * — Felipe, 17/10/2026: "não pode ficar 11 min sem interação ou alguma
 * amostra do que está sendo feito". Nunca o conteúdo de arquivos nem a saída
 * das ferramentas; o servidor ainda mascara segredos (ProtecaoDeSegredos).
 */
const curto = (s, n) => {
    const t = String(s ?? '').replace(/\s+/g, ' ').trim();
    return t.length > n ? t.slice(0, n - 1) + '…' : t;
};

/** A ação de uma ferramenta, em português e curta. */
export function descreverAcao(ferramenta, entrada = {}) {
    const alvo = entrada?.file_path ?? entrada?.notebook_path ?? entrada?.path ?? '';
    switch (ferramenta) {
        case 'Read': return `lendo ${curto(alvo, 120)}`;
        case 'Edit': case 'MultiEdit': case 'NotebookEdit': return `editando ${curto(alvo, 120)}`;
        case 'Write': return `escrevendo ${curto(alvo, 120)}`;
        case 'Bash': return `rodando: ${curto(entrada?.command, 120)}`;
        case 'Grep': return `buscando "${curto(entrada?.pattern, 60)}"${alvo ? ' em ' + curto(alvo, 60) : ''}`;
        case 'Glob': return `procurando arquivos ${curto(entrada?.pattern, 80)}`;
        case 'WebSearch': return `pesquisando na web: ${curto(entrada?.query, 100)}`;
        case 'WebFetch': return `abrindo ${curto(entrada?.url, 100)}`;
        case 'TodoWrite': return 'organizando as próximas etapas';
        case 'Task': case 'Agent': return `delegando a um subagente: ${curto(entrada?.description, 80)}`;
        default: return curto(`usando ${ferramenta}`, 120);
    }
}

export function acompanharProgresso(relogio = Date.now) {
    let etapa = 'preparando', atividade = null, ativo = null, sequencia = 0, linha = '';
    let acao = null, acaoEm = null, fala = null, acoes = 0;
    const agir = texto => { acao = curto(texto, 160); acaoEm = new Date(relogio()).toISOString(); acoes++; };
    return {
        etapa(valor) { etapa = valor; },
        processo(valor) { ativo = valor; },
        saida(dados) {
            atividade = new Date(relogio()).toISOString();
            // JSONL pode vir fragmentado ou com várias linhas no mesmo chunk.
            linha += String(dados);
            const linhas = linha.split('\n'); linha = linhas.pop().slice(-65536);
            for (const l of linhas) {
                let evento; try { evento = JSON.parse(l); } catch { continue; }
                // Codex (--json).
                const it = evento.item;
                if (evento.type === 'item.started' && ['command_execution', 'mcp_tool_call', 'web_search'].includes(it?.type)) {
                    etapa = 'comando';
                    agir(it.type === 'command_execution' ? `rodando: ${curto(it.command, 120)}`
                        : it.type === 'web_search' ? `pesquisando na web: ${curto(it.query, 100)}` : `usando ${curto(`${it.server ?? ''} ${it.tool ?? ''}`, 80)}`);
                }
                if (evento.type === 'item.completed' && it?.type === 'file_change') agir(`editando ${curto((it.changes || []).map(c => c.path).join(', '), 120)}`);
                if (evento.type === 'item.completed' || evento.type === 'turn.started') etapa = 'agente';
                if (evento.type === 'item.completed' && it?.type === 'agent_message') { etapa = 'resposta'; fala = curto(it.text, 200) || fala; }
                if (evento.type === 'turn.completed') etapa = 'finalizando';
                // Claude (stream-json): tool_use, texto e o resultado final.
                if (evento.type === 'assistant') {
                    for (const c of evento.message?.content || []) {
                        if (c?.type === 'tool_use') { etapa = 'comando'; agir(descreverAcao(c.name, c.input)); }
                        if (c?.type === 'text' && c.text?.trim()) { etapa = 'resposta'; fala = curto(c.text, 200); }
                    }
                }
                if (evento.type === 'user' && (evento.message?.content || []).some(c => c?.type === 'tool_result')) etapa = 'agente';
                if (evento.type === 'result') etapa = 'finalizando';
            }
        },
        retrato() {
            return { sequencia: ++sequencia, etapa, atividade_em: atividade, processo_ativo: ativo, acao, acao_em: acaoEm, fala, acoes };
        },
    };
}
