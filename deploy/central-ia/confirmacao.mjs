/**
 * Confirmação fora de banda — o que torna a máquina inviolável pelo servidor.
 *
 * Dono, 12/10/2026: "o runner faz tudo o que o servidor manda — isso não pode
 * ocorrer, tem que ser inviolável". Quem controlasse o AlfaMatriz (ou o banco)
 * criava uma execução e a máquina rodava com acesso total.
 *
 * Agora, antes de qualquer execução que modifica algo, a máquina:
 *  1. calcula um código de 6 dígitos com um SEGREDO que só ela tem
 *     (`CENTRAL_IA_SEGREDO_CONFIRMACAO`, no env local 0600), preso ao conteúdo
 *     exato do pedido (id, agente, projeto, modo, pasta, acesso, instrução);
 *  2. manda o pedido e o código DIRETO ao Telegram do dono — o servidor não
 *     lê o que o bot envia, só o que chega a ele;
 *  3. espera o dono digitar o código (na tela ou no Telegram), confere em
 *     tempo constante e só então executa — exatamente o conteúdo que guardou.
 *
 * Três erros ou 15 minutos sem código: recusa. Tudo fica no log de auditoria
 * local (~/.local/share/alfa/central-ia-auditoria.log), que o servidor também
 * não alcança.
 */
import { createHash, createHmac, timingSafeEqual } from 'node:crypto';
import { appendFileSync, chmodSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { homedir } from 'node:os';
import { girar } from './limpeza.mjs';

export const VALIDADE_MS = 15 * 60_000;
export const TENTATIVAS = 3;
const INTERVALO_MS = 3000;

const LOG = process.env.CENTRAL_IA_AUDITORIA || join(homedir(), '.local/share/alfa/central-ia-auditoria.log');

export function auditar(evento) {
    try {
        mkdirSync(dirname(LOG), { recursive: true });
        // Passou do limite, gira (T-378): a auditoria guarda as últimas
        // cópias em vez de crescer sem fim.
        girar(LOG);
        const novo = !existsSync(LOG);
        appendFileSync(LOG, JSON.stringify({ em: new Date().toISOString(), ...evento }) + '\n');
        if (novo) chmodSync(LOG, 0o600);
    } catch {}
}

/** O que a pessoa confirma: trocar qualquer campo invalida o código. */
export function canonico(job, cwd, acesso) {
    return JSON.stringify({
        id: job.id, agente: job.agente, projeto: job.projeto, modo: job.modo, cwd, acesso,
        instrucao: job.instrucao, anexos: (job.anexos || []).map(a => a.id).sort(),
    });
}

export function codigo(segredo, conteudo) {
    const h = createHmac('sha256', Buffer.from(segredo, 'hex')).update(conteudo).digest();
    return String(h.readUInt32BE(0) % 1_000_000).padStart(6, '0');
}

export function confere(esperado, digitado) {
    const a = Buffer.from(String(esperado)), b = Buffer.from(String(digitado ?? ''));
    return a.length === b.length && timingSafeEqual(a, b);
}

export function segredoValido(segredo) {
    return typeof segredo === 'string' && /^[0-9a-f]{64}$/i.test(segredo);
}

/**
 * Chats já confirmados nesta máquina (vale 12 h): a regra "inicio" não pede o
 * código de novo no mesmo chat.
 *
 * A chave é servidor + conta + chat (T-368). Era só o número do chat, e o
 * número se repete entre servidores e contas: o chat 5 confirmado no staging
 * liberou o chat 5 da produção, de outra conta, e o pedido alterou arquivos sem
 * pedir código. Faltando qualquer das três partes não há chave, e sem chave
 * nada fica liberado. As entradas do formato antigo (só o número) nunca casam
 * com uma chave nova: quem confirmou antes desta versão confirma de novo.
 */
export const VALIDADE_CHAT_MS = 12 * 3600_000;

export function chaveDoChat(servidor, conta, chat) {
    if (!servidor || conta == null || conta === '' || chat == null || chat === '') return null;
    return `${String(servidor).replace(/\/+$/, '')}|${conta}|${chat}`;
}

export function chatsConfirmados(arquivo, agora = Date.now()) {
    try {
        return Object.fromEntries(Object.entries(JSON.parse(readFileSync(arquivo, 'utf8')))
            .filter(([chave, ate]) => chave.includes('|') && ate > agora));
    } catch { return {}; }
}

export function chatConfirmado(arquivo, chave, agora = Date.now()) {
    return !!chave && !!chatsConfirmados(arquivo, agora)[chave];
}

export function marcarChatConfirmado(arquivo, chave, agora = Date.now()) {
    if (!chave) return;
    try {
        mkdirSync(dirname(arquivo), { recursive: true });
        writeFileSync(arquivo, JSON.stringify({ ...chatsConfirmados(arquivo, agora), [chave]: agora + VALIDADE_CHAT_MS }), { mode: 0o600 });
    } catch {}
}

/** A mensagem que a pessoa lê no celular: o pedido EXATO, não um resumo do servidor. */
export function mensagem(job, cwd, acesso, cod) {
    const instrucao = job.instrucao.length > 1500 ? job.instrucao.slice(0, 1500) + '…' : job.instrucao;
    return [
        `🔐 Confirmação do pedido #${job.id} — sua máquina, não o servidor`,
        `${job.agente} vai ${job.modo === 'implementar' ? 'MODIFICAR arquivos' : job.modo} em ${job.projeto || 'sua máquina'}`,
        `Pasta: ${cwd}`,
        `Acesso: ${acesso}`,
        '',
        instrucao,
        '',
        `Código: ${cod}`,
        `Vale 15 min. Se foi você, digite o código na Central ou responda aqui: /confirmar ${cod}`,
        'Se NÃO foi você, não digite nada: o pedido é recusado sozinho.',
    ].join('\n');
}

/**
 * Manda o texto ao Telegram do dono. Falha de REDE tenta de novo (3 vezes):
 * em 13/10/2026 o "fetch failed" intermitente até o api.telegram.org fez um
 * pedido cair como "Telegram não configurado". Recusa do Telegram não repete.
 */
export async function enviarTelegram(cfg, texto, { tentativas = 3, dormir = ms => new Promise(r => setTimeout(r, ms)), buscar = fetch } = {}) {
    for (let i = 1; ; i++) {
        let r;
        try {
            r = await buscar(`https://api.telegram.org/bot${cfg.telegramToken}/sendMessage`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ chat_id: cfg.telegramChat, text: texto, link_preview_options: { is_disabled: true } }),
                signal: AbortSignal.timeout(15000),
            });
        } catch (e) {
            if (i >= tentativas) throw e;
            await dormir(2000 * i);
            continue;
        }
        const j = await r.json().catch(() => ({}));
        if (!j.ok) throw new Error(`Telegram recusou (${r.status})`);
        return;
    }
}

