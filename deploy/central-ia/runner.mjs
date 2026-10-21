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
import { writeFileSync, unlinkSync, closeSync, existsSync, lstatSync, openSync, readFileSync, readSync, readdirSync, statSync, mkdtempSync, rmSync, mkdirSync, realpathSync } from 'node:fs';
import { homedir, hostname, platform, release, tmpdir } from 'node:os';
import { join, resolve, sep } from 'node:path';
import { randomUUID } from 'node:crypto';
import { setDefaultAutoSelectFamilyAttemptTimeout } from 'node:net';
import { arquivoDaSessao, usoLocal } from './uso-local.mjs';
import { montarSemente } from './extrato.mjs';
import { prepararAnexos } from './anexos.mjs';
import { acompanharProgresso } from './progresso.mjs';
import { auditar, chatConfirmado, chaveDoChat, confirmar, marcarChatConfirmado } from './confirmacao.mjs';
import { caminhoDoStatus, compararGit, criarAuditor, retratoGit } from './auditoria.mjs';
import { girar, limparWorktrees } from './limpeza.mjs';
import { transcrever, whisperInstalado } from './transcricao.mjs';
import { hora as horaDoGestor, lerGestor } from './gestor.mjs';
import { hora as horaDaAgenda, lerAgenda as lerAgendaGoogle } from './agenda-google.mjs';
import { aplicarAlteracoes, configuracao as pastasDeDocumentos, espelhar as espelharDocumentos } from './documentos.mjs';
import { atenderSuporte, gravarAberturas, lerGestorParaPedro, naPonte } from './suporte.mjs';
import { acompanharAcesso, ESTADO_INICIAL, ritmoDaConsulta, acessoEfetivo, agendaSemIa, arquivoDeMemoria, CITA_CREDENCIAL, decisaoSemConfirmacao, dentroDe, exigeConfirmacao, modeloArgs, pedeDeploy, permissoes, regraCstk, regraEfetiva } from './politica.mjs';

// Chats já confirmados nesta máquina (vale 12 h; chave servidor + conta +
// chat, em confirmacao.mjs). Fica aqui, e não no servidor.
const CONFIRMADOS = join(homedir(), '.local/share/alfa/central-ia-chats-confirmados.json');

const HOME = homedir();
const CONFIG = process.env.CENTRAL_IA_RUNNER_CONFIG || join(HOME, '.config/alfa/central-ia-runner.env');

// Linux, WSL ou Mac (T-374): o runner roda nos três. A WSL se reconhece pelo
// kernel da Microsoft.
const SISTEMA = platform() === 'darwin' ? 'mac' : platform() === 'linux' ? (/microsoft/i.test(release()) ? 'wsl' : 'linux') : 'outro';

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
        // A pasta onde ficam os projetos da pessoa — o instalador pergunta.
        projetos: (v('CENTRAL_IA_PROJETOS') || join(HOME, 'projetos')).replace(/^~/, HOME),
        maquina: v('CENTRAL_IA_MAQUINA') || hostname().replace(/[^A-Za-z0-9._-]/g, '-').slice(0, 64),
        // O que a MÁQUINA permite, decidido aqui e não no servidor (dono,
        // 12/10/2026: "tem que ser inviolável"). O servidor pode pedir menos,
        // nunca mais. `total` só vale para implementar, e só confirmado.
        acessoMaximo: v('CENTRAL_IA_ACESSO_MAXIMO') === 'total' ? 'total' : 'restrito',
        // `implementar` (padrão): confirma o que modifica. `sempre`: confirma
        // também as análises.
        // O MÍNIMO desta máquina (nunca, deploy, inicio, implementar, sempre).
        // A escolha da pessoa em Ajustes só deixa mais rígido.
        confirmar: ['nunca', 'deploy', 'inicio', 'implementar', 'sempre'].includes(v('CENTRAL_IA_CONFIRMAR')) ? v('CENTRAL_IA_CONFIRMAR') : 'inicio',
        // Analisar: `leitura` (padrão) ou `total` — com total, a IA decide o
        // que consultar, e o que ela viu e alterou fica na auditoria.
        analise: v('CENTRAL_IA_ANALISE') === 'total' ? 'total' : 'leitura',
        // Codex com acesso total fora de deploy (padrão: não). Ver politica.mjs.
        codexTotal: v('CENTRAL_IA_CODEX_TOTAL') === 'sim',
        // Pastas da pasta de projetos que NÃO são projeto (vídeos, orçamentos):
        // somem do catálogo, das sessões e do consumo (dono, 12/10/2026).
        ignorar: new Set((v('CENTRAL_IA_IGNORAR') || '').split(',').map(x => x.trim()).filter(Boolean)),
        segredo: v('CENTRAL_IA_SEGREDO_CONFIRMACAO'),
        // O bot do CÓDIGO da pessoa (T-368, T-374): só para o código de
        // confirmação, diferente do bot da conversa, e o token dele nunca passa
        // pelo servidor. O instalador o pede no terminal e grava o token e o
        // chat; o servidor não muda para onde o código vai. O antigo
        // `CENTRAL_IA_TELEGRAM_TOKEN` NÃO vale mais (revisão da T-374): nas
        // máquinas instaladas à mão ele guardava o bot compartilhado da
        // conversa, cujo token o servidor tem — o instalador o converte depois
        // de conferir que é outro bot.
        telegramToken: v('CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO'),
        telegramChat: v('CENTRAL_IA_TELEGRAM_CHAT_ID'),
        // Opcionais, todos desta máquina e nenhum vindo do servidor (T-374):
        // - memória: o MEMORY.md que vai aos agentes quando a pessoa liga a
        //   opção nos Ajustes; sem valor, o da pasta de projetos, se existir;
        //   `nenhuma` desliga de vez nesta máquina;
        // - agenda: comando que lista a agenda pessoal (`programa|script`);
        //   sem ele, a agenda é a do AlfaMatriz, que vem no pedido;
        // - retrato: um retrato.json de painel de andamento, se a pessoa usa.
        memoria: (v('CENTRAL_IA_MEMORIA') || '').replace(/^~/, HOME),
        agenda: v('CENTRAL_IA_AGENDA') || '',
        // - gestor: a ponte de produção do Gestor (`programa|ponte|script`),
        //   rodada SÓ PARA LEITURA (sem --apply) para o Secretário (Etapa 5).
        gestor: v('CENTRAL_IA_GESTOR') || '',
        // - google: o exportador da agenda do Google (`programa|script|pasta`),
        //   só leitura, na pasta da ponte pessoal já autorizada (Secretário).
        google: v('CENTRAL_IA_GOOGLE') || '',
        // Pastas espelhadas do OneDrive: "Nome|caminho;Outro|caminho" (documentos-espelhados).
        documentos: v('CENTRAL_IA_DOCUMENTOS') || '',
        retrato: (v('CENTRAL_IA_RETRATO') || '').replace(/^~/, HOME),
        // O arquivo que o launchd enche com a saída deste processo (instalar.sh),
        // para girar quando passa do limite (T-378). No Linux e no WSL a saída
        // vai para o journal, que tem limite próprio: sem arquivo, nada a girar.
        logArquivo: (v('CENTRAL_IA_LOG') || (SISTEMA === 'mac' ? join(HOME, 'Library/Logs/central-ia-runner.log') : '')).replace(/^~/, HOME),
    };
    // Worktrees das execuções de implementar: dentro da pasta de projetos,
    // num diretório com ponto — que a listagem de projetos ignora.
    config.worktrees = join(config.projetos, '.central-ia', 'worktrees');
    if (!config.token) throw new Error(`ALFAMATRIZ_RUNNER_TOKEN ausente (${CONFIG}).`);
    return config;
}

