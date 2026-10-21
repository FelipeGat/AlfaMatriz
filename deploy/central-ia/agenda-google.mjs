import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

/**
 * Agenda do Google para o Secretário, lida pela máquina (docs/specs/
 * secretario-agenda-pela-maquina): o exportador de SÓ LEITURA, que mora na
 * pasta da ponte pessoal do Google já autorizada, imprime uma linha marcada;
 * o runner valida o formato e manda ao AlfaMatriz.
 * Configuração: `CENTRAL_IA_GOOGLE="programa|script|pasta"` no `.env` do
 * runner — caminho de máquina nenhum mora neste código (T-374).
 */
const exec = promisify(execFile);
export const MARCA = 'SECRETARIO_AGENDA:';
const DATA = /^\d{4}-\d{2}-\d{2}([T ][\d:.]+([+-]\d{2}:\d{2}|Z)?)?$/;

/** A linha marcada, conferida. Formato fora do esperado = null (o servidor também confere). */
export function lerSaida(saida) {
    const linha = String(saida).split(/\r?\n/).find(l => l.startsWith(MARCA));
    if (!linha) return null;
    let d;
    try { d = JSON.parse(linha.slice(MARCA.length)); } catch { return null; }
    if (!d || !Array.isArray(d.agendas) || !Array.isArray(d.eventos)) return null;
    const texto = (v, n) => String(v ?? '').slice(0, n);
    const agendas = d.agendas.filter(a => a && typeof a.id === 'string').slice(0, 100)
        .map(a => ({ id: texto(a.id, 255), nome: texto(a.nome || a.id, 255), primaria: a.primaria === true }));
    const ids = new Set(agendas.map(a => a.id));
    const eventos = d.eventos.filter(e => e && ids.has(e.agenda) && typeof e.id === 'string' && DATA.test(String(e.inicio)) && DATA.test(String(e.fim)))
        .slice(0, 5000).map(e => ({ agenda: e.agenda, id: texto(e.id, 255), titulo: texto(e.titulo, 255), inicio: String(e.inicio), fim: String(e.fim),
            dia_inteiro: e.dia_inteiro === true, link: typeof e.link === 'string' && e.link.startsWith('https://') ? texto(e.link, 500) : null }));
    return { agendas, eventos };
}

/** @returns {Promise<{ok: true, agendas: object[], eventos: object[]} | {ok: false, erro: string}>} */
export async function lerAgenda(config, { executar = exec } = {}) {
    const [programa, script, pasta] = String(config || '').split('|');
    if (!programa || !script) return { ok: false, erro: 'CENTRAL_IA_GOOGLE incompleto' };
    try {
        // Python do Windows precisa de cwd numa pasta do Windows (a `pasta`).
        const { stdout } = await executar(programa, [script], { cwd: pasta || undefined, timeout: 120000, maxBuffer: 8 * 1024 * 1024 });
        const d = lerSaida(stdout);
        return d ? { ok: true, ...d } : { ok: false, erro: 'saída do exportador sem a agenda' };
    } catch (e) {
        // O motivo vai ao log da máquina (código e a última linha de erro), nunca ao servidor.
        const motivo = [e.code, String(e.stderr || '').trim().split(/\r?\n/).pop()].filter(Boolean).join(' · ').slice(0, 200);
        return { ok: false, erro: (e.killed ? 'o exportador passou do tempo' : 'o exportador falhou') + (motivo ? ' — ' + motivo : '') };
    }
}

/** A cada 10 minutos (a agenda muda mais que o Gestor). */
export function hora(ultimo, agora = new Date()) {
    return !ultimo || agora - ultimo >= 10 * 60_000;
}
