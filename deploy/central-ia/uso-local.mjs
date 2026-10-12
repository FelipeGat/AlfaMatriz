/**
 * Consumo de tokens e sessões das três IAs NESTA máquina, lidos dos registros
 * que elas mesmas gravam:
 *   Claude Code  ~/.claude/projects/<pasta>/<sessão>.jsonl
 *   Codex        ~/.codex/sessions/AAAA/MM/DD/rollout-*.jsonl
 *   Copilot      ~/.copilot/session-state/<sessão>/events.jsonl
 *
 * Regra herdada do painel-avanco: só CONTAGENS e metadados (tokens, modelo,
 * dia, pasta, quantidade de mensagens). O texto das conversas nunca sai daqui
 * — nem para o AlfaMatriz, nem para o log.
 *
 * Os arquivos só crescem no fim (jsonl), e o da sessão longa do gestor passa
 * de 100 MB: cada arquivo é lido a partir de onde parou na rodada anterior.
 */
import { closeSync, existsSync, openSync, readSync, readdirSync, statSync } from 'node:fs';
import { homedir } from 'node:os';
import { join, sep } from 'node:path';

const HOME = homedir();
const DIAS = 8;
const PEDACO = 8 * 1024 * 1024;

const lidos = new Map(); // caminho → estado incremental do arquivo
// Resposta do Claude → arquivo que a contou. `--fork-session` e `--resume`
// copiam o histórico para um arquivo novo: sem isto, cada continuação contava
// de novo tudo o que já tinha sido gasto (472 respostas em dobro em 8 dias).
const donoDaResposta = new Map();
let cotaCodex = null;

function dia(ts) {
    const d = new Date(ts);
    if (Number.isNaN(d.getTime())) return null;
    // Dia no fuso da máquina (America/Sao_Paulo), como a pessoa conta "hoje".
    return d.toLocaleDateString('sv-SE');
}

function arquivos(raiz, filtro, profundidade = 4, dias = DIAS) {
    const achados = [];
    const limite = Date.now() - dias * 86400_000;
    const andar = (dir, nivel) => {
        let itens;
        try { itens = readdirSync(dir, { withFileTypes: true }); } catch { return; }
        for (const it of itens) {
            const p = join(dir, it.name);
            if (it.isDirectory() && nivel < profundidade) andar(p, nivel + 1);
            else if (it.isFile() && filtro(it.name)) {
                try { if (statSync(p).mtimeMs >= limite) achados.push(p); } catch {}
            }
        }
    };
    if (existsSync(raiz)) andar(raiz, 0);
    return achados;
}

/** Lê o que foi acrescentado desde a última vez, linha a linha. */
function novasLinhas(caminho, aoLer) {
    let st;
    try { st = statSync(caminho); } catch { return null; }
    let e = lidos.get(caminho);
    if (!e || st.size < e.offset) {
        e = { offset: 0, resto: '', vistos: new Set(), uso: {}, projetos: {}, mensagens: 0, cwd: null, sessao: null, origem: null, ultima: null };
        lidos.set(caminho, e);
    }
    if (st.size === e.offset) return e;
    const fd = openSync(caminho, 'r');
    try {
        while (e.offset < st.size) {
            const buf = Buffer.alloc(Math.min(PEDACO, st.size - e.offset));
            const n = readSync(fd, buf, 0, buf.length, e.offset);
            if (!n) break;
            e.offset += n;
            const texto = e.resto + buf.toString('utf8', 0, n);
            const linhas = texto.split('\n');
            e.resto = linhas.pop();
            for (const l of linhas) {
                if (!l) continue;
                let j;
                try { j = JSON.parse(l); } catch { continue; }
                aoLer(j, l, e);
            }
        }
    } finally { closeSync(fd); }
    e.mtime = st.mtimeMs;
    return e;
}

function somar(e, d, agente, chave, valores) {
    if (!d) return;
    const k = `${d}|${chave}`;
    const alvo = (e.uso[k] ??= { dia: d, agente, modelo: chave, entrada: 0, saida: 0, cache_leitura: 0, cache_escrita: 0, premium: 0 });
    for (const [campo, v] of Object.entries(valores)) alvo[campo] += Number(v) || 0;
}