// O Node tenta IPv4 e IPv6 juntos e desiste de cada um em 250 ms; daqui o
// IPv6 não tem rota e o IPv4 até o Telegram às vezes passa disso — o código
// de confirmação não saía ("fetch failed", 13/10/2026). 2,5 s por tentativa.
setDefaultAutoSelectFamilyAttemptTimeout(2500);

const cfg = lerConfig();
const log = (...a) => console.log(new Date().toISOString(), ...a);
// Acesso ao AlfaMatriz (politica.mjs: acompanharAcesso): máquina desconectada
// ou servidor fora de alcance avisam uma vez e espaçam as tentativas.
let acesso = ESTADO_INICIAL;
const notarAcesso = resultado => {
    const r = acompanharAcesso(acesso, resultado);
    acesso = r.estado;
    if (r.aviso) log(r.aviso);
};
// Quando este processo subiu e o que ele tem nas mãos: o servidor encerra o
// que ficou "executando" de um processo anterior que morreu sem avisar (13/10/2026:
// o WSL reiniciou e o pedido #84 ficou preso até o teto).
const INICIO_DO_PROCESSO = new Date().toISOString();
const emCurso = new Set();
// As TAREFAS com execução nesta máquina agora: a worktree delas não sai,
// mesmo que o servidor já as dê por encerradas (T-378).
const tarefasEmCurso = new Set();
// Tarefas encerradas desta conta que têm worktree, como a última batida disse.
let encerradas = [];

/* ---------- agentes instalados ---------- */

function noPath(nome) {
    for (const dir of (process.env.PATH || '').split(':')) {
        const p = join(dir, nome);
        if (dir && existsSync(p)) return p;
    }
    return null;
}

// As extensões do VS Code trazem os binários numa pasta com a versão no nome,
// e a pasta muda a cada atualização: procura a mais nova a cada batida. Na
// WSL/SSH ficam em ~/.vscode-server; no Mac e no Linux de mesa, em ~/.vscode.
function maisNovo(prefixo, relativo) {
    const achados = [];
    for (const base of [join(HOME, '.vscode-server/extensions'), join(HOME, '.vscode/extensions')]) {
        if (!existsSync(base)) continue;
        achados.push(...readdirSync(base).filter(d => d.startsWith(prefixo)).map(d => join(base, d, relativo)).filter(existsSync));
    }
    achados.sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
    return achados.at(-1) || null;
}

// A pasta do binário do Codex dentro da extensão muda com o sistema.
const CODEX_NA_EXTENSAO = SISTEMA === 'mac' ? `bin/macos-${process.arch === 'arm64' ? 'aarch64' : 'x86_64'}/codex` : `bin/linux-${process.arch === 'arm64' ? 'aarch64' : 'x86_64'}/codex`;

function localizarAgentes() {
    return {
        claude: noPath('claude') || maisNovo('anthropic.claude-code-', 'resources/native-binary/claude'),
        codex: noPath('codex') || maisNovo('openai.chatgpt-', CODEX_NA_EXTENSAO),
        copilot: noPath('copilot') || (existsSync(join(HOME, '.local/bin/copilot')) ? join(HOME, '.local/bin/copilot') : null),
    };
}

function listarProjetos() {
    return readdirSync(cfg.projetos)
        .filter(n => /^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(n) && n.length <= 128 && !cfg.ignorar.has(n))
        // Pasta vazia não é projeto.
        .filter(n => { try { return statSync(join(cfg.projetos, n)).isDirectory() && readdirSync(join(cfg.projetos, n)).length > 0; } catch { return false; } })
        .sort();
}

/* ---------- AlfaMatriz ---------- */

// A máquina conectada de novo (o comando rodado com código novo) troca o
// token no arquivo, e o servidor apaga o antigo na hora; este processo ainda
// tem o antigo na memória até reiniciar. No primeiro 401, relê o arquivo e, se
// o token mudou, tenta de novo com ele: senão o resultado do pedido que estava
// rodando voltava com 401 e se perdia (revisão da T-374).
function tokenNovoNoArquivo() {
    if (process.env.ALFAMATRIZ_RUNNER_TOKEN) return false;
    let novo;
    try { novo = lerConfig().token; } catch { return false; }
    if (!novo || novo === cfg.token) return false;
    cfg.token = novo;
    log('token novo no arquivo de configuração: usando-o (a máquina foi conectada de novo)');
    return true;
}

// O bot do código pode chegar DEPOIS de o processo subir: o instalador o grava
// quando a pessoa cola o token, e rodar o comando de novo só para colá-lo não
// precisa esperar reinício nenhum (revisão da T-374: a máquina recém-instalada
// ficava sem o bot até reiniciar por outro motivo). Relê do arquivo antes de
// cada batida e de cada confirmação; o ambiente do processo continua vencendo.
function releTelegram() {
    let novo;
    try { novo = lerConfig(); } catch { return; }
    for (const k of ['telegramToken', 'telegramChat', 'segredo']) {
        if (novo[k] !== cfg[k]) {
            cfg[k] = novo[k];
            if (k === 'telegramToken') log(novo[k] ? 'bot do código lido do arquivo de configuração' : 'bot do código retirado do arquivo de configuração');
        }
    }
}

