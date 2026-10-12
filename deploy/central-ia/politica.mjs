/**
 * O que cada agente pode fazer nesta máquina — decidido AQUI, nunca pelo
 * servidor (dono, 12/10/2026: "tem que ser inviolável").
 *
 * Módulo puro (sem efeito ao importar), para o teste conferir os argumentos
 * gerados para cada CLI, em cada modo e acesso.
 */
import { realpathSync } from 'node:fs';
import { sep } from 'node:path';

export const NEGADOS_NO_SHELL = ['git push', 'git commit', 'git reset', 'git checkout', 'git switch', 'git rebase', 'git merge', 'rm -rf', 'sudo', 'docker', 'ssh'];

// Analisar é SÓ LEITURA, imposto pela ferramenta (dono, 12/10/2026: "análise
// é para averiguar, não modificar"). Os comandos de leitura liberados ao
// Claude; o resto do Bash é negado no modo -p.
export const LEITURA_NO_SHELL = ['git log', 'git status', 'git diff', 'git show', 'git branch', 'git blame', 'git rev-parse', 'ls', 'cat', 'head', 'tail',
    'wc', 'grep', 'rg', 'find', 'tree', 'docker ps', 'docker logs', 'docker inspect', 'date', 'pwd'];

// Nem na leitura: credenciais da máquina.
export const SENSIVEIS = ['~/.ssh/**', '~/.config/alfa/**', '~/.aws/**', '~/.codex/auth.json', '~/.claude/.credentials.json', '~/.copilot/**', '**/.env', '**/.env.*'];

// O Codex só leitura não tem negação de leitura por caminho: um pedido de
// análise que cita credenciais não vai a ele.
export const CITA_CREDENCIAL = /\.ssh\b|id_(?:rsa|ed25519|ecdsa)\b|\.aws\/|(?:^|[\s/'"`])\.env\b|central-ia-runner\.env|\.config\/alfa|\.credentials\.json|auth\.json/i;

/**
 * O acesso que vale de fato: analisar é sempre leitura; implementar é o
 * MENOR entre o que o servidor pediu e o teto desta máquina.
 */
export function acessoEfetivo(job, maximo) {
    if (job.modo !== 'implementar') return 'leitura';
    return job.acesso === 'total' && maximo === 'total' ? 'total' : 'restrito';
}

/** Caminho dentro da raiz (ou ela mesma), com links resolvidos. */
export function dentroDe(raiz, caminho) {
    try {
        const r = realpathSync(raiz), c = realpathSync(caminho);
        return c === r || c.startsWith(r + sep);
    } catch { return false; }
}

/** Precisa do código do dono antes de rodar? */
export function exigeConfirmacao(job, regra) {
    return job.modo === 'implementar' || regra === 'sempre';
}

/** Argumentos de permissão de cada CLI para o acesso efetivo. */
export function permissoes(agente, acesso, { projetos, cwd } = {}) {
    const total = acesso === 'total', escreve = acesso !== 'leitura';
    if (agente === 'claude') {
        if (total) return ['--permission-mode', 'bypassPermissions', '--add-dir', projetos];
        if (escreve) return ['--permission-mode', 'acceptEdits', '--allowedTools', 'Read', 'Grep', 'Glob', 'Edit', 'Write', 'Bash',
            '--disallowedTools', ...NEGADOS_NO_SHELL.map(c => `Bash(${c}:*)`), ...SENSIVEIS.map(p => `Read(${p})`)];
        return ['--permission-mode', 'default', '--allowedTools', 'Read', 'Grep', 'Glob', 'WebSearch', 'WebFetch',
            ...LEITURA_NO_SHELL.map(c => `Bash(${c}:*)`),
            '--disallowedTools', 'Edit', 'Write', 'NotebookEdit', ...SENSIVEIS.map(p => `Read(${p})`)];
    }
    if (agente === 'codex') {
        return ['-c', `sandbox_mode="${total ? 'danger-full-access' : (escreve ? 'workspace-write' : 'read-only')}"`, '-c', 'approval_policy="never"'];
    }
    // Copilot: no -p ele exige --allow-all-tools; quem segura é o --deny-tool,
    // que vence. Na leitura, sem escrita e sem shell nenhum.
    const args = ['--add-dir', cwd];
    if (total) return [...args, '--allow-all'];
    args.push('--allow-all-tools');
    if (escreve) for (const c of NEGADOS_NO_SHELL) args.push('--deny-tool', `shell(${c})`);
    else args.push('--deny-tool', 'write', '--deny-tool', 'shell');
    return args;
}
