import { execFile } from 'node:child_process';
import { promisify } from 'node:util';

/**
 * Pendências do Gestor para o Secretário (docs/specs/secretario-emails-gestor):
 * a máquina roda o script de LEITURA pela ponte de produção que o dono já usa
 * (`CENTRAL_IA_GESTOR="programa|ponte|script|pasta"`, no `.env` do runner da
 * pessoa — caminho de máquina nenhum mora neste código, T-374). A `pasta` é o
 * diretório de trabalho da ponte, opcional. Nunca passa `--apply`. A ponte em si não desfaz nada: quem garante a
 * leitura é o script (recusa APPLY=1, sessão READ ONLY, transação desfeita).
 */
const exec = promisify(execFile);
export const MARCA = 'SECRETARIO_GESTOR:';
const BLOCOS = new Set(['orcamentos_aprovacao', 'orcamentos_elaboracao', 'cobrancas_vencidas', 'cobrancas_hoje', 'pagar_vencendo', 'reunioes_proximo_passo', 'funil_parado']);

/** Lê a linha marcada da saída da ponte. Formato fora do esperado = null (o servidor também confere). */
export function lerSaida(saida) {
    const linha = String(saida).split(/\r?\n/).find(l => l.startsWith(MARCA));
    if (!linha) return null;
    let blocos;
    try { blocos = JSON.parse(linha.slice(MARCA.length)); } catch { return null; }
    if (!blocos || typeof blocos !== 'object' || Array.isArray(blocos)) return null;
    const limpo = {};
    for (const [chave, b] of Object.entries(blocos)) {
        if (!BLOCOS.has(chave) || !Number.isInteger(b?.quantidade)) return null;
        limpo[chave] = { quantidade: b.quantidade, ...(typeof b.valor === 'number' ? { valor: b.valor } : {}),
            itens: (Array.isArray(b.itens) ? b.itens : []).slice(0, 10).map(i => ({ texto: String(i.texto ?? '').slice(0, 200),
                ...(typeof i.data === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(i.data) ? { data: i.data } : {}),
                ...(typeof i.valor === 'number' ? { valor: i.valor } : {}) })).filter(i => i.texto) };
    }
    return limpo;
}

/** @returns {Promise<{ok: true, blocos: object} | {ok: false, erro: string}>} */
export async function lerGestor(config, { executar = exec } = {}) {
    const [programa, ponte, script, pasta] = String(config || '').split('|');
    if (!programa || !ponte || !script) return { ok: false, erro: 'CENTRAL_IA_GESTOR incompleto' };
    try {
        // Ponte que roda pelo Python do Windows precisa de cwd numa pasta do
        // Windows, senão resolve o da WSL como UNC e falha: por isso a `pasta`.
        // Só dois argumentos — nunca --apply.
        const { stdout } = await executar(programa, [ponte, script], {
            cwd: pasta || undefined, timeout: 180000, maxBuffer: 4 * 1024 * 1024 });
        const blocos = lerSaida(stdout);
        return blocos ? { ok: true, blocos } : { ok: false, erro: 'saída da ponte sem o retrato' };
    } catch (e) {
        return { ok: false, erro: (e.killed ? 'a ponte passou do tempo' : 'a ponte falhou') };
    }
}

/** De hora em hora, e uma vez na janela antes do briefing (06:30–07:00). */
export function hora(ultimo, agora = new Date()) {
    if (!ultimo) return true;
    const passou = agora - ultimo;
    const min = agora.getHours() * 60 + agora.getMinutes();
    return passou >= 3600_000 || (min >= 390 && min < 420 && passou >= 20 * 60_000);
}
