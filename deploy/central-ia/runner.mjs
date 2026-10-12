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
import { closeSync, existsSync, openSync, readFileSync, readSync, readdirSync, statSync, mkdtempSync, rmSync, mkdirSync, realpathSync } from 'node:fs';
import { homedir, hostname, tmpdir } from 'node:os';
import { join, resolve, sep } from 'node:path';
import { randomUUID } from 'node:crypto';
import { usoLocal } from './uso-local.mjs';
import { prepararAnexos } from './anexos.mjs';
import { acompanharProgresso } from './progresso.mjs';
import { auditar, confirmar } from './confirmacao.mjs';
import { acessoEfetivo, CITA_CREDENCIAL, dentroDe, exigeConfirmacao, permissoes } from './politica.mjs';

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
        // O que a MÁQUINA permite, decidido aqui e não no servidor (dono,
        // 12/10/2026: "tem que ser inviolável"). O servidor pode pedir menos,
        // nunca mais. `total` só vale para implementar, e só confirmado.
        acessoMaximo: v('CENTRAL_IA_ACESSO_MAXIMO') === 'total' ? 'total' : 'restrito',
        // `implementar` (padrão): confirma o que modifica. `sempre`: confirma
        // também as análises.
        confirmar: v('CENTRAL_IA_CONFIRMAR') === 'sempre' ? 'sempre' : 'implementar',
        segredo: v('CENTRAL_IA_SEGREDO_CONFIRMACAO'),
        telegramToken: v('CENTRAL_IA_TELEGRAM_TOKEN'),
        telegramChat: v('CENTRAL_IA_TELEGRAM_CHAT_ID'),
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

/**
 * Sessões de agente abertas agora, por projeto: o processo do Claude, do
 * Codex ou do Copilot cuja pasta de trabalho está dentro de um projeto. O
 * cockpit avisa — mexer na pasta onde alguém está trabalhando é o que a
 * worktree evita, e a pessoa precisa saber que há outra sessão ali.
 */
function sessoesAbertas() {
    const raiz = realpathSync(cfg.projetos) + sep;
    const porProjeto = {};
    for (const pid of readdirSync('/proc').filter(p => /^\d+$/.test(p))) {
        try {
            const cmd = readFileSync(`/proc/${pid}/cmdline`, 'utf8');
            const agente = /native-binary\/claude|\/claude\0/.test(cmd) ? 'claude'
                : /\/codex\0/.test(cmd) ? 'codex' : /copilot/.test(cmd) ? 'copilot' : null;
            if (!agente) continue;
            const cwd = realpathSync(`/proc/${pid}/cwd`);
            if (!cwd.startsWith(raiz)) continue;
            const projeto = cwd.slice(raiz.length).split(sep)[0];
            // As worktrees da própria Central não contam como sessão de alguém.
            if (projeto.startsWith('.')) continue;
            (porProjeto[projeto] ??= new Set()).add(agente);
        } catch {}
    }
    return porProjeto;
}

// Fase e atividade lidas do painel-avanco (porta 8089), quando ele existe —
// só leitura do retrato que ele já gera, sem coletar de novo.
function retratoDoPainel() {
    const arquivo = join(cfg.projetos, 'painel-avanco', 'retrato.json');
    try {
        const r = JSON.parse(readFileSync(arquivo, 'utf8'));
        return Object.fromEntries((r.projetos || []).map(p => [p.nome, { fase: p.fase_inferida?.rotulo ?? p.fase_inferida ?? null, atividade: p.status_atividade?.rotulo ?? p.status_atividade ?? null }]));
    } catch { return {}; }
}

let estadoCache = { em: 0, valor: {} };

