import { execFile } from 'node:child_process';
import { readFileSync, unlinkSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

/**
 * Pendências do Gestor para o Secretário (docs/specs/secretario-emails-gestor):
 * a máquina roda o script de LEITURA pela ponte de produção que o dono já usa
 * (`CENTRAL_IA_GESTOR="programa|ponte|script|pasta"`, no `.env` do runner da
 * pessoa — caminho de máquina nenhum mora neste código, T-374). A `pasta` é o
 * diretório de trabalho da ponte e o lado WSL da pasta do script, opcional. Nunca passa `--apply`. A ponte em si não desfaz nada: quem garante a
 * leitura é o script (recusa APPLY=1, sessão READ ONLY, transação desfeita).
 */
const exec = promisify(execFile);
const AQUI = dirname(fileURLToPath(import.meta.url));
/**
 * A ponte roda uma CÓPIA do script instalado, gravada a cada leitura na pasta
 * do script configurado (como o `suporte.mjs` faz com o do Pedro). Rodar o
 * arquivo configurado direto deixava a leitura parada na versão em que alguém
 * o copiou à mão: o instalador atualiza a pasta da máquina, não a da ponte, e
 * a leitura de 31/10/2026 (contas a pagar com valor) não chegou a rodar.
 */
export const COPIA = 'secretario_gestor_ler.php';
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
    const lista = l => (Array.isArray(l) ? l : []).slice(0, 10).map(i => ({ texto: String(i.texto ?? '').slice(0, 200),
        ...(typeof i.data === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(i.data) ? { data: i.data } : {}),
        ...(typeof i.valor === 'number' ? { valor: i.valor } : {}) })).filter(i => i.texto);
    // Quantidade e valor de uma parte do bloco (as contas a pagar vencidas e as de hoje).
    const parte = p => (Number.isInteger(p?.quantidade) ? { quantidade: p.quantidade, ...(typeof p.valor === 'number' ? { valor: p.valor } : {}) } : null);
    for (const [chave, b] of Object.entries(blocos)) {
        if (!BLOCOS.has(chave) || !Number.isInteger(b?.quantidade)) return null;
        limpo[chave] = { quantidade: b.quantidade, ...(typeof b.valor === 'number' ? { valor: b.valor } : {}), itens: lista(b.itens) };
        // Os campos novos (docs/specs/pedro-comunicacao, plan.md seção 3): só passam no formato esperado.
        if (Array.isArray(b.maiores)) limpo[chave].maiores = lista(b.maiores);
        for (const nome of ['vencidas', 'hoje']) {
            if (parte(b[nome])) limpo[chave][nome] = parte(b[nome]);
        }
    }
    return limpo;
}

/** @returns {Promise<{ok: true, blocos: object} | {ok: false, erro: string}>} */
export async function lerGestor(config, { executar = exec, origem = AQUI, ler = readFileSync, escrever = writeFileSync, apagar = unlinkSync } = {}) {
    const [programa, ponte, script, pasta] = String(config || '').split('|');
    if (!programa || !ponte || !script) return { ok: false, erro: 'CENTRAL_IA_GESTOR incompleto' };
    // A `pasta` é o lado WSL da pasta do script: é nela que a cópia é gravada. Sem
    // ela não há onde copiar, e a ponte roda o script configurado, como antes.
    let alvo = script;
    let local = null;
    if (pasta) {
        const i = Math.max(script.lastIndexOf('\\'), script.lastIndexOf('/'));
        alvo = i >= 0 ? script.slice(0, i + 1) + COPIA : COPIA;
        try {
            local = join(pasta, COPIA);
            escrever(local, ler(join(origem, 'secretario_gestor.php'), 'utf8'));
        } catch (e) {
            // Sem a cópia, nada roda: rodar o arquivo velho seria o defeito de volta.
            return { ok: false, erro: 'não deu para copiar o script para a ponte — ' + String(e.message).slice(0, 160) };
        }
    }
    try {
        // Ponte que roda pelo Python do Windows precisa de cwd numa pasta do
        // Windows, senão resolve o da WSL como UNC e falha: por isso a `pasta`.
        // Só dois argumentos — nunca --apply.
        const { stdout } = await executar(programa, [ponte, alvo], {
            cwd: pasta || undefined, timeout: 180000, maxBuffer: 4 * 1024 * 1024 });
        const blocos = lerSaida(stdout);
        return blocos ? { ok: true, blocos } : { ok: false, erro: 'saída da ponte sem o retrato' };
    } catch (e) {
        // O motivo vai ao log da máquina (código e a última linha de erro), nunca ao servidor.
        const motivo = [e.code, String(e.stderr || '').trim().split(/\r?\n/).pop()].filter(Boolean).join(' · ').slice(0, 200);
        return { ok: false, erro: (e.killed ? 'a ponte passou do tempo' : 'a ponte falhou') + (motivo ? ' — ' + motivo : '') };
    } finally {
        if (local) try { apagar(local); } catch {}
    }
}

/** De hora em hora, e uma vez na janela antes do briefing (06:30–07:00). */
export function hora(ultimo, agora = new Date()) {
    if (!ultimo) return true;
    const passou = agora - ultimo;
    const min = agora.getHours() * 60 + agora.getMinutes();
    return passou >= 3600_000 || (min >= 390 && min < 420 && passou >= 20 * 60_000);
}
