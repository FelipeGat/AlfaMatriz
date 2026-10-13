/** Metadados observáveis; nunca encaminha conteúdo de ferramentas ou mensagens. */
export function acompanharProgresso(relogio = Date.now) {
    let etapa = 'preparando', atividade = null, ativo = null, sequencia = 0, linha = '';
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
                if (evento.type === 'item.started' && ['command_execution', 'mcp_tool_call', 'web_search'].includes(evento.item?.type)) etapa = 'comando';
                if (evento.type === 'item.completed' || evento.type === 'turn.started') etapa = 'agente';
                if (evento.type === 'item.completed' && evento.item?.type === 'agent_message') etapa = 'resposta';
                if (evento.type === 'turn.completed') etapa = 'finalizando';
            }
        },
        retrato() { return { sequencia: ++sequencia, etapa, atividade_em: atividade, processo_ativo: ativo }; },
    };
}