function estadoDosProjetos(projetos) {
    if (Date.now() - estadoCache.em < 60_000) return estadoCache.valor;
    const sessoes = sessoesAbertas();
    const painel = retratoDoPainel();
    const valor = {};
    for (const p of projetos) {
        const dir = join(cfg.projetos, p);
        const e = { git: false, sessoes: [...(sessoes[p] || [])] };
        try {
            e.branch = git(dir, 'branch', '--show-current') || null;
            e.git = true;
            e.pendentes = git(dir, 'status', '--porcelain').split('\n').filter(Boolean).length;
            const [quando, msg] = git(dir, 'log', '-1', '--format=%cI%x09%s').split('\t');
            e.ultimo_commit = quando || null;
            e.ultimo_commit_msg = msg || null;
        } catch {}
        const f = painel[p];
        if (f) { e.fase = typeof f.fase === 'string' ? f.fase : null; e.atividade = typeof f.atividade === 'string' ? f.atividade : null; }
        valor[p] = e;
    }
    estadoCache = { em: Date.now(), valor };
    return valor;
}

// Consumo e sessões vêm dos registros locais das IAs; relidos a cada 2 min
// (a leitura é incremental — só o que cresceu desde a última vez).
let usoCache = { em: 0, valor: null };
function uso() {
    if (Date.now() - usoCache.em > 120_000) {
        try { usoCache = { em: Date.now(), valor: usoLocal(realpathSync(cfg.projetos)) }; }
        catch (e) { log('leitura de consumo falhou:', e.message); usoCache.em = Date.now(); }
    }
    return usoCache.valor;
}

/**
 * Limites da assinatura do Claude, pelo `/usage` — comando local da CLI, sem
 * chamada ao modelo (zero tokens) e sem gravar sessão. De 10 em 10 min.
 */
let limitesClaude = { em: 0, valor: null };
function limitesDoClaude() {
    if (!agentes.claude || Date.now() - limitesClaude.em < 600_000) return limitesClaude.valor;
    limitesClaude.em = Date.now();
    try {
        const saida = execFileSync(agentes.claude, ['-p', '/usage', '--output-format', 'json', '--no-session-persistence'],
            { cwd: pastaGeral(), encoding: 'utf8', timeout: 60_000, stdio: ['ignore', 'pipe', 'pipe'] });
        const texto = JSON.parse(saida).result || '';
        const rotulos = { 'Current session': 'Sessão (5 h)', 'Current week (all models)': 'Semana (todos os modelos)' };
        const janelas = [];
        for (const m of texto.matchAll(/^(Current [^:]+):\s*(\d+(?:\.\d+)?)% used(?:\s*·\s*resets\s+(.+))?$/gm)) {
            janelas.push({ rotulo: rotulos[m[1]] || m[1].replace('Current week', 'Semana'), usado_pct: Number(m[2]), renova: m[3]?.trim() || null });
        }
        limitesClaude.valor = janelas.length ? { janelas, em: new Date().toISOString() } : null;
    } catch (e) { log('limites do Claude indisponíveis:', String(e.message).split('\n')[0]); }
    return limitesClaude.valor;
}

async function batida() {
    agentes = localizarAgentes();
    const projetos = listarProjetos();
    const u = uso();
    const r = await api('batida', {
        maquina: cfg.maquina,
        agentes: Object.keys(agentes).filter(k => agentes[k]),
        projetos,
        estado: estadoDosProjetos(projetos),
        ...(u ? { consumo: { ...u.consumo, limites_claude: limitesDoClaude() }, sessoes: u.sessoes } : {}),
    });
    if (r.status !== 200) log('batida recusada', r.status, r.json?.message || '');
}

/* ---------- execução ---------- */

