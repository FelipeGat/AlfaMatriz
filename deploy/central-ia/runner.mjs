#!/usr/bin/env node
/**
 * Runner da Central de IA — roda na máquina de cada pessoa, com o login de IA
 * DELA (Claude Code, Codex, Copilot), e trabalha nos projetos DELA.
 *
 * O AlfaMatriz não executa nada: ele guarda o pedido e este programa vem
 * buscar (`/api/central-ia/runner/proxima`), roda o agente na pasta do
 * projeto e devolve a resposta (`…/conclusao`). Nenhuma credencial de IA sai
 * daqui; o token do AlfaMatriz não é repassado ao agente.
 *
 * Sem dependências: Node 18+ (fetch nativo). Configuração em
 * ~/.config/alfa/central-ia-runner.env — ver deploy/central-ia/README.md.
 */
import { spawn, execFileSync } from 'node:child_process';
import { existsSync, readFileSync, readdirSync, statSync, mkdtempSync, rmSync, mkdirSync, realpathSync } from 'node:fs';
import { homedir, hostname, tmpdir } from 'node:os';
import { join, resolve, sep } from 'node:path';

const HOME = homedir();
const CONFIG = process.env.CENTRAL_IA_RUNNER_CONFIG || join(HOME, '.config/alfa/central-ia-runner.env');

