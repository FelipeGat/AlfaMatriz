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
// Ler arquivo é pela ferramenta Read, que respeita SENSIVEIS; cat/grep/find
// no shell passavam por cima (revisão de segurança, 13/10/2026).
export const LEITURA_NO_SHELL = ['git log', 'git status', 'git diff', 'git show', 'git blame', 'git rev-parse', 'ls', 'wc', 'tree',
    'docker ps', 'docker logs', 'docker inspect', 'date', 'pwd'];

// Nem na leitura: credenciais da máquina.
export const SENSIVEIS = ['~/.ssh/**', '~/.config/alfa/**', '~/.aws/**', '~/.codex/auth.json', '~/.claude/.credentials.json', '~/.copilot/**', '**/.env', '**/.env.*'];

// O Codex só leitura não tem negação de leitura por caminho: um pedido de
// análise que cita credenciais não vai a ele.
export const CITA_CREDENCIAL = /\.ssh\b|id_(?:rsa|ed25519|ecdsa)\b|\.aws\/|(?:^|[\s/'"`])\.env\b|central-ia-runner\.env|\.config\/alfa|\.credentials\.json|auth\.json/i;

/**
 * O acesso que vale de fato: analisar é sempre leitura; implementar é o
 * MENOR entre o que o servidor pediu e o teto desta máquina.
 */
export function acessoEfetivo(job, maximo, analise = 'leitura') {
    // Analisar: a máquina decide (CENTRAL_IA_ANALISE). Dono, 12/10/2026:
    // "quem tem que analisar o que consulta e altera é a própria IA" — com
    // `total`, a análise roda como no VS Code e o rastro fica na auditoria.
    if (job.modo !== 'implementar') return analise === 'total' && maximo === 'total' ? 'total' : 'leitura';
    return job.acesso === 'total' && maximo === 'total' ? 'total' : 'restrito';
}

/** Caminho dentro da raiz (ou ela mesma), com links resolvidos. */
export function dentroDe(raiz, caminho) {
    try {
        const r = realpathSync(raiz), c = realpathSync(caminho);
        return c === r || c.startsWith(r + sep);
    } catch { return false; }
}

/**
 * Quando a máquina pede o código (dono, 13/10/2026: "só no início do chat e
 * em caso de deploy; ficar pedindo toda hora é bem ruim"), do mais frouxo ao
 * mais rígido:
 *  - nunca:       não pede (escolha de quem não quer o Telegram) — EXCETO deploy;
 *  - deploy:      só pedido de deploy, produção, push ou publicação;
 *  - inicio:      deploy + a primeira implementação de cada chat;
 *  - implementar: deploy + toda implementação;
 *  - sempre:      todo pedido, até análise.
 */
export const REGRAS_DE_CONFIRMACAO = ['nunca', 'deploy', 'inicio', 'implementar', 'sempre'];

/** A regra que vale: a da pessoa (Ajustes), nunca mais frouxa que o mínimo da máquina. */
export function regraEfetiva(minimoDaMaquina, daPessoa) {
    const i = r => Math.max(0, REGRAS_DE_CONFIRMACAO.indexOf(r));
    return REGRAS_DE_CONFIRMACAO[Math.max(i(minimoDaMaquina), REGRAS_DE_CONFIRMACAO.includes(daPessoa) ? i(daPessoa) : 0)];
}

export const PEDE_DEPLOY = /\b(deploy|deplo[iy]|produ[çc][ãa]o|publi(car|que|ca[çc][ãa]o)|push|tag\s+v\d|release|subir\s+(pra|para)\s+o?\s*ar|merge\s+(na|para\s+a)\s+main)\b/i;

/** Precisa do código do dono antes de rodar? `chatConfirmado`: já confirmou neste chat. */
export function exigeConfirmacao(job, regra, chatConfirmado = false) {
    // Deploy, produção, push e publicação pedem o código SEMPRE, em qualquer
    // regra (dono, 13/10/2026: "nem deploy sem autenticação de dois fatores,
    // que é a mensagem no Telegram"). Máquina sem Telegram não executa.
    if (PEDE_DEPLOY.test(job.instrucao || '')) return true;
    if (regra === 'nunca') return false;
    if (regra === 'sempre') return true;
    if (regra === 'deploy') return false;
    if (job.modo !== 'implementar') return false;
    return regra === 'implementar' || !chatConfirmado;
}

/** Argumentos de permissão de cada CLI para o acesso efetivo. */
export function permissoes(agente, acesso, { projetos, cwd } = {}) {
    const total = acesso === 'total', escreve = acesso !== 'leitura';
    if (agente === 'claude') {
        if (total) return ['--permission-mode', 'bypassPermissions', '--add-dir', projetos];
        if (escreve) return ['--permission-mode', 'acceptEdits', '--allowedTools', 'Read', 'Grep', 'Glob', 'Edit', 'Write', 'Bash',
            '--disallowedTools', ...NEGADOS_NO_SHELL.map(c => `Bash(${c}:*)`), ...SENSIVEIS.map(p => `Read(${p})`)];
        // Sem WebFetch na leitura: era o canal para mandar um arquivo lido para fora.
        return ['--permission-mode', 'default', '--allowedTools', 'Read', 'Grep', 'Glob', 'WebSearch',
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

const NOME_DE_MODELO = /^[A-Za-z0-9._-]{1,60}$/;
const ESFORCOS = ['low', 'medium', 'high'];

/**
 * Modelo e esforço do nível de custo (dono, 12/10/2026: agenda não precisa do
 * modelo mais caro). Nome estranho é ignorado: vira argumento de comando.
 */
export function modeloArgs(agente, modelo, esforco) {
    const m = typeof modelo === 'string' && NOME_DE_MODELO.test(modelo) ? modelo : null;
    const e = ESFORCOS.includes(esforco) ? esforco : null;
    if (agente === 'claude') return [...(m ? ['--model', m] : []), ...(e ? ['--effort', e] : [])];
    if (agente === 'codex') return [...(m ? ['-m', m] : []), ...(e ? ['-c', `model_reasoning_effort="${e}"`] : [])];
    // Copilot em "auto" recusa esforço ("Model auto does not support reasoning
    // effort", 13/10/2026): o esforço só vai com modelo escolhido.
    return [...(m ? ['--model', m] : []), ...(e && m && m !== 'auto' ? ['--reasoning-effort', e] : [])];
}

/**
 * "Sem IA": a lista da agenda é a resposta — nenhuma IA precisa ler. Agrupa
 * por dia quando a linha começa com a data (formato da ponte gcal.py).
 */
export function agendaSemIa(rotulo, inicio, bruto) {
    const linhas = String(bruto || '').split('\n').map(l => l.trim()).filter(Boolean);
    const br = d => `${d.slice(8, 10)}/${d.slice(5, 7)}`;
    const cab = `📅 Agenda — ${rotulo}${/^\d{4}-\d{2}-\d{2}$/.test(inicio) ? ` (a partir de ${br(inicio)})` : ''}`;
    if (!linhas.length) return `${cab}\n\nNenhum compromisso no período.\n\n_Lido agora da sua Google Agenda, sem IA._`;
    const dias = new Map();
    for (const l of linhas) {
        const m = l.match(/^(\d{4}-\d{2}-\d{2})\s+(.*)$/);
        const dia = m ? br(m[1]) : '';
        (dias.get(dia) ?? dias.set(dia, []).get(dia)).push(m ? m[2] : l);
    }
    const corpo = [...dias].map(([dia, itens]) => (dia ? `**${dia}**\n` : '') + itens.map(i => `- ${i}`).join('\n')).join('\n\n');
    return `${cab}\n\n${corpo}\n\n_${linhas.length} compromisso(s), lido(s) agora da sua Google Agenda, sem IA._`;
}

/**
 * Regra do dono para QUALQUER IA que implemente em projeto (13/10/2026: "quem
 * for fazer a implementação tem que passar pelo cstk"). Espelha o ~/.claude/
 * CLAUDE.md global — que só o Claude lê sozinho; Codex e Copilot não leem nem
 * têm os skills do cstk, então não implementam frente nova: devolvem ao Claude.
 * Sem projeto (chat de assunto nas pastas de ~/projetos, propostas, planilhas)
 * não se aplica: não é código de repositório.
 */
export function regraCstk(agente, projeto) {
    if (!projeto) return null;
    const comum = [
        '## Regra obrigatória do dono: SDD com cstk',
        'Antes de escrever código novo, confira se existem docs/briefing.md e docs/constitution.md no projeto.',
        'Frente NOVA (feature, módulo, integração) só se implementa com docs/specs/<feature>/{spec,plan,tasks}.md prontos; a implementação segue as tarefas de tasks.md.',
        'Correção pontual de bug num projeto que já tem esses documentos, e ajuste de layout, texto ou configuração, não exigem o pipeline inteiro.',
    ];
    if (agente === 'claude') {
        return [...comum,
            'Você tem os skills do cstk: frente nova sem spec → rode o pipeline (/cstk:briefing e /cstk:constitution se faltarem; depois /cstk:specify → /cstk:clarify → /cstk:plan → /cstk:create-tasks → /cstk:execute-task). Bug pontual → /cstk:bugfix.',
        ].join('\n');
    }
    return [...comum,
        'Você NÃO tem os skills do cstk. Se o pedido for frente nova e o projeto não tiver a spec dela em docs/specs/, NÃO implemente: explique em uma linha e devolva no bloco final "delegar": {"agente": "claude", "modo": "implementar", "pedido": "<o pedido original>, passando pelo pipeline do cstk"}. Correção pontual ou ajuste: pode seguir.',
    ].join('\n');
}
