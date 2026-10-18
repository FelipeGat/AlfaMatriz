import { createHash } from 'node:crypto';
import { createReadStream, lstatSync, readdirSync } from 'node:fs';
import { open } from 'node:fs/promises';
import { join, relative, sep } from 'node:path';

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

/** @returns {{nome: string, caminho: string}[]} */
export function configuracao(texto) {
    return String(texto || '').split(';').map(p => p.split('|').map(s => s.trim()))
        .filter(([nome, caminho]) => nome && caminho && !/[\/\\]/.test(nome)).map(([nome, caminho]) => ({ nome: nome.slice(0, 120), caminho }));
}

/** Os arquivos da pasta: relativo com `/`, sem oculto, sem temporário do Office, sem link e sem executável. */
export function listar(raiz, dir = raiz, lista = []) {
    for (const n of readdirSync(dir)) {
        if (n.startsWith('.') || n.startsWith('~$') || n === 'desktop.ini' || n === 'Thumbs.db') continue;
        const c = join(dir, n);
        let st;
        try { st = lstatSync(c); } catch { continue; }
        if (st.isSymbolicLink()) continue;
        if (st.isDirectory()) { listar(raiz, c, lista); continue; }
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
 * @returns {Promise<{enviados: number, pendentes: number, removidos: number, erro?: string}>}
 */
export async function espelhar(pasta, maquina, { api, apiArquivo }) {
    let arquivos;
    try { arquivos = listar(pasta.caminho); } catch (e) { return { enviados: 0, pendentes: 0, removidos: 0, erro: 'pasta não lida: ' + e.code }; }
    const inv = await api('documentos/inventario', { maquina, pasta: pasta.nome, arquivos });
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
    return { enviados, pendentes: Math.max(0, enviar.length - enviados), removidos };
}