function git(cwd, ...args) {
    return execFileSync('git', ['-C', cwd, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
}

/**
 * A worktree é da TAREFA, não da execução: nasce na primeira vez que alguém
 * implementa e é onde todo agente seguinte trabalha — inclusive outro agente
 * depois de uma troca. O que já está pendente na pasta do projeto, e qualquer
 * sessão aberta nela, não é tocado.
 */
function prepararWorktree(projeto, dir, tarefa) {
    if (git(dir, 'rev-parse', '--is-inside-work-tree') !== 'true') throw new Error('o projeto não é um repositório git; implementar exige git');
    mkdirSync(cfg.worktrees, { recursive: true });
    const branch = tarefa.branch || `central-ia/t${tarefa.id}`;
    const wt = tarefa.worktree || join(cfg.worktrees, `${projeto}-t${tarefa.id}`);
    if (existsSync(wt)) return { cwd: wt, branch, worktree: wt, commit_base: tarefa.commit_base };
    let existe = false;
    try { git(dir, 'rev-parse', '--verify', '--quiet', `refs/heads/${branch}`); existe = true; } catch {}
    const base = git(dir, 'rev-parse', 'HEAD');
    // A branch sobrevive à worktree removida: recria a pasta em cima dela.
    if (existe) git(dir, 'worktree', 'add', wt, branch);
    else git(dir, 'worktree', 'add', '-b', branch, wt, 'HEAD');
    return { cwd: wt, branch, worktree: wt, commit_base: tarefa.commit_base || base };
}

/**
 * O estado real do git ao fim da execução — medido aqui, não relatado. A
 * continuação de uma sessão do VS Code roda na RAIZ ~/projetos, que não é
 * repositório: aí o git medido é o da pasta do projeto.
 */
function checkpoint(cwd, projetoDir = null) {
    const c = { cwd };
    for (const dir of [cwd, projetoDir].filter(Boolean)) {
        try {
            c.branch = git(dir, 'branch', '--show-current') || null;
            c.head = git(dir, 'rev-parse', '--short', 'HEAD');
            const linhas = git(dir, 'status', '--porcelain').split('\n').filter(Boolean);
            c.status = linhas.slice(0, 200).map(l => l.slice(0, 300));
            c.arquivos = linhas.slice(0, 200).map(l => l.slice(3).slice(0, 290));
            c.diff_stat = (git(dir, 'diff', '--stat').split('\n').at(-1) || '').slice(0, 290);
            break;
        } catch {}
    }
    return c;
}

function pastaGeral() {
    const dir = join(HOME, '.local/share/alfa/central-ia-geral');
    mkdirSync(dir, { recursive: true });
    return dir;
}

/**
 * Dados oficiais pedidos pela Central (ver FontesDeDados no AlfaMatriz): lidos
 * AQUI, do mesmo jeito para qualquer agente, e entregues no prompt com a
 * regra de usar só eles. Era o que fazia cada IA trazer uma agenda diferente:
 * cada uma procurava num lugar, e o Codex, isolado, nem alcança a ponte.
 */
function dadosOficiais(fontes) {
    const blocos = [];
    for (const f of fontes) {
        if (f.tipo !== 'agenda') continue;
        const [python, script] = (process.env.CENTRAL_IA_AGENDA || '/mnt/c/Python314/python.exe|C:\\tmp\\gcal\\gcal.py').split('|');
        let texto;
        try {
            if (!/^\d{4}-\d{2}-\d{2}$/.test(f.inicio) || !Number.isInteger(f.dias)) throw new Error('período inválido');
            texto = execFileSync(python, [script, 'list', '--inicio', f.inicio, '--dias', String(f.dias)],
                { encoding: 'utf8', timeout: 60_000, stdio: ['ignore', 'pipe', 'pipe'] }).trim();
        } catch (e) {
            texto = `(NÃO FOI POSSÍVEL LER A AGENDA: ${String(e.message).split('\n')[0].slice(0, 200)}. Diga isso na resposta; não procure em outro lugar nem invente.)`;
        }
        blocos.push([
            `## Dados oficiais — Google Agenda de todas as agendas da pessoa, lido agora pela Central`,
            `Período: ${f.rotulo} (a partir de ${f.inicio}, ${f.dias} dia(s)). Formato: data hora [agenda] título.`,
            texto || 'Nenhum evento no período.',
            'Regras: para a agenda, use SOMENTE estes dados. Não procure agenda em arquivos, repositórios ou outros sistemas.',
            'Inclua todos os eventos do período, em ordem de horário, com a agenda de cada um. Se não houver eventos, diga isso.',
        ].join('\n'));
    }
    return blocos.join('\n\n') || null;
}

function instrucoes(job, branch) {
    const base = [
        'Você está atendendo um pedido feito pela Central de IA do AlfaMatriz. Responda em português do Brasil.',
        job.projeto
            ? `Projeto: ${job.projeto}. Siga as instruções do próprio projeto (CLAUDE.md, AGENTS.md, README).`
            : 'Pedido da conversa Geral, fora de projeto: responda com os dados oficiais abaixo (se houver) e o que a pergunta pede.',
        // Regra global do dono, que vale mesmo com acesso total.
        'Commit, push, merge, deploy e qualquer alteração em produção: SÓ se o pedido disser isso explicitamente. Na dúvida, pergunte (campo "duvida").',
    ];
    if (job.modo === 'implementar') {
        base.push(branch
            ? `Você está numa worktree própria, na branch ${branch}. Faça as alterações pedidas aqui e rode os testes que fizerem sentido.`
            : 'Você trabalha DIRETO na pasta do projeto, como no VS Code. Antes de alterar, veja o `git status`: alterações que já estavam lá são de outra sessão — preserve-as.',
            'No fim, resuma o que mudou, por quê e como testar.');
    } else {
        base.push('Modo ANALISAR: não altere arquivos. Pode rodar comandos de leitura (git, logs, consultas, docker ps/logs) para responder.',
            // A mesma régua para qualquer agente: o dono pediu que resumo,
            // análise e relatório tenham a mesma qualidade venha de quem vier.
            'Responda de forma completa e organizada: comece pelo essencial, use listas curtas, diga de onde veio cada informação',
            'e não invente nada que não esteja nos dados oficiais abaixo, no código ou nos arquivos que você leu. Se faltar dado, diga o que falta.');
    }
    return base.join('\n');
}

/**
 * A memória do dono (o índice das notas que o Claude guarda em ~/projetos):
 * entregue a qualquer agente. Sem isso, cada um começava sem saber das
 * portas, das pontes e das decisões já tomadas.
 */
function memoria() {
    const arq = process.env.CENTRAL_IA_MEMORIA || join(HOME, '.claude/projects/-home-duque-de-caxias-projetos/memory/MEMORY.md');
    try {
        const texto = readFileSync(arq, 'utf8').trim();
        return texto ? `## Memória do dono (índice — os arquivos ficam em ${arq.replace(/\/MEMORY\.md$/, '')}; leia o que for relevante)\n${texto.slice(0, 12000)}` : null;
    } catch { return null; }
}

/**
 * O histórico de uma conversa de OUTRA IA, para quem a assume (ex.: o Claude
 * no limite da semana e o Codex segue a conversa do gestor). Só texto — o que
 * a pessoa escreveu e o que a IA respondeu —, sem o conteúdo das ferramentas.
 * Lido do fim do arquivo (a sessão do gestor passa de 100 MB).
 */
function historicoDaSessao(imp) {
    if (!imp || imp.agente !== 'claude') return null;
    const base = join(HOME, '.claude/projects');
    let arq = null;
    try {
        for (const d of readdirSync(base)) {
            const c = join(base, d, `${imp.sessao}.jsonl`);
            if (existsSync(c)) { arq = c; break; }
        }
    } catch {}
    if (!arq) return null;
    const tam = statSync(arq).size;
    const ler = Math.min(tam, 6 * 1024 * 1024);
    const fd = openSync(arq, 'r');
    const buf = Buffer.alloc(ler);
    readSync(fd, buf, 0, ler, tam - ler);
    closeSync(fd);
    const turnos = [];
    for (const l of buf.toString('utf8').split('\n').slice(1)) {
        let j; try { j = JSON.parse(l); } catch { continue; }
        if (j.isSidechain || j.isMeta) continue;
        const c = j.message?.content;
        const texto = typeof c === 'string' ? c : Array.isArray(c) ? c.filter(x => x?.type === 'text').map(x => x.text).join('\n') : '';
        if (!texto.trim() || (j.type !== 'user' && j.type !== 'assistant')) continue;
        if (j.type === 'user' && Array.isArray(c) && c.some(x => x?.type === 'tool_result')) continue;
        turnos.push(`${j.type === 'user' ? 'PESSOA' : 'CLAUDE'}: ${texto.trim().slice(0, 2500)}`);
    }
    let corpo = turnos.slice(-40).join('\n\n');
    if (corpo.length > 45000) corpo = corpo.slice(-45000);
    return `## Histórico da conversa que você está ASSUMINDO (sessão de Claude no VS Code, últimos turnos)\n`
        + 'Era o Claude quem conduzia. Continue de onde ele parou. Antes de alterar, confira o estado real (git status/diff) contra o que a conversa diz.\n\n'
        + corpo;
}

function comando(job, cwd, branch, saida, acesso) {
    // A sessão interna do agente só é retomada na MESMA pasta: o Claude guarda
    // as sessões por diretório, e retomar noutra falha.
    const sessao = job.continuar?.sessao && job.continuar.cwd === cwd ? job.continuar.sessao : null;
    const prompt = [instrucoes(job, branch), memoria(), job.dadosOficiais, historicoDaSessao(job.importar), job.contexto, job.contextoAnexos,
        `Pedido:\n${job.instrucao}`, job.bloco].filter(Boolean).join('\n\n');
    // Acesso total (dono, 10/10/2026: "lá tem que ter acesso total igual
    // aqui, senão fico travado"): só para implementar, só com o teto desta
    // máquina em `total` e só depois da confirmação do dono (confirmacao.mjs).

    if (job.agente === 'claude') {
        const args = ['-p', prompt, '--output-format', 'json'];
        if (sessao) args.push('--resume', sessao);
        // Continuação de sessão do VS Code: uma cópia, para a original ficar
        // intacta lá.
        if (sessao && job.continuar?.fork) args.push('--fork-session');
        // O id da sessão é escolhido AQUI, antes de rodar (sessão nova ou
        // cópia): se a execução for cortada no meio, a Central ainda sabe qual
        // sessão retomar. Retomada simples mantém o mesmo id.
        job.sessaoPrevista = sessao && !job.continuar?.fork ? sessao : randomUUID();
        if (!sessao || job.continuar?.fork) args.push('--session-id', job.sessaoPrevista);
        args.push(...permissoes('claude', acesso, { projetos: cfg.projetos }));
        return [agentes.claude, args];
    }

    if (job.agente === 'codex') {
        const comum = [...(job.imagens || []).flatMap(p => ['--image', p]), '--json', '-o', saida, '--skip-git-repo-check', ...permissoes('codex', acesso), '-c', 'forced_login_method="chatgpt"'];
        return [agentes.codex, sessao ? ['exec', 'resume', sessao, ...comum, prompt] : ['exec', ...comum, prompt]];
    }

    // Copilot: no modo não interativo ele exige --allow-all-tools; o controle
    // fica no --deny-tool. A sessão leva um nome para ser retomada por ele.
    const nome = sessao || `central-ia-${job.id}`;
    const args = ['-s', '--no-color', ...permissoes('copilot', acesso, { cwd })];
    args.push(...(sessao ? ['--resume', sessao] : ['-n', nome]), '-p', prompt);
    return [agentes.copilot, args, nome];
}

// Processos de agente em andamento, para a parada do serviço encerrá-los.
const filhos = new Set();
let parar = false;

function rodar(bin, args, cwd, minutos, progresso) {
    return new Promise(resolvePromise => {
        const env = { ...process.env };
        delete env.ALFAMATRIZ_RUNNER_TOKEN;
        // A troca de CLI não habilita cobrança por chave de API herdada.
        for (const chave of ['OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'ANTHROPIC_AUTH_TOKEN']) delete env[chave];
        const filho = spawn(bin, args, { cwd, env, stdio: ['ignore', 'pipe', 'pipe'], detached: true });
        filhos.add(filho);
        progresso?.processo(true);
        let out = '', err = '', estourou = false;
        filho.stdout.on('data', d => { progresso?.saida(d); out += d; if (out.length > 4_000_000) out = out.slice(-4_000_000); });
        filho.stderr.on('data', d => { progresso?.saida(d); err += d; if (err.length > 200_000) err = err.slice(-200_000); });
        const prazo = setTimeout(() => {
            estourou = true;
            try { process.kill(-filho.pid, 'SIGTERM'); } catch {}
            setTimeout(() => { try { process.kill(-filho.pid, 'SIGKILL'); } catch {} }, 10000);
        }, minutos * 60_000);
        filho.on('error', e => { clearTimeout(prazo); progresso?.processo(false); filhos.delete(filho); resolvePromise({ codigo: -1, out, err: String(e.message), estourou }); });
        filho.on('close', codigo => { clearTimeout(prazo); progresso?.processo(false); filhos.delete(filho); resolvePromise({ codigo, out, err, estourou }); });
    });
}

function erroDoProcesso(r) {
    const mensagens = [];
    for (const linha of r.out.split('\n')) {
        try {
            const e = JSON.parse(linha);
            if (e.type === 'error' || e.type === 'turn.failed') {
                const mensagem = e.error?.message || e.message;
                if (typeof mensagem === 'string') mensagens.push(mensagem);
            }
        } catch {}
    }
    const linhas = [...mensagens, ...r.err.split('\n'), ...r.out.split('\n')].filter(l => l.trim() && !l.trim().startsWith('{'));
    const limite = linhas.find(l => /(?:hit|reached|exceeded|exhausted).{0,60}(?:limit|quota)|(?:usage|weekly|rate|premium).{0,30}limit|too many requests|overloaded|service unavailable/i.test(l));
    return (limite || mensagens.at(-1) || r.err.trim().split('\n').at(-1) || `saiu com código ${r.codigo}`)
        .replace(/\bBearer\s+\S+/gi, 'Bearer [REDACTED]')
        .replace(/\bsk-[A-Za-z0-9_-]+/g, '[REDACTED]')
        .replace(/\/bot\d+:[A-Za-z0-9_-]+/g, '/bot[REDACTED]')
        .replace(/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/g, '[REDACTED]');
}

function interpretar(job, r, saida, nomeCopilot) {
    if (job.agente === 'claude') {
        const linha = r.out.trim().split('\n').reverse().find(l => l.startsWith('{'));
        const j = linha ? JSON.parse(linha) : null;
        if (!j || j.is_error || r.codigo !== 0) throw new Error(erroDoProcesso({ ...r, err: j?.result || r.err }));
        return { resposta: j.result, sessao: j.session_id };
    }
    if (job.agente === 'codex') {
        let sessao = job.continuar?.sessao || null;
        for (const l of r.out.split('\n')) {
            try { const e = JSON.parse(l); if (e.type === 'thread.started' && e.thread_id) sessao = e.thread_id; } catch {}
        }
        const resposta = existsSync(saida) ? readFileSync(saida, 'utf8').trim() : '';
        // Saída parcial não transforma erro de franquia em conclusão bem-sucedida.
        if (r.codigo !== 0) throw new Error(erroDoProcesso(r));
        return { resposta, sessao };
    }
    if (r.codigo !== 0) throw new Error(erroDoProcesso(r));
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
    const progresso = acompanharProgresso();
    let publicando = false;
    const informar = async () => {
        if (publicando) return;
        publicando = true;
        try {
            const r = await api(`execucoes/${job.id}/progresso`, { maquina: cfg.maquina, ...progresso.retrato() });
            if (r.status !== 200) log(`#${job.id} progresso recusado`, r.status);
        } catch (e) { log(`#${job.id} progresso indisponível:`, e.message); }
        finally { publicando = false; }
    };
    await informar();
    const avisos = setInterval(informar, 30_000);
    const tmp = mkdtempSync(join(tmpdir(), 'central-ia-'));
    // Fora do try: a falha também leva a pasta no checkpoint — é ela que diz
    // onde retomar a sessão do agente na próxima execução.
    let cwd = null, dir = null;
    const acesso = acessoEfetivo(job, cfg.acessoMaximo);
    try {
        if (!agentes[job.agente]) throw new Error(`${job.agente} não está instalado nesta máquina`);
        // Pedido da conversa Geral: na raiz dos projetos quando o teto desta
        // máquina é total (como este chat do VS Code); senão, numa pasta neutra.
        dir = job.projeto === '' ? (cfg.acessoMaximo === 'total' ? realpathSync(cfg.projetos) : pastaGeral()) : realpathSync(resolve(cfg.projetos, job.projeto));
        if (job.projeto !== '' && !dentroDe(cfg.projetos, dir)) throw new Error('projeto fora da pasta de projetos');
        if (job.modo !== 'implementar' && job.agente === 'codex' && CITA_CREDENCIAL.test(job.instrucao)) {
            throw new Error('pedido de análise cita credenciais da máquina; o Codex só leitura não tem como bloquear essa leitura — peça ao Claude');
        }
        job.dadosOficiais = dadosOficiais(job.fontes || []);

        // Onde trabalhar: na worktree da tarefa, se ela já tem uma (analisar
        // também — revisar o que foi feito é olhar a worktree); senão, na
        // pasta do projeto para analisar, e numa worktree nova para implementar.
        // Toda pasta vinda do servidor passa pelo realpath: fora dos projetos,
        // recusa (um servidor comprometido apontaria para ~/.ssh).
        cwd = dir;
        let branch = null, criada = null, novaWorktree = false;
        if (job.tarefa?.worktree && existsSync(job.tarefa.worktree)) {
            if (!dentroDe(cfg.projetos, job.tarefa.worktree)) throw new Error('a pasta da tarefa está fora da pasta de projetos');
            cwd = realpathSync(job.tarefa.worktree); branch = job.tarefa.branch;
        } else if (job.modo === 'implementar' && !job.na_pasta) {
            novaWorktree = true;
            cwd = job.tarefa?.worktree || join(cfg.worktrees, `${job.projeto}-t${job.tarefa?.id ?? `e${job.id}`}`);
        }
        if (job.continuar?.cwd && !dentroDe(cfg.projetos, job.continuar.cwd)) job.continuar = null;

        // Modificar exige o código do dono, que ESTA máquina gera e manda ao
        // Telegram dele (confirmacao.mjs). Sem isso, nada roda.
        if (exigeConfirmacao(job, cfg.confirmar)) {
            progresso.etapa('confirmacao');
            await informar();
            const c = await confirmar({ job, cwd, acesso, cfg, api, parar: () => parar });
            if (!c.ok) throw new Error(`não executado: ${c.motivo}`);
        } else {
            auditar({ execucao: job.id, decisao: 'leitura', agente: job.agente, projeto: job.projeto, modo: job.modo, cwd });
        }
        if (novaWorktree) {
            criada = prepararWorktree(job.projeto, dir, job.tarefa || { id: `e${job.id}` });
            ({ cwd, branch } = criada);
        }

        progresso.etapa('anexos');
        await informar();
        const midias = await prepararAnexos(job.anexos || [], cfg, tmp);
        job.imagens = midias.imagens; job.contextoAnexos = midias.contexto;
        const saida = join(tmp, 'ultima.txt');
        const [bin, args, nomeCopilot] = comando(job, cwd, branch, saida, acesso);
        // Parada pedida enquanto preparava: não cria um processo que ninguém
        // vai esperar.
        if (parar) throw new Error('interrompida porque o runner foi parado ou reiniciado antes de começar');
        progresso.etapa('agente');
        const r = await rodar(bin, args, cwd, job.timeout_minutos, progresso);
        if (r.estourou) throw new Error(`passou de ${job.timeout_minutos} minutos e foi encerrado`);
        if (parar) throw new Error('interrompida porque o runner foi parado ou reiniciado; o estado do git ficou registrado e a tarefa pode ser retomada');

        const { resposta, sessao } = interpretar(job, r, saida, nomeCopilot);
        resultado = {
            status: 'concluida',
            resposta: (resposta || '(o agente não escreveu resposta)') + (branch ? resumoDaBranch(cwd, branch) : ''),
            sessao_agente: sessao || null,
            branch,
            worktree: criada?.worktree ?? null,
            commit_base: criada?.commit_base ?? null,
            checkpoint: checkpoint(cwd, dir),
        };
    } catch (e) {
        resultado = { status: 'falhou', erro: String(e.message || e).slice(0, 1900), sessao_agente: job.sessaoPrevista || null };
        // Mesmo na falha o estado do git interessa (quem assumir parte dele),
        // e a pasta é o que permite retomar a sessão do agente depois.
        if (cwd && existsSync(cwd)) resultado.checkpoint = checkpoint(cwd, dir);
    } finally {
        clearInterval(avisos);
        progresso.etapa('finalizando');
        await informar();
        rmSync(tmp, { recursive: true, force: true });
    }

    // A conclusão é idempotente no AlfaMatriz: repetir depois de uma queda
    // não dobra a resposta. Insiste por até 30 min — com o AlfaMatriz fora do
    // ar por alguns minutos, a resposta do agente se perdia.
    const limite = Date.now() + 30 * 60_000;
    for (let tentativa = 1; ; tentativa++) {
        try {
            const r = await api(`execucoes/${job.id}/conclusao`, resultado);
            if (r.status === 200) break;
            log(`#${job.id} conclusão recusada`, r.status);
            if (r.status < 500 && r.status !== 429 && r.status !== 408) break;
        } catch (e) { log(`#${job.id} conclusão falhou (${e.message}), tentando de novo`); }
        if (Date.now() > limite) { log(`#${job.id} conclusão desistida após 30 min`); auditar({ execucao: job.id, decisao: 'conclusao_perdida' }); break; }
        await new Promise(r => setTimeout(r, Math.min(60_000, 5000 * tentativa)));
    }
    log(`#${job.id} ${resultado.status} em ${Math.round((Date.now() - inicio) / 1000)} s`);
}

/* ---------- laço ---------- */

// Parada do serviço: encerra os agentes em andamento e deixa cada execução
// reportar "interrompida" com o checkpoint do git — a tarefa não fica presa
// em "executando" até expirar, e quem assumir parte do estado real.
function encerrar() {
    if (parar) return;
    parar = true;
    for (const f of filhos) { try { process.kill(-f.pid, 'SIGTERM'); } catch {} }
}
process.on('SIGTERM', encerrar);
process.on('SIGINT', encerrar);

log(`runner ${cfg.maquina} → ${cfg.url}, projetos em ${cfg.projetos}`);
// A batida corre à parte do laço: durante uma execução longa a máquina
// continua aparecendo como online na tela.
const batendo = () => batida().catch(e => log('batida falhou:', e.message));
await batendo();
const relogio = setInterval(batendo, 30_000);
// Até PARALELO execuções ao mesmo tempo. O AlfaMatriz também limita (por
// pessoa, e uma por tarefa); aqui é a máquina que se protege.
const PARALELO = Math.max(1, Number(process.env.CENTRAL_IA_PARALELO || 2));
const rodando = new Set();
while (!parar) {
    try {
        if (rodando.size < PARALELO) {
            const r = await api('proxima', { maquina: cfg.maquina });
            if (r.status === 200 && r.json?.id) {
                const p = executar(r.json).catch(e => log(`#${r.json.id} erro no runner:`, e.message)).finally(() => rodando.delete(p));
                rodando.add(p);
                continue;
            }
            if (r.status === 401 || r.status === 403) log('token recusado ou conta sem permissão', r.status);
        }
    } catch (e) {
        log('AlfaMatriz indisponível:', e.message);
    }
    await new Promise(r => setTimeout(r, 3000));
}
// Parada pedida: espera o que está rodando terminar e devolver a resposta.
await Promise.allSettled([...rodando]);
clearInterval(relogio);
log('runner encerrado');
