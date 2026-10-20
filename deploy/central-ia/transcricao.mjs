import { execFile, spawn } from 'node:child_process';
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

/** Quanto tempo entre dois avisos de progresso ao AlfaMatriz. */
export const INTERVALO_PROGRESSO_MS = 15000;

/**
 * Roda o container lendo o stderr linha a linha (o progresso) enquanto junta o
 * stdout (o resultado). Mesmo contrato do execFile: rejeita com stdout e killed.
 */
function rodar(cmd, args, { timeoutMs, maxBuffer, aoLerLinha }) {
    return new Promise((resolve, reject) => {
        const filho = spawn(cmd, args, { stdio: ['ignore', 'pipe', 'pipe'] });
        let stdout = '';
        let resto = '';
        let killed = false;
        const relogio = setTimeout(() => { killed = true; filho.kill('SIGKILL'); }, timeoutMs);
        filho.stdout.on('data', d => { if (stdout.length < maxBuffer) stdout += d; });
        filho.stderr.on('data', d => {
            const linhas = (resto + d).split('\n');
            resto = linhas.pop();
            linhas.forEach(l => aoLerLinha(l));
        });
        filho.on('error', e => { clearTimeout(relogio); reject(Object.assign(e, { stdout, killed })); });
        filho.on('close', codigo => {
            clearTimeout(relogio);
            if (codigo === 0 && !killed) resolve({ stdout });
            else reject(Object.assign(new Error(`saiu com ${codigo}`), { stdout, killed }));
        });
    });
}

/**
 * Baixa, confere, transcreve e devolve o corpo para `transcricoes/{id}`.
 * `aoProgredir(percentual, duracao)` é chamado no máximo a cada
 * INTERVALO_PROGRESSO_MS, com o quanto do áudio já foi transcrito.
 * Nunca lança: a falha volta como `{status: 'falhou', erro}` — o servidor
 * apaga o áudio nos dois casos.
 */
export async function transcrever(job, cfg, pastaBase, { docker = 'docker', timeoutMs = 90 * 60 * 1000, buscar = fetch, aoProgredir = () => {}, intervaloMs = INTERVALO_PROGRESSO_MS } = {}) {
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
        let avisadoEm = 0;
        let avisado = -1;
        const aoLerLinha = linha => {
            try {
                const p = JSON.parse(linha);
                const duracao = Number(p.duracao);
                if (!(duracao > 0) || !Number.isFinite(Number(p.progresso))) return;
                const percentual = Math.max(0, Math.min(99, Math.floor(Number(p.progresso) * 100 / duracao)));
                if (percentual === avisado || Date.now() - avisadoEm < intervaloMs) return;
                avisado = percentual;
                avisadoEm = Date.now();
                aoProgredir(percentual, duracao);
            } catch {}
        };
        try {
            const { stdout } = await rodar(docker, ['run', '--rm', '--pull=never', '--network=none', '--read-only', '--cap-drop=ALL',
                // Núcleos configuráveis na máquina: mais núcleos, transcrição mais rápida.
                '--security-opt=no-new-privileges', '--memory=3g', `--cpus=${Math.max(1, Math.min(16, Number(process.env.CENTRAL_IA_WHISPER_CPUS) || 2))}`, '--pids-limit=256',
                '--user', `${process.getuid?.() ?? 1000}:${process.getgid?.() ?? 1000}`, '--tmpfs', '/tmp:rw,noexec,nosuid,size=256m',
                '-e', 'HOME=/tmp', '--mount', `type=bind,src=${dir},dst=/media,readonly`,
                IMAGEM_WHISPER, 'python', '/app/transcrever_reuniao.py', `/media/${arquivo}`],
            { timeoutMs, maxBuffer: 32 * 1024 * 1024, aoLerLinha });
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

/**
 * Áudio curto do WhatsApp para o Pedro (docs/specs/suporte-pedro-whatsapp/estagiario.md,
 * Fase 2): o mesmo container sem rede das reuniões, com prazo curto — o cliente
 * está esperando. Devolve o texto, ou null (o Pedro pede para o cliente escrever).
 */
export async function transcreverAudioCurto(bytes, extensao, pastaBase, { docker = 'docker', timeoutMs = 75_000 } = {}) {
    if (!EXTENSOES.has(extensao) || !bytes?.length || bytes.length > MAX) return null;
    const dir = mkdtempSync(join(pastaBase, 'pedro-audio-'));
    try {
        const arquivo = `audio.${extensao}`;
        writeFileSync(join(dir, arquivo), bytes, { mode: 0o644 });
        let saida;
        try {
            ({ stdout: saida } = await rodar(docker, ['run', '--rm', '--pull=never', '--network=none', '--read-only', '--cap-drop=ALL',
                '--security-opt=no-new-privileges', '--memory=3g', `--cpus=${Math.max(1, Math.min(16, Number(process.env.CENTRAL_IA_WHISPER_CPUS) || 2))}`, '--pids-limit=256',
                '--user', `${process.getuid?.() ?? 1000}:${process.getgid?.() ?? 1000}`, '--tmpfs', '/tmp:rw,noexec,nosuid,size=256m',
                '-e', 'HOME=/tmp', '--mount', `type=bind,src=${dir},dst=/media,readonly`,
                IMAGEM_WHISPER, 'python', '/app/transcrever_reuniao.py', `/media/${arquivo}`],
            { timeoutMs, maxBuffer: 4 * 1024 * 1024, aoLerLinha: () => {} }));
        } catch {
            return null;
        }
        const json = JSON.parse(String(saida).trim().split('\n').pop());
        const texto = (json.trechos || []).map(t => String(t.texto || '').trim()).filter(Boolean).join(' ').slice(0, 4000);
        return texto || null;
    } catch {
        return null;
    } finally {
        rmSync(dir, { recursive: true, force: true });
    }
}
