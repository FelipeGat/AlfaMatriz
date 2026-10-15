/**
 * O que a máquina apaga sozinha (T-378, prazos aceitos pelo dono em
 * 14/10/2026). Antes nada era apagado: o log do runner, a auditoria local e
 * as worktrees das tarefas cresciam sem limite.
 *
 *  - Log do runner (só no Mac: no Linux e no WSL ele vai para o journal, que
 *    tem limite próprio) e auditoria: passou do tamanho, gira. Guarda as
 *    últimas cópias (`.1`, `.2`), não apaga a história de uma vez.
 *  - Worktree da tarefa: só sai se a tarefa estiver ENCERRADA no AlfaMatriz e
 *    a pasta não tiver nada que só exista ali. Os agentes não commitam: a
 *    worktree pode ser o único lugar do trabalho feito. Qualquer dúvida —
 *    git que falha, main que não se acha, alteração, commit fora da main —
 *    e ela fica.
 */
import { chmodSync, copyFileSync, existsSync, readdirSync, renameSync, statSync, truncateSync } from 'node:fs';
import { join } from 'node:path';

export const LIMITE_LOG_BYTES = 10 * 1024 * 1024;
export const COPIAS_LOG = 2;

/**
 * Gira `caminho` quando passa de `limite`. `copiar` é para o arquivo que
 * outro processo mantém aberto (o log do runner, aberto pelo launchd em modo
 * de acréscimo — conferido com lsof em 14/10/2026): copia e esvazia no
 * lugar, e a escrita seguinte recomeça do início. Renomear deixaria o
 * launchd escrevendo na cópia. Devolve se girou.
 */
export function girar(caminho, { limite = LIMITE_LOG_BYTES, copias = COPIAS_LOG, copiar = false } = {}) {
    let st;
    try { st = statSync(caminho); } catch { return false; }
    if (st.size <= limite) return false;
    for (let i = copias - 1; i >= 1; i--) {
        if (existsSync(`${caminho}.${i}`)) renameSync(`${caminho}.${i}`, `${caminho}.${i + 1}`);
    }
    if (copiar) {
        copyFileSync(caminho, `${caminho}.1`);
        truncateSync(caminho, 0);
    } else {
        renameSync(caminho, `${caminho}.1`);
    }
    // A cópia guarda o mesmo que o original: a auditoria é 0600, e continua.
    chmodSync(`${caminho}.1`, st.mode & 0o777);
    return true;
}

/** O nome que o runner dá à worktree da tarefa: `<projeto>-t<id>`. */
export function lerNomeDaWorktree(nome) {
    const m = /^(.+)-t(\d+)$/.exec(nome);
    return m ? { projeto: m[1], tarefa: Number(m[2]) } : null;
}

/**
 * Por que esta worktree NÃO pode sair (texto), ou null se pode. `git` é o
 * do runner: (cwd, ...args) => saída, e lança se o git falha.
 */
export function motivoParaFicar(git, wt) {
    try {
        // Inclui os arquivos novos: o que o agente criou e ninguém adicionou.
        if (git(wt, 'status', '--porcelain', '--untracked-files=all')) return 'tem alteração pendente';
        const head = git(wt, 'rev-parse', 'HEAD');
        const mains = ['main', 'origin/main', 'master', 'origin/master'].filter(ref => {
            try { git(wt, 'rev-parse', '--verify', '--quiet', `${ref}^{commit}`); return true; } catch { return false; }
        });
        if (!mains.length) return 'não achei a main do projeto';
        const naMain = mains.some(ref => {
            try { git(wt, 'merge-base', '--is-ancestor', head, ref); return true; } catch { return false; }
        });
        return naMain ? null : 'tem commit fora da main';
    } catch (e) {
        return `git falhou (${String(e.message).split('\n')[0]})`;
    }
}

/**
 * Passa pelas worktrees da pasta e remove as das tarefas encerradas que não
 * guardam nada. `encerradas` vem do AlfaMatriz (a batida): ids das tarefas
 * desta conta concluídas ou arquivadas. `emCurso`: tarefas rodando agora
 * nesta máquina, que ficam de qualquer jeito. Devolve o que fez, para o log.
 */
export function limparWorktrees({ git, worktrees, projetos, encerradas, emCurso = new Set() }) {
    const feitas = [];
    let nomes;
    try { nomes = readdirSync(worktrees, { withFileTypes: true }).filter(d => d.isDirectory()).map(d => d.name); } catch { return feitas; }
    const fechadas = new Set(encerradas.map(Number));
    for (const nome of nomes) {
        const id = lerNomeDaWorktree(nome);
        if (!id || !fechadas.has(id.tarefa) || emCurso.has(id.tarefa)) continue;
        const wt = join(worktrees, nome);
        const motivo = motivoParaFicar(git, wt);
        if (motivo) { feitas.push({ worktree: wt, removida: false, motivo }); continue; }
        try {
            // Sem --force: se algo mudou entre a conferência e aqui, o próprio
            // git recusa, e a pasta fica.
            git(join(projetos, id.projeto), 'worktree', 'remove', wt);
            feitas.push({ worktree: wt, removida: true });
        } catch (e) {
            feitas.push({ worktree: wt, removida: false, motivo: `git recusou (${String(e.message).split('\n')[0]})` });
        }
    }
    return feitas;
}
