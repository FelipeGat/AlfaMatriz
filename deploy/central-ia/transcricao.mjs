import { execFile } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { createHash } from 'node:crypto';
import { promisify } from 'node:util';

/**
 * Transcrição das reuniões do Secretário NA MÁQUINA da pessoa
 * (docs/specs/secretario-reunioes). O áudio vem do AlfaMatriz com o token do
 * runner, é conferido pelo sha256 e transcrito num container sem rede
 * (faster-whisper, modelo dentro da imagem). Nada sai daqui além dos trechos.
 */
const exec = promisify(execFile);
export const IMAGEM_WHISPER = 'alfamatriz-central-whisper:local';
const MAX = 20 * 1024 * 1024;
const EXTENSOES = new Set(['mp3', 'wav', 'm4a', 'ogg', 'opus', 'oga', 'webm']);

/** A imagem existe nesta máquina? Sem ela, o runner nem reserva reunião. */
export async function whisperInstalado(docker = 'docker') {
    try {
        await exec(docker, ['image', 'inspect', IMAGEM_WHISPER], { timeout: 15000 });
        return true;
    } catch {
        return false;
    }
}

/**
 * Baixa, confere, transcreve e devolve o corpo para `transcricoes/{id}`.
 * Nunca lança: a falha volta como `{status: 'falhou', erro}` — o servidor
 * apaga o áudio nos dois casos.
 */
export async function transcrever(job, cfg, pastaBase, { docker = 'docker', timeoutMs = 90 * 60 * 1000, buscar = fetch } = {}) {
    if (!/^\d+$/.test(String(job.id)) || !EXTENSOES.has(job.extensao) || !/^[a-f0-9]{64}$/.test(job.sha256 || '')) {
        return { status: 'falhou', erro: 'pedido de transcrição inválido' };
    }
    const dir = mkdtempSync(join(pastaBase, 'secretario-reuniao-'));
    try {
        // Reconstrói a rota na origem configurada: o token nunca vai a URL do payload.
        const r = await buscar(`${cfg.url}/api/central-ia/runner/transcricoes/${job.id}/audio?maquina=${encodeURIComponent(cfg.maquina)}`, {
            headers: { Authorization: `Bearer ${cfg.token}` }, redirect: 'error', signal: AbortSignal.timeout(120000),
        });
        if (!r.ok) return { status: 'falhou', erro: `não consegui baixar o áudio (HTTP ${r.status})` };
        const bytes = Buffer.from(await r.arrayBuffer());
        if (bytes.length > MAX) return { status: 'falhou', erro: 'áudio acima do limite' };
        if (createHash('sha256').update(bytes).digest('hex') !== job.sha256) return { status: 'falhou', erro: 'o áudio não confere (sha256)' };
        const arquivo = `original.${job.extensao}`;
        writeFileSync(join(dir, arquivo), bytes, { mode: 0o644 });

        let saida;
        try {
            const { stdout } = await exec(docker, ['run', '--rm', '--pull=never', '--network=none', '--read-only', '--cap-drop=ALL',
                // Núcleos configuráveis na máquina: mais núcleos, transcrição mais rápida.
                '--security-opt=no-new-privileges', '--memory=3g', `--cpus=${Math.max(1, Math.min(16, Number(process.env.CENTRAL_IA_WHISPER_CPUS) || 2))}`, '--pids-limit=256',
                '--user', `${process.getuid?.() ?? 1000}:${process.getgid?.() ?? 1000}`, '--tmpfs', '/tmp:rw,noexec,nosuid,size=256m',
                '-e', 'HOME=/tmp', '--mount', `type=bind,src=${dir},dst=/media,readonly`,
                IMAGEM_WHISPER, 'python', '/app/transcrever_reuniao.py', `/media/${arquivo}`],
            { timeout: timeoutMs, maxBuffer: 32 * 1024 * 1024 });
            saida = stdout;
        } catch (e) {
            // O script devolve {"erro": …} com código 1: aproveita o motivo.
            saida = e.stdout || '';
            if (!saida) return { status: 'falhou', erro: e.killed ? 'a transcrição passou do tempo' : 'o transcritor falhou nesta máquina' };
        }
        let json;
        try { json = JSON.parse(String(saida).trim().split('\n').pop()); } catch { return { status: 'falhou', erro: 'resposta ilegível do transcritor' }; }
        if (json.erro || !Array.isArray(json.trechos) || !json.trechos.length) return { status: 'falhou', erro: String(json.erro || 'sem fala').slice(0, 190) };
        const trechos = json.trechos.slice(0, 5000)
            .map(t => ({ inicio: Math.max(0, Number(t.inicio) || 0), fim: Math.max(0, Number(t.fim) || 0), texto: String(t.texto || '').trim().slice(0, 2000) }))
            .filter(t => t.texto)
            .map(t => ({ ...t, fim: Math.max(t.fim, t.inicio) }));
        return trechos.length ? { status: 'ok', duracao: Number(json.duracao) || undefined, trechos } : { status: 'falhou', erro: 'sem fala' };
    } catch (e) {
        return { status: 'falhou', erro: String(e.message || e).slice(0, 190) };
    } finally {
        rmSync(dir, { recursive: true, force: true });
    }
}
