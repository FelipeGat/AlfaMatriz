import { execFile, spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, unlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';
import { modeloArgs, permissoes } from './politica.mjs';
import { analisarVideoCurto, transcreverAudioCurto, whisperInstalado } from './transcricao.mjs';

/**
 * O Pedro, atendente do suporte no WhatsApp (docs/specs/suporte-pedro-whatsapp).
 * A mesma IA da Central (o Claude ou o Codex desta máquina), numa vaga SÓ DO
 * SUPORTE — nunca espera os pedidos de código — e SEM NENHUMA FERRAMENTA: a
 * mensagem do cliente é texto de terceiro e pode tentar dar ordens à IA. Sem
 * pasta de projeto: roda numa pasta temporária vazia.
 */
export const TEMPO_MS = 90_000;

/** Tempo total para transcrever os áudios de um pedido do Pedro. */
export const ORCAMENTO_AUDIO_MS = 70_000;

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
    // As fotos que o cliente mandou (Fase 2): o Pedro as vê junto do prompt.
    const imagens = (job.imagens || []).filter(i => /^image\/(jpeg|png|webp)$/.test(i.mime) && typeof i.base64 === 'string' && i.base64);
    try {
        if (job.agente === 'codex') {
            if (!agentes.codex) throw new Error('Codex não instalado nesta máquina');
            const saida = join(dir, 'resposta.txt');
            const fotos = imagens.map((img, n) => {
                const arq = join(dir, `foto-${n}.${img.mime.split('/')[1].replace('jpeg', 'jpg')}`);
                writeFileSync(arq, Buffer.from(img.base64, 'base64'));
                return arq;
            });
            const args = ['exec', '--json', '-o', saida, '--skip-git-repo-check', ...permissoes('codex', 'leitura', { semFerramentas: true }),
                ...modeloArgs('codex', job.modelo), '-c', 'forced_login_method="chatgpt"', ...fotos.flatMap(f => ['-i', f]), '-'];
            const r = await executar(agentes.codex, args, job.prompt, dir, tempoMs);
            if (r.killed) throw new Error('a IA passou do tempo');
            return readFileSync(saida, 'utf8');
        }
        if (!agentes.claude) throw new Error('Claude não instalado nesta máquina');
        // Com foto, o prompt vai como mensagem stream-json com os blocos de imagem
        // (testado em 20/10/2026, sem nenhuma ferramenta liberada); sem foto, texto puro.
        const comFotos = imagens.length > 0;
        const args = ['-p', ...(comFotos ? ['--input-format', 'stream-json', '--output-format', 'stream-json', '--verbose'] : ['--output-format', 'json']), '--no-session-persistence',
            ...permissoes('claude', 'leitura', { semFerramentas: true }), ...modeloArgs('claude', job.modelo)];
        const entrada = comFotos
            ? JSON.stringify({ type: 'user', message: { role: 'user', content: [
                ...imagens.map(i => ({ type: 'image', source: { type: 'base64', media_type: i.mime, data: i.base64 } })),
                { type: 'text', text: job.prompt }] } }) + '\n'
            : job.prompt;
        const r = await executar(agentes.claude, args, entrada, dir, tempoMs);
        if (r.killed) throw new Error('a IA passou do tempo');
        let j;
        // Com --verbose (stream-json), o resultado é a linha de tipo "result".
        const linhas = r.stdout.trim().split('\n');
        try { j = JSON.parse(linhas.reverse().find(l => l.includes('"type":"result"')) || linhas[0]); } catch { throw new Error('resposta ilegível do Claude'); }
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
export async function atenderSuporte(maquina, { api, agentes, responder = responderComIa, transcrever = transcreverAudioCurto, analisarVideo = analisarVideoCurto, temWhisper = whisperInstalado }) {
    const r = await api('suporte/proxima', { maquina });
    if (r.status !== 200 || !r.json?.id) return { status: r.status };
    const inicio = Date.now();
    // Áudios do cliente: transcritos aqui, sem rede, e o texto entra no prompt no
    // lugar do marcador (⟦AUDIO:id⟧). Sem Whisper ou com falha: "não transcrito".
    const transcricoes = [];
    const audios = r.json.audios || [];
    // Vídeos (20/10/2026): quadros que viram fotos para a IA, e a fala transcrita.
    const videos = r.json.videos || [];
    const vistos = [];
    if ((audios.length || videos.length) && await temWhisper()) {
        // Orçamento: o Gestor manda o menu se o Pedro passar de 2 min; a IA ainda precisa do tempo dela.
        const limite = Date.now() + ORCAMENTO_AUDIO_MS;
        for (const v of videos) {
            const resta = limite - Date.now();
            if (resta < 15_000) break;
            const visto = await analisarVideo(Buffer.from(String(v.base64 || ''), 'base64'), v.extensao, tmpdir(), { timeoutMs: resta });
            if (!visto) continue;
            vistos.push({ id: v.id, ...visto });
            // Registrado como "transcrição" do vídeo: o Matriz não o manda de novo no próximo pedido.
            transcricoes.push({ id: v.id, texto: visto.fala ? `[vídeo visto] ${visto.fala}` : '[vídeo visto, sem fala]' });
        }
        for (const a of audios) {
            const resta = limite - Date.now();
            if (resta < 15_000) break;
            const texto = await transcrever(Buffer.from(String(a.base64 || ''), 'base64'), a.extensao, tmpdir(), { timeoutMs: resta });
            if (texto) transcricoes.push({ id: a.id, texto });
        }
    }
    const job = { ...r.json,
        imagens: [...(r.json.imagens || []), ...vistos.flatMap(v => v.quadros.map(base64 => ({ id: v.id, mime: 'image/jpeg', base64 })))],
        prompt: String(r.json.prompt || '').replace(/⟦AUDIO:(\d+)⟧/g, (_, id) => {
            const t = transcricoes.find(x => String(x.id) === id);
            return t ? `[áudio, transcrito] ${t.texto}` : '[áudio não transcrito]';
        }).replace(/⟦VIDEO:(\d+)⟧/g, (_, id) => {
            const v = vistos.find(x => String(x.id) === id);
            if (!v) return '[vídeo — não consegui abrir; fica registrado para a equipe técnica]';
            return `[VÍDEO nº ${id} — ${v.quadros.length} quadro(s) dele ANEXADO(S) como imagens, do início ao fim; veja-os]`
                + (v.fala ? ` fala no vídeo: ${v.fala}` : ' (sem fala)');
        }) };
    let texto = null;
    let erro = null;
    try { texto = await responder(job, agentes); } catch (e) { erro = String(e.message || e).slice(0, 400); }
    await api(`suporte/${r.json.id}/resposta`, { maquina, texto, erro, ...(transcricoes.length ? { transcricoes } : {}) });
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

/**
 * Uma chamada da ponte do Gestor por vez nesta máquina: ela grava o script num
 * nome fixo no servidor (_run.php), e duas ao mesmo tempo trocariam um script pelo
 * outro — a leitura do Secretário rodaria a gravação do Pedro, ou o contrário.
 */
let filaDaPonte = Promise.resolve();
export function naPonte(fn) {
    const vez = filaDaPonte.then(fn, fn);
    filaDaPonte = vez.catch(() => {});
    return vez;
}

/**
 * Grava UM atendimento no Gestor (novo, vincular ou reabrir) pela ponte, com
 * --apply (acesso-interno.md, FR-A8; Felipe, 21/10/2026: "sempre ir para o Gestor").
 * Sem nova tentativa: a ponte pode cair DEPOIS de gravar, e repetir abriria dois.
 * Sem a linha de resposta, devolve erro e a equipe confere no Gestor.
 */
export async function gravarNoGestor(config, pedido, { executar = execP } = {}) {
    const [programa, script, arquivoWin, pasta] = String(config || '').split('|');
    if (!programa || !script || !arquivoWin || !pasta) throw new Error('CENTRAL_IA_GESTOR incompleto');
    const nome = 'pedro_gestor_gravar.php';
    const local = join(pasta, nome);
    const dados = Buffer.from(JSON.stringify(pedido), 'utf8').toString('base64');
    writeFileSync(local, readFileSync(join(AQUI, 'suporte_gestor_gravar.php'), 'utf8').replace("'__DADOS__'", `'${dados}'`));
    try {
        const { stdout } = await executar(programa, [script, `${arquivoWin.replace(/[\\/][^\\/]*$/, '')}\\${nome}`, '--apply'],
            { cwd: pasta, timeout: 180000, maxBuffer: 4 * 1024 * 1024 });
        const l = String(stdout).split(/\r?\n/).find(x => x.startsWith('SUPORTE_GESTOR_GRAVADO:'));
        if (!l) return { ok: false, erro: 'a ponte não devolveu resposta — conferir no Gestor antes de repetir' };
        return JSON.parse(l.slice('SUPORTE_GESTOR_GRAVADO:'.length));
    } catch (e) {
        return { ok: false, erro: `a ponte falhou (${String(e.message).slice(0, 120)}) — conferir no Gestor antes de repetir` };
    } finally {
        try { unlinkSync(local); } catch {}
    }
}

/** Os pedidos de gravação no Gestor que o Matriz deixou para esta máquina. @returns {Promise<number>} quantos foram tratados */
export async function gravarAberturas(config, maquina, { api, gravar = gravarNoGestor, log = () => {} }) {
    const r = await api('suporte/gestor/aberturas', { maquina });
    if (r.status !== 200) return { status: r.status, feitos: 0 };
    let feitos = 0;
    for (const a of r.json?.aberturas || []) {
        const g = await naPonte(() => gravar(config, a));
        const envio = await api(`suporte/gestor/aberturas/${a.id}`, { maquina, ...(g.ok ? { numero: String(g.numero), modo: g.modo } : { erro: String(g.erro || 'falhou').slice(0, 300) }) });
        log(`suporte: Gestor ${g.ok ? `${g.modo} nº ${g.numero}` : `não gravou (${g.erro})`} → ${envio.status}`);
        feitos++;
    }
    return { status: 200, feitos };
}