// Projeto citado nos caminhos de arquivo da linha — para saber de qual
// projeto é uma sessão aberta na RAIZ ~/projetos (caso do VS Code).
function contarProjetos(e, linha, raiz) {
    const re = new RegExp(raiz.replace(/[/.]/g, '\\$&') + '/([A-Za-z0-9][A-Za-z0-9._-]*)/', 'g');
    let m;
    while ((m = re.exec(linha))) e.projetos[m[1]] = (e.projetos[m[1]] || 0) + 1;
}

function lerClaude(raiz) {
    const base = join(HOME, '.claude/projects');
    return arquivos(base, n => n.endsWith('.jsonl'), 1).map(arq => {
        const e = novasLinhas(arq, (j, linha, e) => {
            if (j.cwd && !e.cwd) e.cwd = j.cwd;
            if (j.sessionId && !e.sessao) e.sessao = j.sessionId;
            if (j.entrypoint && !e.origem) e.origem = j.entrypoint;
            if (j.timestamp) e.ultima = j.timestamp;
            // Resultado de ferramenta também chega como `user`; conta só o que
            // a pessoa escreveu.
            const c = j.message?.content;
            if (j.type === 'user' && !j.isSidechain && !j.isMeta
                && (typeof c === 'string' || (Array.isArray(c) && c.some(x => x?.type === 'text')))) e.mensagens++;
            contarProjetos(e, linha, raiz);
            const u = j.message?.usage;
            if (j.type === 'assistant' && u) {
                // A mesma resposta aparece em várias linhas (uma por bloco):
                // conta uma vez por id de mensagem.
                const id = `${j.message.id}|${j.requestId ?? ''}`;
                if (e.vistos.has(id)) return;
                e.vistos.add(id);
                const dono = donoDaResposta.get(id);
                if (dono && dono !== arq) return;
                donoDaResposta.set(id, arq);
                somar(e, dia(j.timestamp), 'claude', j.message.model || '?', {
                    entrada: u.input_tokens, saida: u.output_tokens,
                    cache_leitura: u.cache_read_input_tokens, cache_escrita: u.cache_creation_input_tokens,
                });
            }
        });
        return e && { ...e, agente: 'claude', vscode: e.origem === 'claude-vscode' };
    }).filter(Boolean);
}

function lerCodex(raiz) {
    return arquivos(join(HOME, '.codex/sessions'), n => n.startsWith('rollout-') && n.endsWith('.jsonl')).map(arq => {
        const e = novasLinhas(arq, (j, linha, e) => {
            const p = j.payload || {};
            if (j.timestamp) e.ultima = j.timestamp;
            if (j.type === 'session_meta') { e.cwd = p.cwd; e.sessao = p.id; e.origem = p.originator; }
            if (j.type === 'response_item' && p.role === 'user') e.mensagens++;
            contarProjetos(e, linha, raiz);
            if (p.type === 'token_count') {
                const u = p.info?.last_token_usage;
                if (u) {
                    somar(e, dia(j.timestamp), 'codex', 'codex', {
                        entrada: (u.input_tokens || 0) - (u.cached_input_tokens || 0), saida: u.output_tokens,
                        cache_leitura: u.cached_input_tokens,
                    });
                }
                if (p.rate_limits && (!cotaCodex || j.timestamp > cotaCodex.em)) {
                    const r = p.rate_limits;
                    const janela = x => x && { usado_pct: x.used_percent, janela_min: x.window_minutes, renova_em: x.resets_at ? new Date(x.resets_at * 1000).toISOString() : null };
                    cotaCodex = { em: j.timestamp, primaria: janela(r.primary), semanal: janela(r.secondary) };
                }
            }
        });
        return e && { ...e, agente: 'codex', vscode: e.origem === 'codex_vscode' };
    }).filter(Boolean);
}

