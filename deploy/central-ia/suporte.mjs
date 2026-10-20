import { execFile, spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, unlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { modeloArgs, permissoes } from './politica.mjs';

/**
 * O Pedro, atendente do suporte no WhatsApp (docs/specs/suporte-pedro-whatsapp).
 * A mesma IA da Central (o Claude ou o Codex desta máquina), numa vaga SÓ DO
 * SUPORTE — nunca espera os pedidos de código — e SEM NENHUMA FERRAMENTA: a
 * mensagem do cliente é texto de terceiro e pode tentar dar ordens à IA. Sem
 * pasta de projeto: roda numa pasta temporária vazia.
 */
export const TEMPO_MS = 90_000;

function rodar(bin, args, entrada, cwd, timeoutMs) {
    return new Promise((resolve, reject) => {
        const filho = spawn(bin, args, { cwd, stdio: ['pipe', 'pipe', 'pipe'] });
        let stdout = '';
        let stderr = '';
        let killed = false;
        const relogio = setTimeout(() => { killed = true; filho.kill('SIGKILL'); }, timeoutMs);
        filho.stdout.on('data', d => { if (stdout.length < 4_000_000) stdout += d; });
        filho.stderr.on('data', d => { if (stderr.length < 100_000) stderr += d; });
        filho.on('error', e => { clearTimeout(relogio); reject(e); });
        filho.on('close', codigo => { clearTimeout(relogio); resolve({ codigo, stdout, stderr, killed }); });
        filho.stdin.end(entrada);
    });
}

/** O texto que a IA devolveu para o pedido (o bloco <<<PEDRO … PEDRO>>> é conferido no Matriz). */
export async function responderComIa(job, agentes, { executar = rodar, tempoMs = TEMPO_MS } = {}) {
    const dir = mkdtempSync(join(tmpdir(), 'pedro-'));
    try {
        if (job.agente === 'codex') {
            if (!agentes.codex) throw new Error('Codex não instalado nesta máquina');
            const saida = join(dir, 'resposta.txt');
            const args = ['exec', '--json', '-o', saida, '--skip-git-repo-check', ...permissoes('codex', 'leitura', { semFerramentas: true }),
                ...modeloArgs('codex', job.modelo), '-c', 'forced_login_method="chatgpt"', '-'];
            const r = await executar(agentes.codex, args, job.prompt, dir, tempoMs);
            if (r.killed) throw new Error('a IA passou do tempo');
            return readFileSync(saida, 'utf8');
        }
        if (!agentes.claude) throw new Error('Claude não instalado nesta máquina');
        const args = ['-p', '--output-format', 'json', '--no-session-persistence', ...permissoes('claude', 'leitura', { semFerramentas: true }),
            ...modeloArgs('claude', job.modelo)];
        const r = await executar(agentes.claude, args, job.prompt, dir, tempoMs);
        if (r.killed) throw new Error('a IA passou do tempo');
        let j;
        try { j = JSON.parse(r.stdout.trim().split('\n').pop()); } catch { throw new Error('resposta ilegível do Claude'); }
        if (j.is_error || typeof j.result !== 'string') throw new Error(String(j.result || j.subtype || 'o Claude não respondeu').slice(0, 200));
        return j.result;
    } finally {
        rmSync(dir, { recursive: true, force: true });
    }
}

/**
 * Uma rodada: pega o próximo pedido do suporte e devolve a resposta (ou o erro).
 * @returns {Promise<{status: number, id?: number, ms?: number, erro?: string}>}
 */
export async function atenderSuporte(maquina, { api, agentes, responder = responderComIa }) {
    const r = await api('suporte/proxima', { maquina });
    if (r.status !== 200 || !r.json?.id) return { status: r.status };
    const inicio = Date.now();
    let texto = null;
    let erro = null;
    try { texto = await responder(r.json, agentes); } catch (e) { erro = String(e.message || e).slice(0, 400); }
    await api(`suporte/${r.json.id}/resposta`, { maquina, texto, erro });
    return { status: 200, id: r.json.id, ms: Date.now() - inicio, erro: erro || undefined };
}

// ── O retrato do Gestor, SÓ LEITURA (Felipe, 20/10/2026) ────────────────────
// Mesma ponte e mesmo `CENTRAL_IA_GESTOR="programa|ponte|script|pasta"` do
// retrato do Secretário. O `script` diz a pasta do lado do Windows (onde a
// ponte lê) e a `pasta`, a mesma pasta do lado do WSL (onde este código copia o
// script). Nunca passa --apply; o script recusa APPLY=1, abre a sessão READ
// ONLY e desfaz a transação.
const execP = promisify(execFile);
const AQUI = dirname(fileURLToPath(import.meta.url));

/** O retrato do Gestor: clientes ativos com telefones, atendimentos recentes e assuntos. */
export async function lerGestorParaPedro(config, { executar = execP, tentativas = 3 } = {}) {
    const [programa, script, arquivoWin, pasta] = String(config || '').split('|');
    if (!programa || !script || !arquivoWin || !pasta) throw new Error('CENTRAL_IA_GESTOR incompleto');
    const nome = 'pedro_gestor_ler.php';
    const local = join(pasta, nome);
    writeFileSync(local, readFileSync(join(AQUI, 'suporte_gestor_ler.php'), 'utf8'));
    try {
        let falha;
        // A chamada do WSL ao Python do Windows às vezes cai (vsock): tenta de novo.
        for (let i = 1; i <= tentativas; i++) {
            try {
                const { stdout } = await executar(programa, [script, `${arquivoWin.replace(/[\\/][^\\/]*$/, '')}\\${nome}`],
                    { cwd: pasta, timeout: 180000, maxBuffer: 16 * 1024 * 1024 });
                const l = String(stdout).split(/\r?\n/).find(x => x.startsWith('SUPORTE_GESTOR:'));
                const r = l ? JSON.parse(l.slice('SUPORTE_GESTOR:'.length)) : null;
                if (!r || !Array.isArray(r.clientes) || !Array.isArray(r.atendimentos)) throw new Error('saída da ponte sem o retrato');
                return r;
            } catch (e) {
                falha = e;
                if (i < tentativas) await new Promise(res => setTimeout(res, 3000 * i));
            }
        }
        throw falha;
    } finally {
        try { unlinkSync(local); } catch {}
    }
}