async function api(caminho, corpo, deNovo = true) {
    const r = await fetch(`${cfg.url}/api/central-ia/runner/${caminho}`, {
        method: 'POST',
        headers: { Authorization: `Bearer ${cfg.token}`, Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify(corpo),
        signal: AbortSignal.timeout(20000),
    });
    if (r.status === 401 && deNovo && tokenNovoNoArquivo()) return api(caminho, corpo, false);
    const texto = await r.text();
    let json = null;
    try { json = texto ? JSON.parse(texto) : null; } catch {}
    return { status: r.status, json };
}

/** Um arquivo para a Central (multipart): a prévia que o agente deixou. */
async function apiArquivo(caminho, campos, conteudo, nome, deNovo = true) {
    const form = new FormData();
    for (const [k, v] of Object.entries(campos)) form.append(k, v);
    form.append('arquivo', new Blob([conteudo]), nome);
    const r = await fetch(`${cfg.url}/api/central-ia/runner/${caminho}`, {
        method: 'POST', headers: { Authorization: `Bearer ${cfg.token}`, Accept: 'application/json' }, body: form, signal: AbortSignal.timeout(120000),
    });
    if (r.status === 401 && deNovo && tokenNovoNoArquivo()) return apiArquivo(caminho, campos, conteudo, nome, false);
    return r.status;
}

const PREVIAS = join(HOME, '.local/share/alfa/central-ia-previas');
const ID_DE_SESSAO = /^[A-Za-z0-9][A-Za-z0-9-]{7,79}$/;

/** Os arquivos que o agente deixou na pasta de prévia, com caminho relativo. */
function arquivosDaPrevia(dir, base = dir, lista = []) {
    for (const n of readdirSync(dir)) {
        const c = join(dir, n);
        // lstat, não stat: link simbólico não entra (um agente apontaria
        // previa/x.txt para ~/.config/alfa e o arquivo ficaria público).
        const st = lstatSync(c);
        if (st.isSymbolicLink()) continue;
        if (st.isDirectory()) arquivosDaPrevia(c, base, lista);
        else if (st.isFile() && st.size <= 12 * 1024 * 1024 && lista.length < 80) lista.push(c.slice(base.length + 1));
    }
    return lista;
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
    let processos;
    try { processos = processosDeAgente(); } catch (e) { log('sessões abertas indisponíveis:', String(e.message).split('\n')[0]); return {}; }
    for (const { agente, cwd: pasta } of processos) {
        try {
            const cwd = realpathSync(pasta);
            if (!cwd.startsWith(raiz)) continue;
            const projeto = cwd.slice(raiz.length).split(sep)[0];
            // As worktrees da própria Central não contam como sessão de alguém.
            if (projeto.startsWith('.')) continue;
            (porProjeto[projeto] ??= new Set()).add(agente);
        } catch {}
    }
    return porProjeto;
}

function qualAgente(cmd) {
    return /native-binary\/claude|\/claude(?:\0|\s|$)/.test(cmd) ? 'claude'
        : /\/codex(?:\0|\s|$)/.test(cmd) ? 'codex' : /copilot/.test(cmd) ? 'copilot' : null;
}

/**
 * Os processos de agente e a pasta de cada um. No Linux e na WSL, pelo
 * /proc; no Mac, que não tem /proc (T-374: o runner quebrava lá), pelo `ps`
 * e pelo `lsof` — que dá a pasta de trabalho (`cwd`) de cada processo.
 */
function processosDeAgente() {
    const achados = [];
    if (existsSync('/proc/self/cwd')) {
        for (const pid of readdirSync('/proc').filter(p => /^\d+$/.test(p))) {
            try {
                const agente = qualAgente(readFileSync(`/proc/${pid}/cmdline`, 'utf8'));
                if (agente) achados.push({ agente, cwd: `/proc/${pid}/cwd` });
            } catch {}
        }
        return achados;
    }
    const porPid = new Map();
    for (const linha of execFileSync('ps', ['-axo', 'pid=,command='], { encoding: 'utf8', timeout: 10_000, stdio: ['ignore', 'pipe', 'ignore'] }).split('\n')) {
        const m = linha.trim().match(/^(\d+)\s+(.*)$/);
        const agente = m && qualAgente(m[2]);
        if (agente && Number(m[1]) !== process.pid) porPid.set(m[1], agente);
    }
    if (!porPid.size) return achados;
    // -a: E entre os filtros; -d cwd: só a pasta de trabalho; -Fn: saída
    // "p<pid>" e "n<pasta>", uma por linha.
    let saida = '';
    try { saida = execFileSync('lsof', ['-a', '-d', 'cwd', '-Fn', '-p', [...porPid.keys()].join(',')], { encoding: 'utf8', timeout: 10_000, stdio: ['ignore', 'pipe', 'ignore'] }); }
    catch (e) { saida = String(e.stdout || ''); } // lsof sai com 1 se algum pid já terminou
    let pid = null;
    for (const linha of saida.split('\n')) {
        if (linha.startsWith('p')) pid = linha.slice(1);
        else if (linha.startsWith('n') && pid && porPid.has(pid)) achados.push({ agente: porPid.get(pid), cwd: linha.slice(1) });
    }
    return achados;
}

// Fase e atividade de um painel de andamento, para quem usa um: o
// retrato.json que ele gera, só lido. Opcional (T-374): o caminho fixo de
// antes era o painel da máquina de uma pessoa.
function retratoDoPainel() {
    if (!cfg.retrato) return {};
    const arquivo = cfg.retrato;
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
    // Recusada, a batida só repetiria a recusa: quem confere se o acesso voltou é o laço.
    if (acesso.situacao === 'recusado') return;
    releTelegram();
    agentes = localizarAgentes();
    const projetos = listarProjetos();
    const u = uso();
    // O que é das pastas ignoradas não sai daqui: sessões somem; o gasto
    // continua contado (foi gasto de verdade), sem o nome da pasta.
    const sessoes = u?.sessoes?.filter(x => !cfg.ignorar.has(x.projeto));
    const consumo = u ? { ...u.consumo, linhas: (u.consumo.linhas || []).map(l => cfg.ignorar.has(l.projeto) ? { ...l, projeto: '(fora dos projetos)' } : l) } : null;
    const r = await api('batida', {
        maquina: cfg.maquina,
        agentes: Object.keys(agentes).filter(k => agentes[k]),
        projetos,
        estado: estadoDosProjetos(projetos),
        // O que esta máquina impõe, para a tela mostrar (só leitura lá).
        politica: { acesso_maximo: cfg.acessoMaximo, analise: cfg.analise, confirmar_minimo: cfg.confirmar,
            telegram: !!(cfg.telegramToken && cfg.telegramChat && /^[0-9a-f]{64}$/i.test(cfg.segredo || '')), sistema: SISTEMA },
        desde: INICIO_DO_PROCESSO,
        em_curso: [...emCurso],
        ...(u ? { consumo: { ...consumo, limites_claude: limitesDoClaude() }, sessoes } : {}),
    });
    // O 2xx também conta: depois de "fora de alcance", a batida que passa tira
    // o laço da espera longa sem esperar a consulta seguinte.
    if (r.status === 401 || r.status === 403 || (r.status >= 200 && r.status < 300)) notarAcesso(r.status);
    if (r.status === 401 || r.status === 403) return;
    if (r.status !== 200) log('batida recusada', r.status, r.json?.message || '');
    else if (Array.isArray(r.json?.encerradas)) encerradas = r.json.encerradas;
}

/* ---------- faxina (T-378) ---------- */

// O log gira a cada volta (é só um stat); as worktrees, uma vez por hora,
// porque cada uma custa alguns comandos de git.
const FAXINA_MS = 10 * 60_000;
const WORKTREES_MS = 3600_000;
let ultimaFaxinaDeWorktrees = 0;
function faxina() {
    try {
        if (cfg.logArquivo && girar(cfg.logArquivo, { copiar: true })) log('log do runner girado');
    } catch (e) { log('não consegui girar o log:', e.message); }
    if (Date.now() - ultimaFaxinaDeWorktrees < WORKTREES_MS || !encerradas.length) return;
    ultimaFaxinaDeWorktrees = Date.now();
    for (const f of limparWorktrees({ git, worktrees: cfg.worktrees, projetos: cfg.projetos, encerradas, emCurso: tarefasEmCurso })) {
        if (f.removida) { log('worktree de tarefa encerrada removida:', f.worktree); auditar({ decisao: 'worktree_removida', worktree: f.worktree }); }
        else log('worktree de tarefa encerrada mantida:', f.worktree, `(${f.motivo})`);
    }
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
 * continuação de uma sessão do VS Code roda na RAIZ da pasta de projetos, que
 * não é repositório: aí o git medido é o da pasta do projeto.
 */
function checkpoint(cwd, projetoDir = null) {
    const c = { cwd };
    for (const dir of [cwd, projetoDir].filter(Boolean)) {
        try {
            c.branch = git(dir, 'branch', '--show-current') || null;
            c.head = git(dir, 'rev-parse', '--short', 'HEAD');
            const linhas = git(dir, 'status', '--porcelain').split('\n').filter(Boolean);
            c.status = linhas.slice(0, 200).map(l => l.slice(0, 300));
            c.arquivos = linhas.slice(0, 200).map(l => caminhoDoStatus(l).slice(0, 290));
            c.diff_stat = (git(dir, 'diff', '--stat').split('\n').at(-1) || '').slice(0, 290);
            break;
        } catch {}
    }
    return c;
}

/**
 * Onde ficam os anexos da execução. No Mac, dentro do HOME (T-374): o Docker
 * de lá (Colima, e o Docker Desktop configurado assim) só monta o HOME, e a
 * conversão de PDF e vídeo num container não achava o /var/folders do
 * tmpdir(). No Linux e na WSL, o tmpdir() de sempre.
 */
function pastaTemporaria() {
    if (SISTEMA !== 'mac') return tmpdir();
    const dir = join(HOME, '.local/share/alfa/tmp');
    mkdirSync(dir, { recursive: true, mode: 0o700 });
    return dir;
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
/**
 * A agenda do período. Com `CENTRAL_IA_AGENDA` (`programa|script`, opcional
 * por máquina), a agenda pessoal que a pessoa integrou — chamada com
 * `list --inicio AAAA-MM-DD --dias N`, uma linha por evento. Sem ela, a do
 * próprio AlfaMatriz, que o servidor já manda no pedido (T-374: o padrão era
 * o script do Google Agenda no Windows de uma pessoa).
 */
function lerAgenda(f) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(f.inicio) || !Number.isInteger(f.dias)) throw new Error('período inválido');
    if (cfg.agenda) {
        const [programa, ...resto] = cfg.agenda.split('|');
        return { fonte: 'agenda pessoal desta máquina', texto: execFileSync(programa, [...resto, 'list', '--inicio', f.inicio, '--dias', String(f.dias)],
            { encoding: 'utf8', timeout: 60_000, stdio: ['ignore', 'pipe', 'pipe'] }).trim() };
    }
    if (typeof f.alfamatriz === 'string') return { fonte: 'Agenda do AlfaMatriz', texto: f.alfamatriz.trim() };
    throw new Error('nenhuma agenda disponível (sem acesso à Agenda do AlfaMatriz e sem agenda pessoal configurada nesta máquina)');
}

function dadosOficiais(fontes) {
    const blocos = [];
    for (const f of fontes) {
        if (f.tipo !== 'agenda') continue;
        let texto, fonte = 'agenda da pessoa';
        try {
            ({ texto, fonte } = lerAgenda(f));
        } catch (e) {
            texto = `(NÃO FOI POSSÍVEL LER A AGENDA: ${String(e.message).split('\n')[0].slice(0, 200)}. Diga isso na resposta; não procure em outro lugar nem invente.)`;
        }
        blocos.push([
            `## Dados oficiais — ${fonte}, lida agora pela Central`,
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
        // Prévia visual (dono, 13/10/2026): o que ficava numa pasta solta da
        // máquina, quem estava em outra não via.
        ...(job.previaDir ? [`Para MOSTRAR algo visual à pessoa (conceito, tela, gráfico, PDF), salve na pasta de prévia ${job.previaDir}: imagens (png/jpg/webp), PDF e vídeo MP4 (até 12 MB cada; vídeo maior, reduza antes) aparecem na conversa e vão ao Telegram dela; uma página web vai em ${job.previaDir}/pagina/index.html (CSS, JS e imagens ao lado, com caminhos RELATIVOS) e vira um link para abrir no navegador, no Mac ou no Windows. Não use outra pasta (temporária ou de projeto) para isso, e não precisa dizer o caminho na resposta.`] : []),
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
        // O método cstk, só para quem o ligou nos Ajustes (T-374).
        const cstk = job.cstk ? regraCstk(job.agente, job.projeto) : null;
        if (cstk) base.push(cstk);
    } else if (job.categoria === 'operacao') {
        // Operar um sistema (dono, 12/10/2026): confere, propõe, confirma,
        // e só então grava. Código não se mexe; banco além do pedido, só com
        // backup oferecido antes.
        base.push('Pedido de OPERAÇÃO num sistema (ex.: agendar alguém, lançar, dar baixa). Siga esta ordem:',
            '1) Confira o estado real ANTES (somente leitura): o que já existe, conflitos, o contexto do pedido (ex.: se diz "continuação", procure o atendimento aberto, em andamento ou aguardando; se não achar, diga isso e mostre os 3 últimos desse cliente).',
            '2) Se o que achou não bater com o pedido, NÃO grave: explique e pergunte (campo "duvida").',
            '3) Se bater, NÃO grave ainda: escreva exatamente o que vai fazer (quem, onde, quando, horário) e peça a confirmação no campo "duvida", com "opcoes_duvida": ["Sim, pode fazer", "Não"].',
            '4) Só grave depois de a pessoa confirmar nesta conversa. Grave somente o pedido, pela via oficial do sistema (as notas da memória do dono dizem como; ex.: ponte de produção com DRY antes do APPLY), e confira o resultado depois.',
            'Operação que só registra o pedido não precisa de backup. Se for preciso alterar CÓDIGO ou mexer no banco além do pedido, pare e pergunte antes se a pessoa deseja um backup, dizendo o motivo (o que será alterado e o que se perde sem ele).');
    } else {
        // Análise que propõe mudança termina oferecendo os três caminhos
        // (dono, 13/10/2026: "perguntar se era aquilo mesmo e quer implementar,
        // ou se deveria trazer outra sugestão").
        if (job.projeto) base.push('Se a sua análise PROPÕE uma mudança (código, tela, regra, dado), termine perguntando no campo "duvida": "É isso que você quer? Posso implementar?", com "opcoes_duvida" exatamente: ["Sim, implementar", "Trazer outra sugestão", "Outra sugestão com outra IA"]. Se for só uma consulta ou resposta, não pergunte.');
        base.push('Modo ANALISAR: não altere arquivos. Pode rodar comandos de leitura (git, logs, consultas, docker ps/logs) para responder.',
            // A mesma régua para qualquer agente: o dono pediu que resumo,
            // análise e relatório tenham a mesma qualidade venha de quem vier.
            'Responda de forma completa e organizada: comece pelo essencial, use listas curtas, diga de onde veio cada informação',
            'e não invente nada que não esteja nos dados oficiais abaixo, no código ou nos arquivos que você leu. Se faltar dado, diga o que falta.');
    }
    return base.join('\n');
}

/**
 * A memória da pessoa (o índice das notas que o Claude guarda para a pasta de
 * projetos): entregue a qualquer agente, quando ela liga a opção nos Ajustes.
 * Sem isso, cada um começava sem saber das portas, das pontes e das decisões
 * já tomadas. O arquivo é escolhido AQUI (arquivoDeMemoria): o servidor só
 * diz sim ou não, e não aponta arquivo nenhum da máquina.
 */
function memoria(job) {
    if (!job.memoria) return null;
    const arq = arquivoDeMemoria(cfg.memoria, realpathSync(cfg.projetos), HOME);
    if (!arq) return null;
    try {
        const texto = readFileSync(arq, 'utf8').trim();
        return texto ? `## Memória da pessoa (índice — os arquivos ficam em ${arq.replace(/\/MEMORY\.md$/, '')}; leia o que for relevante)\n${texto.slice(0, 12000)}` : null;
    } catch { return null; }
}

/**
 * O histórico de uma conversa de OUTRA IA, para quem a assume (ex.: o Claude
 * no limite da semana e o Codex segue a conversa do gestor). Só texto — o que
 * a pessoa escreveu e o que a IA respondeu —, sem o conteúdo das ferramentas.
 * Lido do fim do arquivo (a sessão do gestor passa de 100 MB).
 */
function historicoDaSessao(imp) {
    if (!imp || imp.agente !== 'claude' || !ID_DE_SESSAO.test(String(imp.sessao || ''))) return null;
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
    // Id de sessão vira argumento de linha de comando: só o formato de id
    // (nada que comece com "-" e vire opção da CLI).
    const sessao = job.continuar?.sessao && job.continuar.cwd === cwd && ID_DE_SESSAO.test(job.continuar.sessao) ? job.continuar.sessao : null;
    const remoto = job.remotoAtualizado ? `Git: a Central já rodou \`git fetch --all\` às ${job.remotoAtualizado}; as referências remotas (origin/*) estão atualizadas. Você não tem rede: use o que já está local.`
        : job.remotoFalhou ? `Git: a Central tentou \`git fetch\` e falhou (${job.remotoFalhou}). Diga que os dados remotos podem estar desatualizados.` : null;
    // POPs: o que as IAs já aprenderam dos sistemas envolvidos. Vêm antes do
    // pedido para o agente ir direto às consultas, sem redescobrir.
    const pops = (job.pops || []).length ? ['## POPs — procedimentos já aprendidos (siga-os; se estiverem errados ou incompletos, corrija e devolva o POP atualizado no bloco final)',
        ...job.pops.map(p => `### ${p.sistema} · ${p.titulo} (versão ${p.versao})\n${p.conteudo}`)].join('\n\n') : null;
    const prompt = [instrucoes(job, branch), remoto, pops, memoria(job), job.dadosOficiais, historicoDaSessao(job.importar), job.contexto, job.contextoAnexos,
        `Pedido:\n${job.instrucao}`, job.bloco].filter(Boolean).join('\n\n');
    // Acesso total (dono, 10/10/2026: "lá tem que ter acesso total igual
    // aqui, senão fico travado"): só para implementar, só com o teto desta
    // máquina em `total` e só depois da confirmação do dono (confirmacao.mjs).

    if (job.agente === 'claude') {
        // stream-json: além da resposta, os eventos de ferramenta — é deles
        // que sai o "quem viu o quê" da auditoria (a última linha continua
        // sendo o resultado, como no json).
        // O pedido vai pelo stdin, não por argumento: o Linux limita um
        // argumento a 128 KB, e a semente do Gestor.SG (13/10/2026) caiu com
        // "spawn E2BIG". Contexto de chat de assunto passa disso fácil.
        const args = ['-p', '--output-format', 'stream-json', '--verbose'];
        if (sessao) args.push('--resume', sessao);
        // Continuação de sessão do VS Code: uma cópia, para a original ficar
        // intacta lá.
        if (sessao && job.continuar?.fork) args.push('--fork-session');
        // O id da sessão é escolhido AQUI, antes de rodar (sessão nova ou
        // cópia): se a execução for cortada no meio, a Central ainda sabe qual
        // sessão retomar. Retomada simples mantém o mesmo id.
        job.sessaoPrevista = sessao && !job.continuar?.fork ? sessao : randomUUID();
        if (!sessao || job.continuar?.fork) args.push('--session-id', job.sessaoPrevista);
        args.push(...permissoes('claude', acesso, { projetos: cfg.projetos, deploy: pedeDeploy(job), semFerramentas: job.acesso === 'nenhum' }), ...modeloArgs('claude', job.modelo, job.esforco));
        return [agentes.claude, args, null, prompt];
    }

    if (job.agente === 'codex') {
        const comum = [...(job.imagens || []).flatMap(p => ['--image', p]), '--json', '-o', saida, '--skip-git-repo-check', ...permissoes('codex', acesso, { deploy: pedeDeploy(job), codexTotal: cfg.codexTotal, semFerramentas: job.acesso === 'nenhum' }), ...modeloArgs('codex', job.modelo, job.esforco), '-c', 'forced_login_method="chatgpt"'];
        // `-`: o Codex lê o pedido do stdin (mesmo motivo do Claude, E2BIG).
        return [agentes.codex, sessao ? ['exec', 'resume', sessao, ...comum, '-'] : ['exec', ...comum, '-'], null, prompt];
    }

    // Copilot: no modo não interativo ele exige --allow-all-tools; o controle
    // fica no --deny-tool. A sessão leva um nome para ser retomada por ele.
    const nome = sessao || `central-ia-${job.id}`;
    const args = ['-s', '--no-color', ...permissoes('copilot', acesso, { cwd, deploy: pedeDeploy(job), semFerramentas: job.acesso === 'nenhum' }), ...modeloArgs('copilot', job.modelo, job.esforco)];
    // Pedido pelo stdin também no Copilot (E2BIG acima de 128 KB, que o
    // contexto de um chat de assunto passa fácil).
    args.push(...(sessao ? ['--resume', sessao] : ['-n', nome]));
    return [agentes.copilot, args, nome, prompt];
}

// Processos de agente em andamento, para a parada do serviço encerrá-los.
const filhos = new Set();
let parar = false;

function rodar(bin, args, cwd, minutos, progresso, auditor = null, entrada = null) {
    return new Promise(resolvePromise => {
        const env = { ...process.env };
        delete env.ALFAMATRIZ_RUNNER_TOKEN;
        // A troca de CLI não habilita cobrança por chave de API herdada.
        for (const chave of ['OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'ANTHROPIC_AUTH_TOKEN']) delete env[chave];
        const filho = spawn(bin, args, { cwd, env, stdio: [entrada === null ? 'ignore' : 'pipe', 'pipe', 'pipe'], detached: true });
        if (entrada !== null) { filho.stdin.on('error', () => {}); filho.stdin.end(entrada); }
        filhos.add(filho);
        progresso?.processo(true);
        let out = '', err = '', estourou = false;
        filho.stdout.on('data', d => { progresso?.saida(d); auditor?.saida(d); out += d; if (out.length > 4_000_000) out = out.slice(-4_000_000); });
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
    const tmp = mkdtempSync(join(pastaTemporaria(), 'central-ia-'));
    // Fora do try: a falha também leva a pasta no checkpoint — é ela que diz
    // onde retomar a sessão do agente na próxima execução.
    let cwd = null, dir = null;
    const acesso = acessoEfetivo(job, cfg.acessoMaximo, cfg.analise);
    const auditor = criarAuditor(job.agente);
    let antesGit = null;
    try { execucao: {
        // Sem IA: a agenda lida agora é a resposta. Nenhum agente roda, nada
        // é modificado, nenhuma cota é gasta.
        if (job.nivel === 'sem_ia' && job.modo !== 'implementar' && (job.fontes || []).some(f => f.tipo === 'agenda')) {
            auditar({ execucao: job.id, decisao: 'sem_ia', projeto: job.projeto });
            const partes = (job.fontes || []).filter(f => f.tipo === 'agenda').map(f => {
                try { const a = lerAgenda(f); return agendaSemIa(f.rotulo, f.inicio, a.texto, a.fonte); }
                catch (e) { return `Não foi possível ler a agenda (${String(e.message).split('\n')[0].slice(0, 160)}). Nada foi inventado; tente de novo ou peça um resumo a uma IA.`; }
            });
            resultado = { status: 'concluida', resposta: partes.join('\n\n'), sessao_agente: null };
            break execucao;
        }
        if (!agentes[job.agente]) throw new Error(`${job.agente} não está instalado nesta máquina`);
        // Pedido da conversa Geral: na raiz dos projetos quando o teto desta
        // máquina é total (como um chat do VS Code aberto nela); senão, numa
        // pasta neutra.
        dir = job.projeto === '' ? (cfg.acessoMaximo === 'total' ? realpathSync(cfg.projetos) : pastaGeral()) : realpathSync(resolve(cfg.projetos, job.projeto));
        if (job.projeto !== '' && !dentroDe(cfg.projetos, dir)) throw new Error('projeto fora da pasta de projetos');
        if (acesso === 'leitura' && job.agente === 'codex' && CITA_CREDENCIAL.test(job.instrucao)) {
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
            // A pasta ainda não existe, então o realpath não serve: confere
            // pelo caminho resolvido que ela fica nas worktrees ou nos projetos.
            const alvo = resolve(cwd);
            if (![cfg.worktrees, cfg.projetos].some(r => alvo.startsWith(resolve(r) + sep))) throw new Error('a pasta da tarefa está fora das pastas permitidas');
        }
        if (job.continuar?.cwd && !dentroDe(cfg.projetos, job.continuar.cwd)) job.continuar = null;

        // Análise lê o remoto ATUALIZADO: o runner busca antes (só refs
        // remotas, nada no código). No modo só leitura o agente não tem rede —
        // em 12/10/2026 o Codex respondeu "sem acesso ao GitHub" a "o
        // Alexandre mexeu ontem?" enquanto o VS Code buscava e respondia.
        if (job.modo !== 'implementar' && job.projeto !== '') {
            progresso.etapa('preparando');
            try {
                execFileSync('git', ['-C', cwd, 'fetch', '--all', '--prune', '--quiet'], { timeout: 45_000, stdio: ['ignore', 'ignore', 'pipe'] });
                job.remotoAtualizado = new Date().toISOString();
            } catch (e) {
                const erro = String(e.stderr || e.message);
                // Pasta sem git ou sem remoto: nada a avisar.
                if (!/not a git repository|No remote|does not appear to be a git/i.test(erro)) job.remotoFalhou = erro.split('\n').find(Boolean)?.slice(0, 200) || 'falhou';
            }
        }

        // Modificar exige o código do dono, que ESTA máquina gera e manda ao
        // Telegram dele (confirmacao.mjs). Sem isso, nada roda.
        const regra = regraEfetiva(cfg.confirmar, job.confirmacao_regra);
        const chaveChat = chaveDoChat(cfg.url, job.conta, job.tarefa?.id);
        if (exigeConfirmacao(job, regra, chatConfirmado(CONFIRMADOS, chaveChat))) {
            progresso.etapa('confirmacao');
            await informar();
            releTelegram();
            const c = await confirmar({ job, cwd, acesso, cfg, api, parar: () => parar });
            if (!c.ok) throw new Error(`não executado: ${c.motivo}`);
            marcarChatConfirmado(CONFIRMADOS, chaveChat);
        } else {
            // "leitura" só quando É leitura (T-368: a implementação que a regra
            // deixou passar sem código saía no log como leitura).
            auditar({ execucao: job.id, ...decisaoSemConfirmacao(job, regra, acesso), agente: job.agente, projeto: job.projeto, modo: job.modo, cwd });
        }
        if (novaWorktree) {
            criada = prepararWorktree(job.projeto, dir, job.tarefa || { id: `e${job.id}` });
            ({ cwd, branch } = criada);
        }

        // Só diz "preparando os anexos" quando há anexo: sem isso, a tela
        // mostrava essa etapa em todo pedido (12/10/2026).
        if ((job.anexos || []).length) { progresso.etapa('anexos'); await informar(); }
        const midias = await prepararAnexos(job.anexos || [], cfg, tmp);
        job.imagens = midias.imagens; job.contextoAnexos = midias.contexto;
        const saida = join(tmp, 'ultima.txt');
        if (acesso !== 'leitura') {
            job.previaDir = join(PREVIAS, `e${job.id}`);
            mkdirSync(job.previaDir, { recursive: true });
        }
        if (Array.isArray(job.semear) && job.semear.length) {
            uso(); // garante a lista de sessões desta máquina
            const s = montarSemente(job.semear, { localizar: arquivoDaSessao });
            job.contexto = [job.contexto, s.texto].filter(Boolean).join('\n\n');
            job.sementeLida = s.lidas;
            auditar({ execucao: job.id, decisao: 'semente', conversas: s.lidas.map(l => ({ id: l.id, turnos: l.incluidos, caracteres: l.caracteres })) });
        }
        const [bin, args, nomeCopilot, entrada] = comando(job, cwd, branch, saida, acesso);
        // Parada pedida enquanto preparava: não cria um processo que ninguém
        // vai esperar.
        if (parar) throw new Error('interrompida porque o runner foi parado ou reiniciado antes de começar');
        progresso.etapa('agente');
        antesGit = retratoGit(git, cwd);
        const r = await rodar(bin, args, cwd, job.timeout_minutos, progresso, auditor, entrada ?? null);
        if (r.estourou) throw new Error(`passou de ${job.timeout_minutos} minutos e foi encerrado`);
        if (parar) throw new Error('interrompida porque o runner foi parado ou reiniciado; o estado do git ficou registrado e a tarefa pode ser retomada');

        const { resposta, sessao } = interpretar(job, r, saida, nomeCopilot);
        // Prévias: sobem antes da conclusão, para a resposta já chegar com elas.
        if (job.previaDir && existsSync(job.previaDir)) {
            for (const rel of arquivosDaPrevia(job.previaDir)) {
                try {
                    const st = await apiArquivo(`execucoes/${job.id}/previa`, { maquina: cfg.maquina, caminho: rel.split(sep).join('/') }, readFileSync(join(job.previaDir, rel)), rel.split(sep).pop());
                    if (st !== 200) log(`#${job.id} prévia ${rel} recusada`, st);
                } catch (e) { log(`#${job.id} prévia ${rel} falhou:`, e.message); }
            }
            rmSync(job.previaDir, { recursive: true, force: true });
        }
        resultado = {
            status: 'concluida',
            resposta: (resposta || '(o agente não escreveu resposta)') + (branch ? resumoDaBranch(cwd, branch) : ''),
            sessao_agente: sessao || null,
            branch,
            worktree: criada?.worktree ?? null,
            commit_base: criada?.commit_base ?? null,
            checkpoint: checkpoint(cwd, dir),
        };
        if (job.sementeLida) {
            resultado.semente = job.sementeLida.map(({ agente, id, incluidos, fora, caracteres }) => ({ agente, id, incluidos, fora, caracteres }));
            // Cópia do Mac: usada uma vez e apagada (FR-020).
            for (const l of job.sementeLida) if (l.copia) rmSync(l.arquivo, { force: true });
        }
    } } catch (e) {
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

    // O rastro: o que o agente fez (eventos da CLI) e o que mudou no git.
    if (resultado && antesGit && cwd) {
        resultado.auditoria = { acessos: auditor.acessos(), informado: auditor.informado, git: compararGit(git, cwd, antesGit, retratoGit(git, cwd), auditor.editados()), acesso };
    } else if (resultado && auditor.acessos().length) {
        resultado.auditoria = { acessos: auditor.acessos(), informado: auditor.informado, git: null, acesso };
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
// Fora de alcance, o laço já avisou uma vez; a batida não repete a cada 30 s.
const batendo = () => batida().catch(e => { if (acesso.situacao !== 'rede') log('batida falhou:', e.message); });
await batendo();
const relogio = setInterval(batendo, 30_000);
const faxinando = setInterval(faxina, FAXINA_MS);
faxina();
// Até PARALELO execuções ao mesmo tempo. O AlfaMatriz também limita (por
// pessoa, e uma por tarefa); aqui é a máquina que se protege.
const PARALELO = Math.max(1, Number(process.env.CENTRAL_IA_PARALELO || 2));
const rodando = new Set();
// Quantas execuções estão com esta máquina — inclusive esperando o código do
// dono, que não tem processo filho. O reinício "quando ocioso" lê isto: em
// 13/10/2026 ele reiniciou no meio de duas confirmações e as derrubou.
const OCUPADO = join(HOME, '.local/share/alfa/central-ia-ocupado');
const marcarOcupado = () => { try { writeFileSync(OCUPADO, String(rodando.size)); } catch {} };
marcarOcupado();
// Último trabalho desta máquina (politica.mjs: ritmoDaConsulta): perto dele a
// consulta é a cada 3 s; parada, a cada 10 s. Subir conta como trabalho, para
// o teste logo depois de instalar não esperar.
let ultimoTrabalho = Date.now();
// Atualização (atualizar-quando-ocioso.sh) pede o reinício por este arquivo, e
// quem decide sair é o próprio laço, no mesmo passo em que pediria trabalho: com
// nada rodando, sai antes de chamar `proxima`, então nenhum pedido é pego e
// largado. Antes o script matava o processo de fora depois de checar o
// `OCUPADO`, e um pedido que chegava entre a checagem e o kill morria como
// "expirada" (#17 no staging, 15/10/2026). O serviço (KeepAlive no launchd,
// Restart=always no systemd) o religa já com a versão nova.
//
// Revisão da T-374, mais dois pedaços:
// - `CONHECE` diz ao script que ESTE processo sabe sair sozinho. Sem isso o
//   script supunha "runner antigo" depois de ~20 s sem saída, e um `proxima`
//   lento (até 20 s) ainda podia trazer um pedido no instante do kill.
// - "pausar" no arquivo: no Mac, quando o serviço em si mudou (o plist), o
//   launchd só relê o plist descarregando o serviço, e religar sozinho volta
//   com o plist velho. Então o runner para de pedir trabalho, avisa em
//   `PAUSADO`, e o script descarrega e carrega o serviço sem pedido nenhum na
//   mão. Ninguém veio em 2 min (o script morreu): volta a trabalhar.
const REINICIAR = join(HOME, '.local/share/alfa/central-ia-reiniciar');
// Transcrição de reuniões (Secretário, Etapa 4): uma por vez; a presença da
// imagem é conferida a cada 10 min (o instalador pode tê-la criado depois).
let transcrevendo = false;
let lendoGestor = false;
let ultimoGestor = null;
let gestorPausadoAte = 0;
let transcricaoPausadaAte = 0;
let lendoAgenda = false;
let ultimaAgenda = null;
let agendaPausadaAte = 0;
// Documentos: a cada 15 min; com envio pendente (rodada limitada), no minuto seguinte.
let espelhando = false;
let proximoEspelho = 0;
let alterando = false;
let proximaAlteracao = 0;
// O Pedro (suporte no WhatsApp): uma vaga só dele, fora do PARALELO dos pedidos de código.
let atendendoSuporte = false;
let suportePausadoAte = 0;
// O retrato do Gestor para o Pedro (só leitura), a cada 10 min.
let lendoGestorPedro = false;
let proximoRetratoPedro = 0;
// O que o Pedro pediu para gravar no Gestor (FR-A8): a cada 20 s, um lote por vez.
let gravandoGestor = false;
let proximaGravacao = 0;
let whisperConferido = { em: 0, ok: false };
async function whisperPronto() {
    if (Date.now() - whisperConferido.em > 600_000) whisperConferido = { em: Date.now(), ok: await whisperInstalado() };
    return whisperConferido.ok;
}
const CONHECE = join(HOME, '.local/share/alfa/central-ia-conhece-reinicio');
const PAUSADO = join(HOME, '.local/share/alfa/central-ia-pausado');
const PAUSA_MAX_MS = 120_000;
try { writeFileSync(CONHECE, String(process.pid)); } catch {}
let pausadoDesde = 0;
while (!parar) {
    if (!rodando.size && existsSync(REINICIAR)) {
        let pedido = '';
        try { pedido = readFileSync(REINICIAR, 'utf8').trim(); } catch {}
        try { unlinkSync(REINICIAR); } catch {}
        if (pedido === 'pausar') {
            pausadoDesde = Date.now();
            try { writeFileSync(PAUSADO, String(process.pid)); } catch {}
            log('parado, sem pedir trabalho, para o serviço ser recarregado (nada em andamento)');
        } else {
            log('reiniciando para carregar a versão nova (nada em andamento)');
            parar = true;
            break;
        }
    }
    if (pausadoDesde) {
        if (Date.now() - pausadoDesde < PAUSA_MAX_MS) { await new Promise(r => setTimeout(r, 1000)); continue; }
        pausadoDesde = 0;
        try { unlinkSync(PAUSADO); } catch {}
        log('ninguém recarregou o serviço em 2 min: voltando a pedir trabalho');
    }
    try {
        // Reuniões do Secretário: uma transcrição por vez, quando a imagem
        // existe e a conta usa o Secretário (403 = não usa; tenta de novo em 1 h).
        // Pendências do Gestor (Secretário, Etapa 5): retrato de hora em hora,
        // em paralelo ao resto; uma leitura por vez.
        if (cfg.gestor && !lendoGestor && Date.now() >= gestorPausadoAte && horaDoGestor(ultimoGestor)) {
            lendoGestor = true;
            ultimoGestor = new Date();
            naPonte(() => lerGestor(cfg.gestor)).then(async r => {
                if (!r.ok) return log('Gestor: retrato não lido —', r.erro);
                const envio = await api('gestor', { maquina: cfg.maquina, lido_em: new Date().toISOString(), blocos: r.blocos });
                if (envio.status === 403 || envio.status === 404) gestorPausadoAte = Date.now() + 3600_000 * 6;
                log('Gestor: retrato enviado →', envio.status);
            }).catch(e => log('Gestor: erro', e.message)).finally(() => { lendoGestor = false; });
        }
        // Agenda do Google (pela máquina): a cada 10 min, uma leitura por vez.
        if (cfg.google && !lendoAgenda && Date.now() >= agendaPausadaAte && horaDaAgenda(ultimaAgenda)) {
            lendoAgenda = true;
            ultimaAgenda = new Date();
            lerAgendaGoogle(cfg.google).then(async r => {
                if (!r.ok) return log('Agenda: não lida —', r.erro);
                const envio = await api('agenda-google', { maquina: cfg.maquina, lido_em: new Date().toISOString(), agendas: r.agendas, eventos: r.eventos });
                if (envio.status === 403 || envio.status === 404) agendaPausadaAte = Date.now() + 3600_000 * 6;
                if (envio.status !== 200) log('Agenda: envio →', envio.status);
            }).catch(e => log('Agenda: erro', e.message)).finally(() => { lendoAgenda = false; });
        }
        // O Pedro, do suporte no WhatsApp (suporte-pedro-whatsapp): vaga própria, sem
        // ferramentas; 403/404 = esta conta não atende o suporte (tenta de novo em 1 h).
        if (!atendendoSuporte && Date.now() >= suportePausadoAte) {
            atendendoSuporte = true;
            atenderSuporte(cfg.maquina, { api, agentes })
                .then(r => {
                    if (r.status === 403 || r.status === 404 || r.status === 405 || r.status === 422) suportePausadoAte = Date.now() + 3600_000;
                    if (r.id) log(`suporte: pedido #${r.id} ${r.erro ? 'falhou (' + r.erro + ')' : 'respondido'} em ${Math.round(r.ms / 1000)} s`);
                })
                .catch(e => { log('suporte: erro', e.message); suportePausadoAte = Date.now() + 15_000; })
                .finally(() => { atendendoSuporte = false; });
        }
        if (cfg.gestor && !lendoGestorPedro && Date.now() >= proximoRetratoPedro && Date.now() >= suportePausadoAte) {
            lendoGestorPedro = true;
            proximoRetratoPedro = Date.now() + 10 * 60_000;
            naPonte(() => lerGestorParaPedro(cfg.gestor))
                .then(async r => {
                    const envio = await api('suporte/gestor', { maquina: cfg.maquina, lido_em: new Date().toISOString(), ...r });
                    if (envio.status === 403 || envio.status === 404) proximoRetratoPedro = Date.now() + 3600_000;
                    else if (envio.status !== 200) log('suporte: retrato do Gestor →', envio.status);
                })
                .catch(e => log('suporte: retrato do Gestor não lido —', e.message))
                .finally(() => { lendoGestorPedro = false; });
        }
        if (cfg.gestor && !gravandoGestor && Date.now() >= proximaGravacao && Date.now() >= suportePausadoAte) {
            gravandoGestor = true;
            proximaGravacao = Date.now() + 20_000;
            gravarAberturas(cfg.gestor, cfg.maquina, { api, log })
                .then(r => { if (r.status === 403 || r.status === 404) proximaGravacao = Date.now() + 3600_000; })
                .catch(e => log('suporte: gravação no Gestor —', e.message))
                .finally(() => { gravandoGestor = false; });
        }
        // Mão dupla (documentos-mao-dupla): a cada 30 s, aplica no OneDrive o que foi
        // pedido pelo Matriz; aplicou algo, espelha de novo logo em seguida.
        if (cfg.documentos && !espelhando && !alterando && Date.now() >= proximaAlteracao) {
            alterando = true;
            proximaAlteracao = Date.now() + 30_000;
            const baixar = async id => {
                const r = await fetch(`${cfg.url}/api/central-ia/runner/documentos/operacoes/${id}/arquivo?maquina=${encodeURIComponent(cfg.maquina)}`, {
                    headers: { Authorization: `Bearer ${cfg.token}` }, redirect: 'error', signal: AbortSignal.timeout(600_000),
                });
                if (!r.ok) throw new Error(`não consegui baixar o arquivo (HTTP ${r.status})`);
                return Buffer.from(await r.arrayBuffer());
            };
            aplicarAlteracoes(pastasDeDocumentos(cfg.documentos), cfg.maquina, { api, baixar, log })
                .then(n => { if (n) proximoEspelho = 0; })
                .catch(e => log('Documentos: alterações —', e.message))
                .finally(() => { alterando = false; });
        }
        if (cfg.documentos && !espelhando && !alterando && Date.now() >= proximoEspelho) {
            espelhando = true;
            (async () => {
                let pendentes = 0;
                for (const pasta of pastasDeDocumentos(cfg.documentos)) {
                    const r = await espelharDocumentos(pasta, cfg.maquina, { api, apiArquivo });
                    pendentes += r.pendentes;
                    if (r.erro) log(`Documentos (${pasta.nome}):`, r.erro);
                    else if (r.enviados || r.removidos || r.miniaturas) log(`Documentos (${pasta.nome}): ${r.enviados} enviado(s), ${r.removidos} removido(s), ${r.miniaturas || 0} miniatura(s), ${r.pendentes} na fila`);
                }
                proximoEspelho = Date.now() + (pendentes ? 60_000 : 15 * 60_000);
            })().catch(e => { log('Documentos: erro', e.message); proximoEspelho = Date.now() + 15 * 60_000; }).finally(() => { espelhando = false; });
        }
        if (!transcrevendo && rodando.size < PARALELO && !existsSync(REINICIAR) && Date.now() >= transcricaoPausadaAte && await whisperPronto()) {
            const t = await api('transcricoes/proxima', { maquina: cfg.maquina });
            if (t.status === 403 || t.status === 404 || t.status === 405) transcricaoPausadaAte = Date.now() + 3600_000;
            if (t.status === 200 && t.json?.id) {
                transcrevendo = true;
                ultimoTrabalho = Date.now();
                log(`reunião #${t.json.id}: transcrevendo (${t.json.modo})`);
                const aoProgredir = (percentual, duracao) => api(`transcricoes/${t.json.id}/progresso`, { maquina: cfg.maquina, percentual, duracao })
                    .catch(e => log(`reunião #${t.json.id}: progresso não enviado —`, e.message));
                const p = transcrever(t.json, cfg, pastaTemporaria(), { aoProgredir })
                    .then(async res => {
                        const envio = await api(`transcricoes/${t.json.id}`, { maquina: cfg.maquina, ...res });
                        log(`reunião #${t.json.id}: ${res.status === 'ok' ? res.trechos.length + ' trecho(s)' : 'falhou (' + res.erro + ')'} → ${envio.status}`);
                    })
                    .catch(e => log(`reunião #${t.json.id} erro no runner:`, e.message))
                    .finally(() => { rodando.delete(p); transcrevendo = false; marcarOcupado(); ultimoTrabalho = Date.now(); });
                rodando.add(p);
                marcarOcupado();
            }
        }
        // Reinício pedido com algo rodando: não pega trabalho novo, para a
        // máquina esvaziar. Com PARALELO=2 os pedidos se sobrepunham, ela
        // nunca ficava livre e a atualização esperava para sempre (3ª revisão
        // da T-374). O pedido segue na fila do servidor, para esta ou outra.
        if (rodando.size < PARALELO && !existsSync(REINICIAR)) {
            const r = await api('proxima', { maquina: cfg.maquina });
            notarAcesso(r.status);
            if (r.status === 200 && r.json?.id) {
                const id = r.json.id;
                const tarefaId = r.json.tarefa?.id;
                emCurso.add(id);
                if (tarefaId) tarefasEmCurso.add(tarefaId);
                ultimoTrabalho = Date.now();
                const p = executar(r.json).catch(e => log(`#${id} erro no runner:`, e.message)).finally(() => { rodando.delete(p); emCurso.delete(id); if (tarefaId) tarefasEmCurso.delete(tarefaId); marcarOcupado(); ultimoTrabalho = Date.now(); });
                rodando.add(p);
                marcarOcupado();
                continue;
            }
        }
    } catch (e) {
        if (acesso.situacao !== 'rede') log('AlfaMatriz indisponível:', e.message);
        notarAcesso('rede');
    }
    // Com algo rodando, a máquina não está ociosa: a resposta seguinte da conversa vem logo.
    if (rodando.size) ultimoTrabalho = Date.now();
    const espera = acesso.situacao === 'ok' ? ritmoDaConsulta(Date.now() - ultimoTrabalho) : acesso.espera;
    // Em passos de 1 s: a espera chega a 10 min, e parar o serviço não espera
    // por ela. Nem a atualização (o pedido de reinício) nem a máquina conectada
    // de novo (token novo no arquivo): sem isso, quem reconectava via a máquina
    // online na tela e o pedido seguinte esperava até 10 min (revisão da T-374).
    const situacaoAntes = acesso.situacao;
    for (let t = 0; t < espera && !parar; t += 1000) {
        await new Promise(r => setTimeout(r, Math.min(1000, espera - t)));
        // Só com a máquina livre: com pedido rodando o laço não sai, e cortar
        // a espera aqui virava uma consulta por segundo ao servidor.
        if (espera > 10_000 && !rodando.size && existsSync(REINICIAR)) break;
        if (acesso.situacao !== situacaoAntes) break;
        if (situacaoAntes === 'recusado' && t % 5000 === 0 && tokenNovoNoArquivo()) break;
    }
}
// Parada pedida: espera o que está rodando terminar e devolver a resposta.
await Promise.allSettled([...rodando]);
clearInterval(relogio);
clearInterval(faxinando);
log('runner encerrado');
// Sai de vez: um socket ainda aberto não pode segurar o processo e atrasar a volta.
process.exit(0);
