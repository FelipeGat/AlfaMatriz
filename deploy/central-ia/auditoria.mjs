/**
 * Quem viu o quê e quem alterou o quê (dono, 12/10/2026): os agentes
 * trabalham com acesso total — quem decide o que consultar e alterar é a IA —
 * e a pessoa confere depois, no Contexto da tarefa e no log.
 *
 * Duas fontes, separadas como no dossiê:
 *  - o que o AGENTE fez, lido dos eventos que a própria CLI emite (comandos,
 *    arquivos lidos e editados, buscas) — o Copilot não emite, e isso é dito;
 *  - o que MUDOU de fato no git da pasta, medido antes e depois pelo runner.
 */
const LIMITE = 300;

// O caminho de uma linha do `status --porcelain`. Por regex, e não por
// posição: o git() do runner apara a saída e a primeira linha perde o espaço
// inicial (" M a.txt" vira "M a.txt").
export const caminhoDoStatus = l => l.replace(/^[ MADRCUT?!]{1,2}\s+/, '');

export function criarAuditor(agente) {
    let resto = '';
    const acessos = [];
    const add = (ferramenta, alvo) => {
        if (acessos.length < LIMITE) acessos.push({ ferramenta: String(ferramenta).slice(0, 40), alvo: String(alvo ?? '').replace(/\s+/g, ' ').slice(0, 300) });
    };
    const alvo = i => i?.command ?? i?.file_path ?? i?.notebook_path ?? i?.path ?? i?.pattern ?? i?.url ?? i?.query ?? JSON.stringify(i ?? {}).slice(0, 200);
    return {
        saida(dados) {
            resto += String(dados);
            const linhas = resto.split('\n');
            resto = linhas.pop().slice(-1_000_000);
            for (const l of linhas) {
                let j; try { j = JSON.parse(l); } catch { continue; }
                if (agente === 'claude' && j.type === 'assistant') {
                    for (const c of j.message?.content || []) if (c?.type === 'tool_use') add(c.name, alvo(c.input));
                }
                if (agente === 'codex') {
                    const it = j.item;
                    if (j.type === 'item.started' && it?.type === 'command_execution') add('shell', it.command);
                    if (j.type === 'item.completed' && it?.type === 'file_change') for (const ch of it.changes || []) add('edição', ch.path);
                    if (j.type === 'item.started' && it?.type === 'web_search') add('web', it.query);
                    if (j.type === 'item.started' && it?.type === 'mcp_tool_call') add('mcp', `${it.server ?? ''} ${it.tool ?? ''}`.trim());
                }
            }
        },
        acessos: () => acessos,
        informado: agente !== 'copilot',
    };
}

/** Retrato do git da pasta: HEAD e cada linha do `status --porcelain`. */
export function retratoGit(git, cwd) {
    try {
        const status = git(cwd, 'status', '--porcelain', '--untracked-files=all').split('\n').filter(Boolean);
        // Hash do conteúdo de cada arquivo pendente: um arquivo que já estava
        // modificado e foi mexido de novo tem a mesma linha no status.
        const caminhos = status.map(caminhoDoStatus).filter(c => !c.includes(' -> ')).slice(0, 300);
        const hashes = {};
        if (caminhos.length) {
            const saida = (() => { try { return git(cwd, 'hash-object', '--', ...caminhos).split('\n'); } catch { return []; } })();
            caminhos.forEach((c, i) => { if (saida[i]) hashes[c] = saida[i]; });
        }
        return { head: git(cwd, 'rev-parse', 'HEAD'), status, hashes };
    } catch { return null; }
}

/** O que mudou entre os dois retratos: arquivos e commits novos. */
export function compararGit(git, cwd, antes, depois) {
    if (!antes || !depois) return null;
    const ja = new Set(antes.status.map(caminhoDoStatus));
    const mexidos = Object.keys(depois.hashes || {}).filter(c => antes.hashes?.[c] && antes.hashes[c] !== depois.hashes[c]);
    const agora = depois.status.map(caminhoDoStatus);
    const arquivos = [...new Set([...agora.filter(c => !ja.has(c)), ...mexidos])].slice(0, 200);
    const sumiram = [...ja].filter(c => !agora.includes(c)).slice(0, 200);
    let commits = [];
    if (antes.head !== depois.head) {
        try { commits = git(cwd, 'log', '--format=%h %s', `${antes.head}..${depois.head}`).split('\n').filter(Boolean).slice(0, 50); } catch {}
    }
    return { head_antes: antes.head, head_depois: depois.head, arquivos, revertidos: sumiram, commits };
}