function lerCopilot(raiz) {
    // 40 dias, e não 8: a franquia do Copilot é mensal, e o "usado no mês"
    // precisa das sessões desde o dia 1.
    return arquivos(join(HOME, '.copilot/session-state'), n => n === 'events.jsonl', 1, 40).map(arq => {
        const e = novasLinhas(arq, (j, linha, e) => {
            const d = j.data || {};
            if (j.timestamp) e.ultima = j.timestamp;
            if (d.sessionId && !e.sessao) e.sessao = d.sessionId;
            if (d.context?.cwd && !e.cwd) { e.cwd = d.context.cwd; e.origem = d.producer; }
            if (j.type === 'user.message') e.mensagens++;
            contarProjetos(e, linha, raiz);
            const u = d.usage;
            if (j.type === 'session.usage_record' && u) {
                somar(e, dia(j.timestamp), 'copilot', u.model || '?', {
                    entrada: u.inputTokens, saida: u.outputTokens, cache_leitura: u.cacheReadTokens,
                    cache_escrita: u.cacheWriteTokens, premium: u.cost,
                });
            }
        });
        return e && { ...e, agente: 'copilot', vscode: true };
    }).filter(Boolean);
}

/** O projeto de uma sessão: a pasta, ou — na raiz — o mais citado nos caminhos. */
function projetoDa(e, raiz) {
    if (e.cwd && e.cwd.startsWith(raiz + sep)) {
        const p = e.cwd.slice(raiz.length + 1).split(sep)[0];
        if (!p.startsWith('.')) return p;
        return null; // worktree da própria Central
    }
    const [maior] = Object.entries(e.projetos).sort((a, b) => b[1] - a[1]);
    return maior && maior[1] >= 5 ? maior[0] : null;
}

/**
 * @returns {{consumo: object, sessoes: object[]}}
 */
export function usoLocal(raiz) {
    const todas = [...lerClaude(raiz), ...lerCodex(raiz), ...lerCopilot(raiz)];
    const desde = new Date(Date.now() - DIAS * 86400_000).toLocaleDateString('sv-SE');

    const linhas = {};
    for (const s of todas) {
        const projeto = projetoDa(s, raiz) || '(sem projeto)';
        for (const u of Object.values(s.uso)) {
            if (u.dia < desde) continue;
            const k = `${u.dia}|${u.agente}|${projeto}|${u.modelo}`;
            const alvo = (linhas[k] ??= { dia: u.dia, agente: u.agente, projeto, modelo: u.modelo, entrada: 0, saida: 0, cache_leitura: 0, cache_escrita: 0, premium: 0 });
            for (const c of ['entrada', 'saida', 'cache_leitura', 'cache_escrita', 'premium']) alvo[c] += u[c];
        }
    }

    // Sessões que a PESSOA abriu (VS Code), as mais recentes primeiro — as
    // que o runner cria (`sdk-cli`, `codex_exec`) e as das worktrees da
    // Central ficam de fora.
    const sessoes = todas
        .filter(s => s.vscode && s.sessao && s.cwd && s.ultima && !s.cwd.includes(`${sep}.central-ia${sep}`))
        .map(s => ({ agente: s.agente, id: s.sessao, cwd: s.cwd, projeto: projetoDa(s, raiz), ultima_atividade: s.ultima, mensagens: s.mensagens }))
        .filter(s => s.projeto && s.mensagens > 0)
        .sort((a, b) => b.ultima_atividade.localeCompare(a.ultima_atividade))
        .slice(0, 40);

    const inicioDoMes = new Date().toLocaleDateString('sv-SE').slice(0, 8) + '01';
    const premiumMes = todas.filter(s => s.agente === 'copilot')
        .flatMap(s => Object.values(s.uso)).filter(u => u.dia >= inicioDoMes)
        .reduce((t, u) => t + u.premium, 0);

    return {
        consumo: { linhas: Object.values(linhas), cota_codex: cotaCodex, copilot_premium_mes: Math.round(premiumMes * 100) / 100, coletado_em: new Date().toISOString() },
        sessoes,
    };
}