function lerConfig() {
    const env = {};
    if (existsSync(CONFIG)) {
        for (const linha of readFileSync(CONFIG, 'utf8').split('\n')) {
            const m = linha.match(/^\s*([A-Z_]+)\s*=\s*(.*?)\s*$/);
            if (m) env[m[1]] = m[2].replace(/^["']|["']$/g, '');
        }
    }
    const v = k => process.env[k] ?? env[k];
    const config = {
        url: (v('ALFAMATRIZ_URL') || 'http://localhost:8096').replace(/\/$/, ''),
        token: v('ALFAMATRIZ_RUNNER_TOKEN'),
        projetos: (v('CENTRAL_IA_PROJETOS') || join(HOME, 'projetos')).replace(/^~/, HOME),
        maquina: v('CENTRAL_IA_MAQUINA') || hostname().replace(/[^A-Za-z0-9._-]/g, '-').slice(0, 64),
    };
    // Worktrees das execuções de implementar: dentro da pasta de projetos,
    // num diretório com ponto — que a listagem de projetos ignora.
    config.worktrees = join(config.projetos, '.central-ia', 'worktrees');
    if (!config.token) throw new Error(`ALFAMATRIZ_RUNNER_TOKEN ausente (${CONFIG}).`);
    return config;
}

const cfg = lerConfig();
const log = (...a) => console.log(new Date().toISOString(), ...a);

/* ---------- agentes instalados ---------- */

function noPath(nome) {
    for (const dir of (process.env.PATH || '').split(':')) {
        const p = join(dir, nome);
        if (dir && existsSync(p)) return p;
    }
    return null;
}

// As extensões do VS Code trazem os binários numa pasta com a versão no nome,
// e a pasta muda a cada atualização: procura a mais nova a cada batida.
function maisNovo(prefixo, relativo) {
    const base = join(HOME, '.vscode-server/extensions');
    if (!existsSync(base)) return null;
    const achados = readdirSync(base).filter(d => d.startsWith(prefixo)).map(d => join(base, d, relativo)).filter(existsSync);
    achados.sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
    return achados.at(-1) || null;
}

function localizarAgentes() {
    return {
        claude: noPath('claude') || maisNovo('anthropic.claude-code-', 'resources/native-binary/claude'),
        codex: noPath('codex') || maisNovo('openai.chatgpt-', 'bin/linux-x86_64/codex'),
        copilot: noPath('copilot') || (existsSync(join(HOME, '.local/bin/copilot')) ? join(HOME, '.local/bin/copilot') : null),
    };
}

function listarProjetos() {
    return readdirSync(cfg.projetos)
        .filter(n => /^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(n) && n.length <= 128)
        .filter(n => { try { return statSync(join(cfg.projetos, n)).isDirectory(); } catch { return false; } })
        .sort();
}

/* ---------- AlfaMatriz ---------- */

async function api(caminho, corpo) {
    const r = await fetch(`${cfg.url}/api/central-ia/runner/${caminho}`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${cfg.token}`, Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify(corpo),
        signal: AbortSignal.timeout(20000),
    });
    const texto = await r.text();
    let json = null;
    try { json = texto ? JSON.parse(texto) : null; } catch {}
    return { status: r.status, json };
}

let agentes = {};

async function batida() {
    agentes = localizarAgentes();
    const r = await api('batida', {
        maquina: cfg.maquina,
        agentes: Object.keys(agentes).filter(k => agentes[k]),
        projetos: listarProjetos(),
    });
    if (r.status !== 200) log('batida recusada', r.status, r.json?.message || '');
}

/* ---------- execução ---------- */

function git(cwd, ...args) {
    return execFileSync('git', ['-C', cwd, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
}

/**
 * Implementar trabalha numa worktree com branch própria: o que já está
 * pendente na pasta do projeto, e qualquer sessão aberta nela, não é tocado.
 * Pedido seguinte ao mesmo agente continua na mesma branch, se ela existe.
 */
function prepararWorktree(projeto, dir, job) {
    if (git(dir, 'rev-parse', '--is-inside-work-tree') !== 'true') throw new Error('o projeto não é um repositório git; implementar exige git');
    const anterior = job.continuar?.branch;
    if (anterior) {
        const wt = join(cfg.worktrees, `${projeto}-${anterior.split('/').pop()}`);
        if (existsSync(wt)) return { cwd: wt, branch: anterior };
    }
    mkdirSync(cfg.worktrees, { recursive: true });
    const branch = `central-ia/${job.id}`;
    const wt = join(cfg.worktrees, `${projeto}-${job.id}`);
    git(dir, 'worktree', 'add', '-b', branch, wt, 'HEAD');
    return { cwd: wt, branch };
}

function instrucoes(job, branch) {
    const base = [
        'Você está atendendo um pedido feito pela Central de IA do AlfaMatriz. Responda em português do Brasil.',
        `Projeto: ${job.projeto}. Siga as instruções do próprio projeto (CLAUDE.md, AGENTS.md, README).`,
    ];
    if (job.modo === 'implementar') {
        base.push(`Você está numa worktree própria, na branch ${branch}. Faça as alterações pedidas aqui e rode os testes que fizerem sentido.`,
            'NÃO faça commit, push, merge, reset nem checkout; não mexa em pastas fora desta; não rode deploy nem toque em produção.',
            'No fim, resuma o que mudou, por quê e como testar.');
    } else {
        base.push('Modo ANALISAR: só leia e responda. Não altere nenhum arquivo nem rode comandos que mudem estado.');
    }
    return base.join('\n');
}

const NEGADOS_NO_SHELL = ['git push', 'git commit', 'git reset', 'git checkout', 'git switch', 'git rebase', 'git merge', 'rm -rf', 'sudo', 'docker', 'ssh'];

function comando(job, cwd, branch, saida) {
    const sessao = job.continuar?.sessao || null;
    const prompt = `${instrucoes(job, branch)}\n\nPedido:\n${job.instrucao}`;
    const escreve = job.modo === 'implementar';

    if (job.agente === 'claude') {
        const args = ['-p', prompt, '--output-format', 'json'];
        if (sessao) args.push('--resume', sessao);
        if (escreve) {
            args.push('--permission-mode', 'acceptEdits', '--allowedTools', 'Read', 'Grep', 'Glob', 'Edit', 'Write', 'Bash',
                '--disallowedTools', ...NEGADOS_NO_SHELL.map(c => `Bash(${c}:*)`));
        } else {
            args.push('--permission-mode', 'default', '--allowedTools', 'Read', 'Grep', 'Glob', 'WebSearch', 'WebFetch',
                '--disallowedTools', 'Edit', 'Write', 'NotebookEdit', 'Bash');
        }
        return [agentes.claude, args];
    }

    if (job.agente === 'codex') {
        const sandbox = `sandbox_mode="${escreve ? 'workspace-write' : 'read-only'}"`;
        const comum = ['--json', '-o', saida, '--skip-git-repo-check', '-c', sandbox, '-c', 'approval_policy="never"'];
        return [agentes.codex, sessao ? ['exec', 'resume', sessao, ...comum, prompt] : ['exec', ...comum, prompt]];
    }

    // Copilot: no modo não interativo ele exige --allow-all-tools; o controle
    // fica no --deny-tool. A sessão leva um nome para ser retomada por ele.
    const nome = sessao || `central-ia-${job.id}`;
    const args = ['-s', '--no-color', '--allow-all-tools', '--add-dir', cwd];
    if (escreve) for (const c of NEGADOS_NO_SHELL) args.push('--deny-tool', `shell(${c})`);
    else args.push('--deny-tool', 'write', '--deny-tool', 'shell');
    args.push(...(sessao ? ['--resume', sessao] : ['-n', nome]), '-p', prompt);
    return [agentes.copilot, args, nome];
}

function rodar(bin, args, cwd, minutos) {
    return new Promise(resolvePromise => {
        const env = { ...process.env };
        delete env.ALFAMATRIZ_RUNNER_TOKEN;
        const filho = spawn(bin, args, { cwd, env, stdio: ['ignore', 'pipe', 'pipe'], detached: true });
        let out = '', err = '', estourou = false;
        filho.stdout.on('data', d => { out += d; if (out.length > 4_000_000) out = out.slice(-4_000_000); });
        filho.stderr.on('data', d => { err += d; if (err.length > 200_000) err = err.slice(-200_000); });
        const prazo = setTimeout(() => {
            estourou = true;
            try { process.kill(-filho.pid, 'SIGTERM'); } catch {}
            setTimeout(() => { try { process.kill(-filho.pid, 'SIGKILL'); } catch {} }, 10000);
        }, minutos * 60_000);
        filho.on('error', e => { clearTimeout(prazo); resolvePromise({ codigo: -1, out, err: String(e.message), estourou }); });
        filho.on('close', codigo => { clearTimeout(prazo); resolvePromise({ codigo, out, err, estourou }); });
    });
}

function interpretar(job, r, saida, nomeCopilot) {
    if (job.agente === 'claude') {
        const linha = r.out.trim().split('\n').reverse().find(l => l.startsWith('{'));
        const j = linha ? JSON.parse(linha) : null;
        if (!j || j.is_error) throw new Error(j?.result || r.err.trim().split('\n').at(-1) || `saiu com código ${r.codigo}`);
        return { resposta: j.result, sessao: j.session_id };
    }
    if (job.agente === 'codex') {
        let sessao = job.continuar?.sessao || null;
        for (const l of r.out.split('\n')) {
            try { const e = JSON.parse(l); if (e.type === 'thread.started' && e.thread_id) sessao = e.thread_id; } catch {}
        }
        const resposta = existsSync(saida) ? readFileSync(saida, 'utf8').trim() : '';
        if (r.codigo !== 0 && !resposta) throw new Error(r.err.trim().split('\n').at(-1) || `saiu com código ${r.codigo}`);
        return { resposta, sessao };
    }
    if (r.codigo !== 0 && !r.out.trim()) throw new Error(r.err.trim().split('\n').at(-1) || `saiu com código ${r.codigo}`);
    return { resposta: r.out.trim(), sessao: nomeCopilot };
}

function resumoDaBranch(cwd, branch) {
    try {
        const status = git(cwd, 'status', '--short');
        const stat = git(cwd, 'diff', '--stat');
        if (!status) return `\n\n— Nenhum arquivo alterado na branch ${branch}.`;
        return `\n\n— Alterações na branch ${branch} (worktree ${cwd}), sem commit:\n${status}${stat ? `\n${stat.split('\n').at(-1)}` : ''}`;
    } catch { return ''; }
}

async function executar(job) {
    const inicio = Date.now();
    log(`#${job.id} ${job.agente} ${job.projeto} ${job.modo}`);
    let resultado;
    const tmp = mkdtempSync(join(tmpdir(), 'central-ia-'));
    try {
        if (!agentes[job.agente]) throw new Error(`${job.agente} não está instalado nesta máquina`);
        const dir = realpathSync(resolve(cfg.projetos, job.projeto));
        if (!dir.startsWith(realpathSync(cfg.projetos) + sep)) throw new Error('projeto fora da pasta de projetos');

        let cwd = dir, branch = null;
        if (job.modo === 'implementar') ({ cwd, branch } = prepararWorktree(job.projeto, dir, job));

        const saida = join(tmp, 'ultima.txt');
        const [bin, args, nomeCopilot] = comando(job, cwd, branch, saida);
        const r = await rodar(bin, args, cwd, job.timeout_minutos);
        if (r.estourou) throw new Error(`passou de ${job.timeout_minutos} minutos e foi encerrado`);

        const { resposta, sessao } = interpretar(job, r, saida, nomeCopilot);
        resultado = {
            status: 'concluida',
            resposta: (resposta || '(o agente não escreveu resposta)') + (branch ? resumoDaBranch(cwd, branch) : ''),
            sessao_agente: sessao || null,
            branch,
        };
    } catch (e) {
        resultado = { status: 'falhou', erro: String(e.message || e).slice(0, 1900) };
    } finally {
        rmSync(tmp, { recursive: true, force: true });
    }

    // A conclusão é idempotente no AlfaMatriz: repetir depois de uma queda
    // não dobra a resposta.
    for (let tentativa = 1; tentativa <= 5; tentativa++) {
        try {
            const r = await api(`execucoes/${job.id}/conclusao`, resultado);
            if (r.status === 200) break;
            log(`#${job.id} conclusão recusada`, r.status);
            if (r.status < 500) break;
        } catch (e) { log(`#${job.id} conclusão falhou (${e.message}), tentando de novo`); }
        await new Promise(r => setTimeout(r, 5000 * tentativa));
    }
    log(`#${job.id} ${resultado.status} em ${Math.round((Date.now() - inicio) / 1000)} s`);
}

/* ---------- laço ---------- */

let parar = false;
process.on('SIGTERM', () => { parar = true; });
process.on('SIGINT', () => { parar = true; });

log(`runner ${cfg.maquina} → ${cfg.url}, projetos em ${cfg.projetos}`);
// A batida corre à parte do laço: durante uma execução longa a máquina
// continua aparecendo como online na tela.
const batendo = () => batida().catch(e => log('batida falhou:', e.message));
await batendo();
const relogio = setInterval(batendo, 30_000);
while (!parar) {
    try {
        const r = await api('proxima', { maquina: cfg.maquina });
        if (r.status === 200 && r.json?.id) { await executar(r.json); continue; }
        if (r.status === 401 || r.status === 403) log('token recusado ou conta sem permissão', r.status);
    } catch (e) {
        log('AlfaMatriz indisponível:', e.message);
    }
    await new Promise(r => setTimeout(r, 3000));
}
clearInterval(relogio);
log('runner encerrado');