/**
 * Pede e espera a confirmação. Devolve `{ ok, motivo }`. `api` é a porta do
 * runner no AlfaMatriz; `dormir` é injetável para o teste.
 */
export async function confirmar({ job, cwd, acesso, cfg, api, enviar = enviarTelegram, dormir = ms => new Promise(r => setTimeout(r, ms)), agora = () => Date.now(), parar = () => false }) {
    const resumo = `${job.agente} vai ${job.modo === 'implementar' ? 'modificar arquivos' : job.modo} em ${job.projeto || 'sua máquina'}`;
    const informar = async (estado, tentativas, extra = {}) => {
        try {
            const r = await api(`execucoes/${job.id}/confirmacao`, { maquina: cfg.maquina, estado, tentativas, resumo, ...extra });
            return r.status === 200 ? (r.json?.codigo ?? null) : null;
        } catch { return null; }
    };

    if (!segredoValido(cfg.segredo) || !cfg.telegramToken || !cfg.telegramChat) {
        auditar({ execucao: job.id, decisao: 'sem_canal', agente: job.agente, projeto: job.projeto, modo: job.modo });
        await informar('sem_canal', 0);
        return { ok: false, motivo: 'a máquina não tem como pedir confirmação (segredo ou Telegram não configurados no env local)' };
    }

    const esperado = codigo(cfg.segredo, canonico(job, cwd, acesso));
    const hash = createHash('sha256').update(canonico(job, cwd, acesso)).digest('hex').slice(0, 16);
    try {
        await enviar(cfg, mensagem(job, cwd, acesso, esperado));
    } catch (e) {
        auditar({ execucao: job.id, decisao: 'sem_canal', erro: String(e.message).slice(0, 120), hash });
        await informar('sem_canal', 0);
        return { ok: false, motivo: 'não foi possível mandar o código ao Telegram do dono' };
    }

    const expira = agora() + VALIDADE_MS;
    auditar({ execucao: job.id, decisao: 'pedida', agente: job.agente, projeto: job.projeto, modo: job.modo, cwd, acesso, hash });
    let tentativas = 0;
    let digitado = await informar('aguardando', 0, { expira_em: new Date(expira).toISOString() });
    while (agora() < expira && !parar()) {
        if (digitado !== null) {
            if (confere(esperado, digitado)) {
                auditar({ execucao: job.id, decisao: 'confirmada', hash, tentativas });
                await informar('confirmada', tentativas);
                return { ok: true };
            }
            tentativas++;
            auditar({ execucao: job.id, decisao: 'codigo_errado', hash, tentativas });
            if (tentativas >= TENTATIVAS) {
                auditar({ execucao: job.id, decisao: 'recusada', hash, tentativas });
                await informar('recusada', tentativas);
                return { ok: false, motivo: 'três códigos errados; pedido recusado pela máquina' };
            }
            digitado = await informar('codigo_errado', tentativas);
            continue;
        }
        await dormir(INTERVALO_MS);
        digitado = await informar('aguardando', tentativas);
    }
    auditar({ execucao: job.id, decisao: 'expirada', hash, tentativas });
    await informar('expirada', tentativas);
    return { ok: false, motivo: parar() ? 'runner parado durante a confirmação' : 'o código não foi digitado em 15 minutos; pedido recusado pela máquina' };
}
