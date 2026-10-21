import { createHash } from 'node:crypto';
import { execFile } from 'node:child_process';
import { copyFileSync, createReadStream, existsSync, mkdtempSync, readFileSync, lstatSync, mkdirSync, readdirSync, realpathSync, renameSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { open } from 'node:fs/promises';
import { dirname, extname, join, relative, resolve, sep } from 'node:path';
import { tmpdir } from 'node:os';
import { promisify } from 'node:util';

/**
 * Pastas de documentos espelhadas do OneDrive (docs/specs/documentos-espelhados):
 * a máquina lista a pasta, pergunta ao AlfaMatriz o que subir e envia em partes.
 * Configuração: `CENTRAL_IA_DOCUMENTOS="Nome|caminho;Outro nome|outro caminho"`
 * no `.env` do runner — caminho de máquina nenhum mora neste código (T-374).
 */
export const BLOQUEADAS = new Set(['exe', 'bat', 'cmd', 'ps1', 'sh', 'php', 'js', 'msi', 'dll', 'com', 'scr', 'vbs', 'jar']);
export const MAX_BYTES = 200 * 1024 * 1024;
/** Arquivos enviados por rodada: o resto vai na próxima, sem segurar o runner. */
export const POR_RODADA = 40;
/** Miniaturas de PDF e vídeo geradas por rodada (cada uma é um container). */
export const MINIATURAS_POR_RODADA = 20;
const IMAGEM_MIDIA = 'alfamatriz-central-media:local';
const exec = promisify(execFile);

/**
 * Converte dentro da imagem de mídia (ffmpeg + poppler), sem rede e sem
 * privilégio, como os anexos: o arquivo do cliente é conteúdo não confiável.
 */
async function converterNoDocker(dir, comando, args) {
    return exec('docker', ['run', '--rm', '--pull=never', '--network=none', '--read-only', '--cap-drop=ALL',
        '--security-opt=no-new-privileges', '--memory=512m', '--cpus=1', '--pids-limit=128',
        '--user', `${process.getuid?.() ?? 1000}:${process.getgid?.() ?? 1000}`, '--tmpfs', '/tmp:rw,noexec,nosuid,size=128m',
        '--mount', `type=bind,src=${dir},dst=/media`, IMAGEM_MIDIA, comando, ...args], { timeout: 120000, maxBuffer: 1024 * 1024 });
}

export async function midiaInstalada() {
    try { await exec('docker', ['image', 'inspect', IMAGEM_MIDIA], { timeout: 15000 }); return true; } catch { return false; }
}

/**
 * A miniatura de um PDF (primeira página) ou vídeo (um quadro perto do 1º
 * segundo), em JPEG de até 480 px. Null se não deu.
 */
export async function gerarMiniatura(local, { converter = converterNoDocker } = {}) {
    const ext = extname(local).slice(1).toLowerCase();
    const dir = mkdtempSync(join(tmpdir(), 'miniatura-'));
    try {
        copyFileSync(local, join(dir, `original.${ext}`));
        if (ext === 'pdf') {
            await converter(dir, 'pdftoppm', ['-f', '1', '-l', '1', '-singlefile', '-scale-to', '480', '-jpeg', '/media/original.pdf', '/media/mini']);
        } else {
            const quadro = ss => converter(dir, 'ffmpeg', ['-nostdin', '-v', 'error', '-y', '-ss', ss, '-i', `/media/original.${ext}`, '-frames:v', '1', '-vf', 'scale=480:-2', '/media/mini.jpg']);
            // Vídeo menor que 1 s não tem quadro no 1º segundo: tenta o primeiro.
            await quadro('1').catch(() => quadro('0'));
            if (!existsSync(join(dir, 'mini.jpg'))) await quadro('0');
        }
        const f = join(dir, 'mini.jpg');
        return existsSync(f) ? readFileSync(f) : null;
    } catch {
        return null;
    } finally {
        rmSync(dir, { recursive: true, force: true });
    }
}

/** @returns {{nome: string, caminho: string}[]} */
export function configuracao(texto) {
    return String(texto || '').split(';').map(p => p.split('|').map(s => s.trim()))
        .filter(([nome, caminho]) => nome && caminho && !/[\/\\]/.test(nome)).map(([nome, caminho]) => ({ nome: nome.slice(0, 120), caminho }));
}

/**
 * Os arquivos da pasta: relativo com `/`, sem oculto, sem temporário do Office, sem link e sem executável.
 * Em `pastas`, se passada, ficam todas as subpastas — as vazias inclusive (documentos-mao-dupla FR-006).
 */
export function listar(raiz, dir = raiz, lista = [], pastas = null) {
    for (const n of readdirSync(dir)) {
        if (n.startsWith('.') || n.startsWith('~$') || n === 'desktop.ini' || n === 'Thumbs.db') continue;
        const c = join(dir, n);
        let st;
        try { st = lstatSync(c); } catch { continue; }
        if (st.isSymbolicLink()) continue;
        if (st.isDirectory()) { pastas?.push(relative(raiz, c).split(sep).join('/')); listar(raiz, c, lista, pastas); continue; }
        if (!st.isFile()) continue;
        const ext = (n.includes('.') ? n.split('.').pop() : '').toLowerCase();
        if (BLOQUEADAS.has(ext) || st.size > MAX_BYTES) continue;
        lista.push({ caminho: relative(raiz, c).split(sep).join('/'), tamanho: st.size, modificado: st.mtime.toISOString() });
    }
    return lista;
}

export async function sha256(arquivo) {
    const h = createHash('sha256');
    for await (const b of createReadStream(arquivo)) h.update(b);
    return h.digest('hex');
}

/**
 * Uma rodada de uma pasta. `api(caminho, corpo)` devolve {status, json};
 * `apiArquivo(caminho, campos, conteudo, nome)` devolve o status.
 * @returns {Promise<{enviados: number, pendentes: number, removidos: number, miniaturas?: number, erro?: string}>}
 */
export async function espelhar(pasta, maquina, { api, apiArquivo, ...opcoes }) {
    let arquivos;
    const pastas = [];
    try { arquivos = listar(pasta.caminho, pasta.caminho, [], pastas); } catch (e) { return { enviados: 0, pendentes: 0, removidos: 0, erro: 'pasta não lida: ' + e.code }; }
    const inv = await api('documentos/inventario', { maquina, pasta: pasta.nome, arquivos, pastas });
    if (inv.status !== 200 || !inv.json) return { enviados: 0, pendentes: 0, removidos: 0, erro: 'inventário → ' + inv.status };
    const { pasta_id: id, enviar = [], removidos = 0, parte_bytes: parteBytes = 8 * 1024 * 1024 } = inv.json;
    const porCaminho = new Map(arquivos.map(a => [a.caminho, a]));
    let enviados = 0;
    for (const caminho of enviar.slice(0, POR_RODADA)) {
        const a = porCaminho.get(caminho);
        if (!a) continue;
        const local = join(pasta.caminho, ...caminho.split('/'));
        const hash = await sha256(local);
        const partes = Math.max(1, Math.ceil(a.tamanho / parteBytes));
        const fd = await open(local, 'r');
        let ok = true;
        try {
            for (let i = 0; i < partes; i++) {
                const tamanho = Math.min(parteBytes, a.tamanho - i * parteBytes);
                const buf = Buffer.alloc(Math.max(0, tamanho));
                if (tamanho > 0) await fd.read(buf, 0, tamanho, i * parteBytes);
                const st = await apiArquivo(`documentos/${id}/partes`, { maquina, caminho, tamanho: String(a.tamanho), modificado: a.modificado,
                    sha256: hash, parte: String(i), partes: String(partes) }, buf, 'parte.bin');
                if (st !== 200) { ok = false; break; }
            }
        } finally { await fd.close(); }
        if (ok) enviados++;
    }
    // Miniaturas que o Matriz pediu (PDF e vídeo): sem a imagem de mídia, fica para depois.
    let miniaturas = 0;
    const pedidas = (inv.json.miniaturas || []).filter(c => porCaminho.has(c)).slice(0, MINIATURAS_POR_RODADA);
    if (pedidas.length && await (opcoes.midiaInstalada || midiaInstalada)()) {
        for (const caminho of pedidas) {
            const local = join(pasta.caminho, ...caminho.split('/'));
            const hash = await sha256(local);
            const jpeg = await gerarMiniatura(local, { converter: opcoes.converter });
            const st = jpeg ? await apiArquivo(`documentos/${id}/miniaturas`, { maquina, caminho, sha256: hash }, jpeg, 'miniatura.jpg')
                : (await api(`documentos/${id}/miniaturas`, { maquina, caminho, sha256: hash })).status;
            if (st === 200 && jpeg) miniaturas++;
        }
    }
    return { enviados, pendentes: Math.max(0, enviar.length - enviados), removidos, miniaturas };
}

/**
 * O caminho de uma alteração, DENTRO da raiz (documentos-mao-dupla FR-002): sem
 * `..`, sem absoluto, sem a própria raiz, sem passar por link e sem executável.
 * Devolve o caminho absoluto, ou null se recusado.
 */
export function dentroDaRaiz(raiz, caminho) {
    const c = String(caminho || '');
    const partes = c.split('/');
    if (!c || c.length > 600 || c.startsWith('/') || /^[A-Za-z]:/.test(c) || /[\x00-\x1F\\]/.test(c)
        || partes.some(p => p === '' || p === '.' || p === '..')) return null;
    if (BLOQUEADAS.has(extname(c).slice(1).toLowerCase())) return null;
    const base = realpathSync(raiz);
    const alvo = resolve(base, ...partes);
    if (!alvo.startsWith(base + sep)) return null;
    // Nenhum pedaço do caminho que já existe pode ser link (sairia da raiz por ele).
    let atual = base;
    for (const p of partes) {
        atual = join(atual, p);
        try { if (lstatSync(atual).isSymbolicLink()) return null; } catch { break; }
    }
    return alvo;
}

/** "orcamento.pdf" já existe? Vira "orcamento (2).pdf", "(3)"… (FR-004). */
function nomeLivre(alvo) {
    if (!existsSync(alvo)) return alvo;
    const ext = extname(alvo);
    const base = alvo.slice(0, alvo.length - ext.length);
    for (let i = 2; i < 1000; i++) {
        const c = `${base} (${i})${ext}`;
        if (!existsSync(c)) return c;
    }
    throw new Error('nomes esgotados');
}

/**
 * Aplica no OneDrive as alterações pedidas pelo Matriz e devolve o resultado de
 * cada uma. `baixar(id)` devolve o Buffer do arquivo enviado.
 * @returns {Promise<number>} quantas foram aplicadas (com sucesso ou falha)
 */
export async function aplicarAlteracoes(pastasConfig, maquina, { api, baixar, log = () => {} }) {
    const r = await api('documentos/operacoes', { maquina });
    if (r.status !== 200 || !Array.isArray(r.json?.operacoes)) return 0;
    let n = 0;
    for (const op of r.json.operacoes) {
        const pasta = pastasConfig.find(p => p.nome === op.pasta);
        if (!pasta) continue;
        let res;
        try {
            res = await aplicarUma(pasta.caminho, op, baixar);
        } catch (e) {
            res = { situacao: 'falhou', erro: String(e.code || e.message || e).slice(0, 190) };
        }
        const envio = await api(`documentos/operacoes/${op.id}`, { maquina, ...res });
        log(`Documentos (${pasta.nome}): ${op.tipo} ${op.caminho} → ${res.situacao}${res.erro ? ' (' + res.erro + ')' : ''} · ${envio.status}`);
        n++;
    }
    return n;
}

async function aplicarUma(raiz, op, baixar) {
    const alvo = dentroDaRaiz(raiz, op.caminho);
    if (!alvo) return { situacao: 'falhou', erro: 'caminho recusado pela máquina' };
    if (op.tipo === 'criar_pasta') {
        if (existsSync(alvo) && !statSync(alvo).isDirectory()) return { situacao: 'falhou', erro: 'já existe um arquivo com esse nome' };
        mkdirSync(alvo, { recursive: true });
        return { situacao: 'feita' };
    }
    if (op.tipo === 'apagar') {
        // Na pasta do OneDrive, apagar manda para a lixeira online (30 dias).
        rmSync(alvo, { recursive: true, force: true });
        return { situacao: 'feita' };
    }
    if (op.tipo === 'mover') {
        const destino = dentroDaRaiz(raiz, op.destino);
        if (!destino) return { situacao: 'falhou', erro: 'destino recusado pela máquina' };
        if (!existsSync(alvo)) return { situacao: 'falhou', erro: 'não existe mais no OneDrive' };
        if (existsSync(destino)) return { situacao: 'falhou', erro: 'o destino já existe' };
        mkdirSync(dirname(destino), { recursive: true });
        renameSync(alvo, destino);
        return { situacao: 'feita' };
    }
    if (op.tipo === 'enviar') {
        const bytes = await baixar(op.id);
        if (bytes.length !== Number(op.tamanho)) return { situacao: 'falhou', erro: 'o arquivo chegou com outro tamanho' };
        if (createHash('sha256').update(bytes).digest('hex') !== op.sha256) return { situacao: 'falhou', erro: 'o arquivo não confere (sha256)' };
        mkdirSync(dirname(alvo), { recursive: true });
        const final = nomeLivre(alvo);
        // Grava com nome oculto e renomeia: o OneDrive (e o espelho) nunca veem o arquivo pela metade.
        const temp = join(dirname(final), `.matriz-${op.id}.part`);
        writeFileSync(temp, bytes);
        renameSync(temp, final);
        const rel = relative(realpathSync(raiz), final).split(sep).join('/');
        return { situacao: 'feita', caminho_final: rel, modificado: statSync(final).mtime.toISOString() };
    }
    return { situacao: 'falhou', erro: 'alteração desconhecida' };
}
