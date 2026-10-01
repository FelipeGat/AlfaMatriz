#!/usr/bin/env node
/**
 * A ponte entre o Telegram e o Claude Code — o agente do AlfaMatriz.
 *
 * O que ela faz: fica ouvindo o bot do agente (@alfamatriz_agente_bot), e cada
 * mensagem de quem pode comandar vira uma rodada do Claude Code em modo
 * headless. A resposta volta pelo mesmo chat, e enquanto ele trabalha uma
 * mensagem de andamento diz o que está sendo feito.
 *
 * DUAS FAIXAS (01/10/2026). Um pedido pode ser de dois tipos, e eles não
 * disputam o mesmo recurso:
 *
 * - QUADRO: abrir tarefa, marcar reunião, ver o que está travado. Só usa as
 *   ferramentas do servidor MCP do AlfaMatriz, não toca no repositório e
 *   termina em segundos.
 * - CÓDIGO: trabalhar numa tarefa — editar, rodar a suíte, commitar. Usa o
 *   clone, e pode levar muitos minutos.
 *
 * Com uma fila só, "abre uma tarefa" esperava a suíte de outro pedido terminar.
 * Com duas, o quadro anda enquanto o código trabalha. Dentro de cada faixa
 * continua sendo um pedido por vez: dois agentes editando o mesmo clone é
 * conflito, e duas suítes ao mesmo tempo é a máquina de joelhos.
 *
 * Quem decide a faixa é o próprio Claude da faixa do quadro, que não tem como
 * mexer em código: se o pedido não cabe nas ferramentas dele, responde um
 * marcador e a ponte passa o pedido adiante. Uma palavra-chave no começo da
 * mensagem obrigaria a pessoa a classificar o próprio pedido — e por áudio.
 *
 * O que ela deliberadamente NÃO faz:
 *
 * - Não decide nada sozinha. Só age quando uma mensagem chega, e só de quem
 *   está em TELEGRAM_PERMITIDOS. Não há rotina que dispare por conta própria —
 *   decisão do dono do produto em 28/09/2026: "não terá rotina programada sem
 *   eu pedir". O `/agendar` existe para o que ELE marcar, e é ele quem marca.
 *
 * - Não publica em produção. O agente nunca cria tag; quem cria é o `/publicar`,
 *   que é um comando digitado pela pessoa, no chat dela — é a mesma pessoa que
 *   sempre publicou, pelo mesmo mecanismo (tag `v*` + vigia), só que pelo
 *   Telegram. O texto do agente pode DIZER "pronto para publicar"; a tag sai da
 *   mão dele.
 *
 * Áudio também entra: a mensagem de voz é transcrita na própria máquina
 * (`transcrever.py`, Whisper local) e o texto entendido é mostrado antes de
 * virar pedido. Decisão do dono em 30/09/2026: transcrição local, sem conta nova.
 *
 * Sem dependência de npm, de propósito: `fetch` e `child_process` bastam, e um
 * `npm install` a menos é um `npm install` a menos para quebrar numa máquina
 * que ninguém está olhando.
 *
 * Autorizada pelo dono do produto em 30/09/2026, inclusive o modo sem
 * confirmação de permissões na faixa de código, num ambiente isolado (LXC de
 * desenvolvimento; depois, um usuário próprio no Mac).
 *
 * Configuração pelo ambiente (ver `alfa-agente.env.example`).
 */

import { spawn } from 'node:child_process';
import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { basename, dirname } from 'node:path';

const TOKEN = obrigatorio('TELEGRAM_TOKEN');
const PERMITIDOS = new Set(
    (process.env.TELEGRAM_PERMITIDOS ?? '').split(',').map((s) => s.trim()).filter(Boolean),
);
const REPO = process.env.AGENTE_REPO ?? '/opt/dev/AlfaMatriz';
const ESTADO = process.env.AGENTE_ESTADO ?? '/var/lib/alfa-agente/estado.json';
const CLAUDE = process.env.AGENTE_CLAUDE ?? 'claude';
// Um `.mcp.json` só desta máquina, quando o do repositório não serve (no LXC a
// produção só responde pela rede interna). Com o arquivo definido, o Claude usa
// SÓ ele (`--strict-mcp-config`).
const MCP_CONFIG = process.env.AGENTE_MCP_CONFIG ?? '';
// O programa que transforma um áudio do Telegram em texto (`transcrever.py`,
// Whisper local). Vazio, a ponte diz que não entende áudio em vez de fingir.
const TRANSCRITOR = process.env.AGENTE_TRANSCRITOR ?? '';
const PASTA_DE_AUDIO = process.env.AGENTE_AUDIO ?? `${dirname(ESTADO)}/audio`;
// Onde a faixa do quadro roda. Fora do clone de propósito: ela não precisa do
// repositório, e sem ele o Claude não carrega o CLAUDE.md do projeto a cada
// "abre uma tarefa". Só vale com um MCP_CONFIG próprio — sem ele, o servidor
// MCP vem do `.mcp.json` do repositório, e a faixa precisa rodar lá dentro.
const PASTA_DO_QUADRO = MCP_CONFIG ? (process.env.AGENTE_QUADRO ?? `${dirname(ESTADO)}/quadro`) : REPO;
// Quatro horas: uma tarefa de código com suíte pode levar muito, mas nada
// legítimo passa disso. Depois, o processo é derrubado e o chat fica sabendo.
const TEMPO_MAXIMO_MS = Number(process.env.AGENTE_TEMPO_MAXIMO_MIN ?? 240) * 60_000;
const TEMPO_DO_QUADRO_MS = 10 * 60_000;
// O agente do quadro fica ABERTO entre um pedido e outro (ver `pedirAoResidente`).
// Depois de tantos pedidos, ou de tanto tempo parado, ele é trocado por um novo:
// a conversa acumulada encarece e atrasa cada resposta, e "o que está travado?"
// de amanhã não precisa lembrar do de hoje.
const RESIDENTE_MAX_PEDIDOS = 25;
const RESIDENTE_MAX_PARADO_MS = 30 * 60_000;
// O transcritor também pode ficar aberto, com o modelo do Whisper na memória
// (~1 GB). Vale no Mac; no LXC de 4 GB, não.
const TRANSCRITOR_RESIDENTE = process.env.AGENTE_TRANSCRITOR_RESIDENTE === '1';
// Um pedido falado se transcreve em um ou dois segundos. Passou disto, o
// Whisper entrou em laço: melhor dizer "não consegui" do que segurar a pessoa.
const TEMPO_DA_TRANSCRICAO_MS = 45_000;
// Até quando a resposta substitui a mensagem de andamento em vez de chegar como
// mensagem nova (ver `naFaixa`).
const RESPOSTA_NO_LUGAR_ATE_MS = 45_000;
const API = `https://api.telegram.org/bot${TOKEN}`;
const DONO = process.env.AGENTE_DONO ?? 'o dono do produto';
const SERVIDOR_MCP = 'alfamatriz-producao';

// O que a faixa do quadro responde quando o pedido não é dela.
const MARCADOR_DE_CODIGO = '[[CODIGO]]';

/**
 * O que o agente precisa saber além do CLAUDE.md do repositório, que ele lê
 * sozinho: que está falando por Telegram, e o que nunca pode fazer daqui.
 */
const REGRAS_DO_CODIGO = `
Você está rodando como o agente do AlfaMatriz num servidor, comandado pelo Telegram por ${DONO}.
- Responda em português, texto puro (sem Markdown), curto: o Telegram é um chat, não um relatório. Até uns 2500 caracteres.
- Para mexer no quadro e na agenda do sistema NO AR, use o servidor MCP "${SERVIDOR_MCP}". O "alfamatriz" local é só do banco de desenvolvimento deste clone.
- Trabalhe na branch Rossini. Antes de dizer que algo está pronto, rode a suíte (php artisan test) e diga o resultado. Commit e push só quando pedidos.
- NUNCA crie tag nem faça deploy. Quando algo estiver pronto para produção, diga qual versão publicar e pare: quem publica é a pessoa, com /publicar.
- Se precisar de uma decisão que é dela, pergunte e pare em vez de escolher.
`.trim();

const REGRAS_DO_QUADRO = `
Você é o assistente do quadro de tarefas e da agenda do AlfaMatriz, comandado pelo Telegram por ${DONO}.
- Você só tem as ferramentas do servidor MCP "${SERVIDOR_MCP}": listar, ver, criar e mover tarefas, perguntar, responder, comentar, ver agenda e marcar compromisso. Não tem arquivos, terminal nem git.
- Se o pedido exigir editar código, rodar comandos, testes, git, commit ou deploy — ou se parecer a continuação de uma conversa que você não tem —, responda EXATAMENTE ${MARCADOR_DE_CODIGO} e nada mais. Outro agente, com acesso ao código, assume.
- Fora isso, resolva você: responda em português, texto puro (sem Markdown), curto. Até uns 2500 caracteres.
- Se precisar de uma decisão que é da pessoa, pergunte e pare em vez de escolher.
`.trim();

function obrigatorio(nome) {
    const valor = process.env[nome];
    if (!valor) {
        console.error(`Defina ${nome} no ambiente (ver alfa-agente.env.example).`);
        process.exit(1);
    }
    return valor;
}

// ---------- estado em disco: offset do Telegram, sessões por chat, agendamentos ----------

function lerEstado() {
    let lido = {};
    try {
        lido = JSON.parse(readFileSync(ESTADO, 'utf8'));
    } catch {
        // primeira subida, ou arquivo ilegível: começa do zero
    }

    const estado = { offset: 0, sessoes: {}, ultimaFaixa: {}, agendados: [], ...lido };

    // Até 01/10/2026 havia uma sessão só por chat, guardada como texto. Ela era
    // a da faixa de código.
    for (const [chat, sessao] of Object.entries(estado.sessoes)) {
        if (typeof sessao === 'string') estado.sessoes[chat] = { codigo: sessao };
    }

    return estado;
}

function gravarEstado() {
    mkdirSync(dirname(ESTADO), { recursive: true });
    writeFileSync(ESTADO, JSON.stringify(estado, null, 2));
}

const estado = lerEstado();

// ---------- Telegram ----------

async function telegram(metodo, corpo) {
    const resposta = await fetch(`${API}/${metodo}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(corpo),
    });
    const json = await resposta.json();
    if (!json.ok) {
        throw new Error(`Telegram ${metodo}: ${json.description ?? resposta.status}`);
    }
    return json.result;
}

/** Manda texto puro, em pedaços de até 4000 (o limite do Telegram é 4096). */
async function responder(chatId, texto) {
    const limpo = (texto ?? '').trim() || '(sem resposta)';
    for (let i = 0; i < limpo.length; i += 4000) {
        await telegram('sendMessage', { chat_id: chatId, text: limpo.slice(i, i + 4000), disable_web_page_preview: true });
    }
}

function duracao(ms) {
    const s = Math.round(ms / 1000);
    return s < 60 ? `${s}s` : `${Math.floor(s / 60)}m${String(s % 60).padStart(2, '0')}s`;
}

/**
 * A mensagem de andamento: UMA mensagem, editada a cada passo, em vez de uma
 * mensagem nova por passo. Um pedido de código passa por dezenas de passos, e
 * dezenas de notificações no celular é o jeito de a pessoa silenciar o bot.
 *
 * As edições são espaçadas (o Telegram limita a frequência) e a última sempre
 * sai — o que fica na tela ao final é o que de fato aconteceu por último.
 */
async function criarAndamento(chatId, titulo) {
    const inicio = Date.now();
    let mensagemId = null;
    let ultimaEdicao = 0;
    let pendente = null;
    let relogio = null;
    let encerrado = false;

    try {
        mensagemId = (await telegram('sendMessage', { chat_id: chatId, text: `${titulo}…` })).message_id;
    } catch (e) {
        console.error(e.message);
    }

    const editar = async (texto) => {
        if (mensagemId === null) return false;
        ultimaEdicao = Date.now();
        try {
            await telegram('editMessageText', { chat_id: chatId, message_id: mensagemId, text: texto });
            return true;
        } catch {
            // "message is not modified" e limites de frequência: o andamento é
            // cortesia, e não pode derrubar o pedido. Quem chama decide o que
            // fazer quando a edição não pegou.
            return false;
        }
    };

    return {
        passo(texto) {
            if (encerrado || !texto) return;
            pendente = `${titulo}… (${duracao(Date.now() - inicio)})\n• ${texto}`;
            if (relogio) return;
            const espera = Math.max(0, 2500 - (Date.now() - ultimaEdicao));
            relogio = setTimeout(() => {
                relogio = null;
                if (!encerrado && pendente) editar(pendente);
            }, espera);
        },
        decorrido() {
            return Date.now() - inicio;
        },
        /** Encerra o andamento com um texto final. Devolve se a edição pegou. */
        async fim(texto) {
            encerrado = true;
            clearTimeout(relogio);
            return editar(texto ?? `Concluído em ${duracao(Date.now() - inicio)}.`);
        },
    };
}

/** O passo, em palavras de gente. Null quando não vale uma linha na tela. */
function descreverPasso(ferramenta, entrada = {}) {
    if (ferramenta.startsWith(`mcp__${SERVIDOR_MCP}__`)) {
        return `quadro: ${ferramenta.split('__').pop().replaceAll('_', ' ')}`;
    }

    switch (ferramenta) {
        case 'Bash': {
            const comando = String(entrada.command ?? '');
            if (/artisan test|phpunit|pest/.test(comando)) return 'rodando a suíte de testes';
            if (/^git (commit|push)/.test(comando.trim())) return comando.trim().startsWith('git push') ? 'enviando ao GitHub' : 'fazendo o commit';
            return entrada.description ? String(entrada.description).slice(0, 90) : `rodando: ${comando.slice(0, 70)}`;
        }
        case 'Read':
            return `lendo ${basename(String(entrada.file_path ?? 'um arquivo'))}`;
        case 'Edit':
        case 'Write':
        case 'NotebookEdit':
            return `editando ${basename(String(entrada.file_path ?? 'um arquivo'))}`;
        case 'Grep':
        case 'Glob':
            return 'procurando no código';
        case 'Agent':
        case 'Task':
            return 'delegando parte do trabalho';
        case 'WebFetch':
        case 'WebSearch':
            return 'consultando a web';
        case 'TodoWrite':
        case 'ToolSearch':
            return null;
        default:
            return ferramenta;
    }
}

// ---------- as duas faixas ----------

const faixas = {
    quadro: { fila: [], ocupado: false, filho: null },
    codigo: { fila: [], ocupado: false, filho: null },
};

function enfileirar(nome, tarefa) {
    const faixa = faixas[nome];
    faixa.fila.push(tarefa);
    if (!faixa.ocupado) proximo(nome);
}

async function proximo(nome) {
    const faixa = faixas[nome];
    const tarefa = faixa.fila.shift();
    if (!tarefa) {
        faixa.ocupado = false;
        return;
    }
    faixa.ocupado = true;
    try {
        await tarefa();
    } catch (e) {
        console.error(e);
    }
    proximo(nome);
}

// ---------- executar coisas ----------

/**
 * Roda um programa e devolve o que ele disse. `aCadaLinha` recebe a saída
 * linha a linha enquanto ele roda — é por onde o andamento do Claude chega.
 * `faixa` é quem responde por ele no `/parar`.
 */
function executar(comando, args, { cwd = REPO, tempoMs = TEMPO_MAXIMO_MS, aCadaLinha = null, faixa = null } = {}) {
    return new Promise((resolve) => {
        const filho = spawn(comando, args, { cwd, env: process.env, stdio: ['ignore', 'pipe', 'pipe'] });
        let saida = '';
        let erro = '';
        let resto = '';
        const relogio = setTimeout(() => filho.kill('SIGTERM'), tempoMs);

        filho.stdout.on('data', (d) => {
            saida += d;
            if (!aCadaLinha) return;
            const linhas = (resto + d).split('\n');
            resto = linhas.pop();
            linhas.forEach(aCadaLinha);
        });
        filho.stderr.on('data', (d) => (erro += d));
        filho.on('error', (e) => {
            clearTimeout(relogio);
            if (faixa && faixa.filho === filho) faixa.filho = null;
            resolve({ codigo: -1, saida, erro: `${erro}${e.message}` });
        });
        filho.on('close', (codigo) => {
            clearTimeout(relogio);
            if (resto && aCadaLinha) aCadaLinha(resto);
            if (faixa && faixa.filho === filho) faixa.filho = null;
            resolve({ codigo, saida, erro });
        });

        if (faixa) faixa.filho = filho;
    });
}

/**
 * Uma rodada do Claude Code numa das faixas. A sessão continua de uma mensagem
 * para a outra (`--resume`), como uma conversa: "agora faz o mesmo na #230"
 * precisa saber o que foi "o mesmo". Cada faixa tem a sua sessão, porque cada
 * uma é um agente diferente, com ferramentas diferentes. `/novo` recomeça as duas.
 */
async function rodarClaude(chatId, pedido, nomeDaFaixa, aCadaPasso) {
    const doQuadro = nomeDaFaixa === 'quadro';

    if (doQuadro) return pedirAoResidente(chatId, pedido, aCadaPasso);

    const args = [
        '-p', pedido,
        // `stream-json` em vez de `json`: os eventos chegam enquanto ele
        // trabalha, e é deles que sai o andamento. Exige `--verbose`.
        '--output-format', 'stream-json',
        '--verbose',
        '--append-system-prompt', REGRAS_DO_CODIGO,
        // O agente de código precisa editar arquivos e rodar a suíte sem
        // ninguém para clicar em "permitir". Roda num ambiente isolado, e o
        // dano possível é o do próprio clone — produção só se alcança pelo MCP,
        // que tem as regras do quadro, e pela tag, que ele não pode criar.
        '--dangerously-skip-permissions',
    ];

    if (MCP_CONFIG) args.push('--mcp-config', MCP_CONFIG, '--strict-mcp-config');

    const sessao = estado.sessoes[chatId]?.[nomeDaFaixa];
    if (sessao) args.push('--resume', sessao);

    let resultado = null;

    const { codigo, saida, erro } = await executar(CLAUDE, args, {
        cwd: REPO,
        tempoMs: TEMPO_MAXIMO_MS,
        faixa: faixas[nomeDaFaixa],
        aCadaLinha(linha) {
            let evento;
            try {
                evento = JSON.parse(linha);
            } catch {
                return;
            }

            if (evento.type === 'result') {
                resultado = evento;
                return;
            }

            if (evento.type !== 'assistant') return;

            for (const bloco of evento.message?.content ?? []) {
                if (bloco.type === 'tool_use') aCadaPasso?.(descreverPasso(bloco.name, bloco.input));
            }
        },
    });

    if (resultado?.session_id) {
        estado.sessoes[chatId] = { ...estado.sessoes[chatId], [nomeDaFaixa]: resultado.session_id };
        gravarEstado();
    }

    if (!resultado?.result) {
        // Sessão perdida (a máquina reiniciou, o Claude atualizou): recomeça na
        // próxima em vez de falhar para sempre com o mesmo id.
        if (/session|resume/i.test(erro + saida) && estado.sessoes[chatId]) {
            delete estado.sessoes[chatId][nomeDaFaixa];
            gravarEstado();
        }

        if (codigo === null || codigo === 143) return 'Interrompido antes de terminar.';

        return `O Claude não respondeu (código ${codigo}).\n${(erro || saida).trim().slice(-1500)}`;
    }

    return resultado.result;
}

// ---------- o agente do quadro, residente ----------

/**
 * O agente do quadro não é um processo por pedido: é um processo que fica
 * aberto, recebendo um pedido por linha (`--input-format stream-json`).
 *
 * Medido em 01/10/2026: a mesma consulta levava ~9 s abrindo um Claude Code
 * novo a cada mensagem e 2,5 a 4 s com ele já aberto. Trocar de modelo não
 * mudava nada (Opus, Sonnet e Haiku deram todos 8 a 10 s) — o tempo estava no
 * arranque do processo e na conexão com o MCP, não em quem pensa.
 *
 * Só o quadro é residente. A faixa de código continua um processo por pedido:
 * lá o arranque é ruído perto de uma suíte de dois minutos, e um processo de
 * vida longa com permissão de editar e executar é exatamente o que não se quer
 * deixar aberto.
 *
 * @type {Map<number, {filho: import('node:child_process').ChildProcess, pendente: object|null, pedidos: number, ultimoUso: number, resto: string, erro: string, retomou: boolean}>}
 */
const residentes = new Map();

function abrirResidente(chatId) {
    const args = [
        '-p',
        '--input-format', 'stream-json',
        '--output-format', 'stream-json',
        '--verbose',
        '--append-system-prompt', REGRAS_DO_QUADRO,
        // Só as ferramentas do MCP. Tudo o mais o modo headless recusa sozinho:
        // este agente não edita nem executa nada, e por isso não precisa — nem
        // deve — pular as permissões.
        '--allowedTools', `mcp__${SERVIDOR_MCP}`,
    ];

    if (MCP_CONFIG) args.push('--mcp-config', MCP_CONFIG, '--strict-mcp-config');

    const sessao = estado.sessoes[chatId]?.quadro;
    if (sessao) args.push('--resume', sessao);

    // Com um `cwd` que não existe, o erro que volta é "spawn … ENOENT", que
    // parece dizer que o Claude sumiu.
    mkdirSync(PASTA_DO_QUADRO, { recursive: true });

    const filho = spawn(CLAUDE, args, {
        cwd: PASTA_DO_QUADRO,
        // As nove ferramentas do MCP carregadas de saída: com a busca de
        // ferramentas ligada, o primeiro pedido gasta uma ida e volta só para
        // descobrir que `ver_tarefa` existe.
        env: { ...process.env, ENABLE_TOOL_SEARCH: 'false' },
        stdio: ['pipe', 'pipe', 'pipe'],
    });

    const residente = { filho, pendente: null, pedidos: 0, ultimoUso: Date.now(), resto: '', erro: '', retomou: Boolean(sessao) };

    filho.stdout.on('data', (d) => {
        const linhas = (residente.resto + d).split('\n');
        residente.resto = linhas.pop();

        for (const linha of linhas) {
            let evento;
            try {
                evento = JSON.parse(linha);
            } catch {
                continue;
            }

            if (evento.session_id && estado.sessoes[chatId]?.quadro !== evento.session_id) {
                estado.sessoes[chatId] = { ...estado.sessoes[chatId], quadro: evento.session_id };
                gravarEstado();
            }

            if (evento.type === 'assistant') {
                for (const bloco of evento.message?.content ?? []) {
                    if (bloco.type === 'tool_use') residente.pendente?.aCadaPasso?.(descreverPasso(bloco.name, bloco.input));
                }
            }

            if (evento.type === 'result') concluir(residente, { resposta: evento.result ?? '' });
        }
    });

    filho.stderr.on('data', (d) => (residente.erro = (residente.erro + d).slice(-2000)));
    filho.on('error', (e) => (residente.erro += e.message));
    filho.on('close', (codigo) => {
        if (residentes.get(chatId) === residente) residentes.delete(chatId);
        if (faixas.quadro.filho === filho) faixas.quadro.filho = null;
        concluir(residente, { morreu: true, codigo });
    });

    residentes.set(chatId, residente);

    return residente;
}

function concluir(residente, desfecho) {
    const pendente = residente.pendente;
    if (!pendente) return;
    residente.pendente = null;
    clearTimeout(pendente.relogio);
    pendente.resolve(desfecho);
}

function fecharResidente(chatId) {
    const residente = residentes.get(chatId);
    if (!residente) return;
    residentes.delete(chatId);
    // Fechar a entrada é o jeito educado: ele termina o que tem e sai sozinho.
    residente.filho.stdin.end();
}

async function pedirAoResidente(chatId, pedido, aCadaPasso, tentativa = 1) {
    let residente = residentes.get(chatId);

    if (residente && (residente.pedidos >= RESIDENTE_MAX_PEDIDOS || Date.now() - residente.ultimoUso > RESIDENTE_MAX_PARADO_MS)) {
        fecharResidente(chatId);
        // Conversa nova de propósito: é a conversa acumulada que se quer largar.
        if (estado.sessoes[chatId]) delete estado.sessoes[chatId].quadro;
        residente = null;
    }

    residente ??= abrirResidente(chatId);
    residente.pedidos += 1;
    residente.ultimoUso = Date.now();
    faixas.quadro.filho = residente.filho;

    const desfecho = await new Promise((resolve) => {
        residente.pendente = {
            resolve,
            aCadaPasso,
            relogio: setTimeout(() => residente.filho.kill('SIGTERM'), TEMPO_DO_QUADRO_MS),
        };

        residente.filho.stdin.write(`${JSON.stringify({ type: 'user', message: { role: 'user', content: pedido } })}\n`);
    });

    if (!desfecho.morreu) return desfecho.resposta;

    // Morreu antes de responder. Se ele tinha sido aberto retomando uma sessão,
    // o mais provável é a sessão não existir mais (o Claude atualizou, a pasta
    // mudou): tenta UMA vez do zero antes de devolver o erro.
    if (residente.retomou && tentativa === 1 && desfecho.codigo !== null && desfecho.codigo !== 143) {
        if (estado.sessoes[chatId]) delete estado.sessoes[chatId].quadro;
        gravarEstado();

        return pedirAoResidente(chatId, pedido, aCadaPasso, 2);
    }

    if (desfecho.codigo === null || desfecho.codigo === 143) return 'Interrompido antes de terminar.';

    return `O Claude não respondeu (código ${desfecho.codigo}).\n${residente.erro.trim().slice(-1500)}`;
}

/**
 * Para onde vai um pedido.
 *
 * Primeiro o quadro, que responde em segundos e sabe dizer "isto não é
 * comigo". A exceção é a conversa de código em curso: se a última resposta
 * veio de lá e a faixa está livre, o pedido vai direto — "sim, pode commitar"
 * é continuação, e o agente do quadro não saberia do que se está falando.
 * Com a faixa de código OCUPADA, o quadro atende primeiro mesmo assim: é
 * exatamente o caso que as duas faixas existem para resolver.
 */
function encaminhar(chatId, pedido) {
    const direto = estado.ultimaFaixa[chatId] === 'codigo' && !faixas.codigo.ocupado;

    naFaixa(direto ? 'codigo' : 'quadro', chatId, pedido);
}

function naFaixa(nome, chatId, pedido) {
    enfileirar(nome, async () => {
        const andamento = await criarAndamento(chatId, nome === 'quadro' ? 'Vendo no quadro' : 'Trabalhando no código');
        const resposta = await rodarClaude(chatId, pedido, nome, andamento.passo);

        console.log(`faixa ${nome}: pedido de ${pedido.length} caracteres respondido em ${duracao(andamento.decorrido())}`);

        if (nome === 'quadro' && resposta.includes(MARCADOR_DE_CODIGO)) {
            await andamento.fim(faixas.codigo.ocupado
                ? 'Isso é trabalho de código. Entrou na fila, atrás do que já está rodando.'
                : 'Isso é trabalho de código. Passando para o agente do repositório.');

            return naFaixa('codigo', chatId, pedido);
        }

        estado.ultimaFaixa[chatId] = nome;
        gravarEstado();

        // Pedido rápido: a própria mensagem de andamento VIRA a resposta. Quem
        // perguntou ainda está olhando o chat, e "Concluído em 11s." seguido da
        // resposta em outra mensagem fez a resposta passar despercebida duas
        // vezes (01/10/2026). Pedido demorado: a resposta chega como mensagem
        // NOVA, porque edição não notifica — e depois de dez minutos de suíte
        // a pessoa já saiu do chat e precisa do aviso no celular.
        const rapido = andamento.decorrido() < RESPOSTA_NO_LUGAR_ATE_MS && resposta.trim().length <= 4000;

        if (rapido && await andamento.fim(resposta.trim() || '(sem resposta)')) return;

        await andamento.fim();
        await responder(chatId, resposta);
    });
}

// ---------- áudio: baixar e transcrever ----------

/**
 * Um áudio vira texto antes de virar pedido. O texto entendido é mostrado de
 * volta ("Entendi: …") de propósito: quem ditou precisa ver o que o Claude vai
 * ler, porque um "228" ouvido como "238" muda a tarefa que ele vai pegar.
 */
async function transcrever(mensagem) {
    const arquivo = mensagem.voice ?? mensagem.audio;
    const info = await telegram('getFile', { file_id: arquivo.file_id });
    const resposta = await fetch(`https://api.telegram.org/file/bot${TOKEN}/${info.file_path}`);
    if (!resposta.ok) throw new Error(`download do áudio: ${resposta.status}`);

    mkdirSync(PASTA_DE_AUDIO, { recursive: true });
    const caminho = `${PASTA_DE_AUDIO}/${arquivo.file_unique_id}.ogg`;
    writeFileSync(caminho, Buffer.from(await resposta.arrayBuffer()));

    try {
        if (TRANSCRITOR_RESIDENTE) return await transcreverNoResidente(caminho);

        const r = await executar(TRANSCRITOR, [caminho], { tempoMs: TEMPO_DA_TRANSCRICAO_MS });
        if (r.codigo !== 0) throw new Error((r.erro || r.saida).trim().slice(-400) || `código ${r.codigo}`);
        return r.saida.trim();
    } finally {
        // O áudio não fica no disco: já virou texto, e voz é dado pessoal.
        rmSync(caminho, { force: true });
    }
}

/**
 * O transcritor aberto, com o modelo já na memória: um caminho por linha na
 * entrada, um JSON por linha na saída. Poupa a carga do modelo a cada áudio.
 * Um áudio por vez — a corrente de promessas é a fila.
 */
let transcritor = null;
let filaDoTranscritor = Promise.resolve();

function abrirTranscritor() {
    const filho = spawn(TRANSCRITOR, ['--servidor'], { env: process.env, stdio: ['pipe', 'pipe', 'pipe'] });
    const t = { filho, resto: '', esperando: null, erro: '' };

    filho.stdout.on('data', (d) => {
        const linhas = (t.resto + d).split('\n');
        t.resto = linhas.pop();
        for (const linha of linhas) {
            let dito;
            try {
                dito = JSON.parse(linha);
            } catch {
                continue;
            }
            // A primeira linha é só o aviso de que o modelo carregou.
            if (dito.pronto) continue;
            t.esperando?.(dito);
            t.esperando = null;
        }
    });
    filho.stderr.on('data', (d) => (t.erro = (t.erro + d).slice(-600)));
    filho.on('error', (e) => (t.erro += e.message));
    filho.on('close', () => {
        if (transcritor === t) transcritor = null;
        t.esperando?.({ erro: t.erro.trim() || 'o transcritor fechou' });
        t.esperando = null;
    });

    return t;
}

function transcreverNoResidente(caminho) {
    const vez = filaDoTranscritor.then(() => new Promise((resolve, reject) => {
        transcritor ??= abrirTranscritor();
        const t = transcritor;
        // Matar o processo é o que destrava: ele fecha, o `close` rejeita este
        // áudio, e o próximo abre um transcritor novo.
        const relogio = setTimeout(() => {
            t.erro = 'a transcrição passou do tempo';
            t.filho.kill('SIGKILL');
        }, TEMPO_DA_TRANSCRICAO_MS);

        t.esperando = (dito) => {
            clearTimeout(relogio);
            if (dito.erro) reject(new Error(dito.erro));
            else resolve(dito.texto);
        };

        t.filho.stdin.write(`${caminho}\n`);
    }));

    // A fila segue mesmo que este áudio tenha falhado.
    filaDoTranscritor = vez.catch(() => {});

    return vez;
}

// ---------- comandos ----------

// O formato das tags deste repositório: vAAAA.MM.DD, com um .N quando há mais
// de uma publicação no dia (v2026.09.21.5). É o que o vigia de produção lê.
const VERSAO = /^v\d{4}\.\d{2}\.\d{2}(\.\d+)?$/;

function situacaoDaFaixa(nome, rotulo) {
    const faixa = faixas[nome];
    const fila = faixa.fila.length ? `, ${faixa.fila.length} na fila` : '';

    return `${rotulo}: ${faixa.ocupado ? 'trabalhando' : 'livre'}${fila}`;
}

async function comando(chatId, texto) {
    const [nome, ...resto] = texto.trim().split(/\s+/);
    const argumento = resto.join(' ');

    switch (nome) {
        case '/start':
        case '/ajuda':
            return responder(chatId, [
                'Sou o agente do AlfaMatriz. Mande um pedido em texto ou em áudio.',
                'Pedidos do quadro e da agenda saem em segundos, mesmo com uma tarefa de código rodando.',
                '',
                '/status — o que está rodando, a branch e os últimos commits',
                '/publicar v2026.09.30.1 — cria e envia a tag de produção (a partir da main)',
                '/agendar HH:MM pedido — roda o pedido hoje nesse horário (ou AAAA-MM-DD HH:MM pedido)',
                '/agendados — o que está marcado · /cancelar N — desmarca',
                '/parar — interrompe o que estiver rodando',
                '/novo — começa uma conversa nova com o Claude',
            ].join('\n'));

        case '/status': {
            const git = await executar('git', ['-c', 'color.ui=never', 'status', '-sb'], { tempoMs: 30_000 });
            const log = await executar('git', ['log', '--oneline', '-5'], { tempoMs: 30_000 });
            return responder(chatId, [
                situacaoDaFaixa('quadro', 'Quadro'),
                situacaoDaFaixa('codigo', 'Código'),
                '',
                git.saida.trim(),
                '',
                log.saida.trim(),
            ].join('\n'));
        }

        case '/publicar': {
            if (!VERSAO.test(argumento)) {
                return responder(chatId, 'Diga a versão no formato vAAAA.MM.DD ou vAAAA.MM.DD.N, por exemplo /publicar v2026.09.30.1.');
            }
            // A tag nasce da main remota, e não do que está no clone: o clone é
            // a bancada do agente, e o que vai para o ar é o que a esteira já
            // levou ao staging.
            const passos = [
                ['git', ['fetch', '--tags', 'origin', 'main']],
                ['git', ['tag', '-a', argumento, '-m', `Publicado pelo Telegram em ${new Date().toISOString()}`, 'origin/main']],
                ['git', ['push', 'origin', argumento]],
            ];
            for (const [cmd, args] of passos) {
                const r = await executar(cmd, args, { tempoMs: 120_000 });
                if (r.codigo !== 0) {
                    return responder(chatId, `Falhou em "${cmd} ${args.join(' ')}":\n${(r.erro || r.saida).trim().slice(-1200)}`);
                }
            }
            return responder(chatId, `Tag ${argumento} criada na main e enviada. O vigia de produção publica em até 5 minutos.`);
        }

        case '/parar': {
            // Para o que está RODANDO, nas duas faixas, e esvazia as filas: quem
            // manda parar quer silêncio, não o próximo pedido começando sozinho.
            const rodando = Object.values(faixas).filter((f) => f.filho);
            const naFila = Object.values(faixas).reduce((total, f) => total + f.fila.length, 0);

            Object.values(faixas).forEach((f) => {
                f.fila.length = 0;
                f.filho?.kill('SIGTERM');
            });

            if (!rodando.length && !naFila) return responder(chatId, 'Nada rodando.');

            return responder(chatId, `Interrompido.${naFila ? ` ${naFila} pedido(s) da fila descartado(s).` : ''}`);
        }

        case '/novo':
            fecharResidente(chatId);
            delete estado.sessoes[chatId];
            delete estado.ultimaFaixa[chatId];
            gravarEstado();
            return responder(chatId, 'Conversa nova. O próximo pedido começa do zero.');

        case '/agendar': {
            const quando = interpretarHorario(argumento);
            if (!quando) {
                return responder(chatId, 'Use /agendar HH:MM pedido, ou /agendar AAAA-MM-DD HH:MM pedido.');
            }
            estado.agendados.push({ id: Date.now(), chatId, em: quando.em.toISOString(), pedido: quando.pedido });
            gravarEstado();
            return responder(chatId, `Marcado para ${formatar(quando.em)}: ${quando.pedido}`);
        }

        case '/agendados':
            if (!estado.agendados.length) return responder(chatId, 'Nada marcado.');
            return responder(chatId, estado.agendados
                .map((a, i) => `${i + 1}. ${formatar(new Date(a.em))} — ${a.pedido}`)
                .join('\n'));

        case '/cancelar': {
            const indice = Number(argumento) - 1;
            const [removido] = indice >= 0 ? estado.agendados.splice(indice, 1) : [];
            gravarEstado();
            return responder(chatId, removido ? `Desmarcado: ${removido.pedido}` : 'Não achei esse número. Veja /agendados.');
        }

        default:
            return responder(chatId, `Não conheço ${nome}. Mande /ajuda.`);
    }
}

/** "22:00 texto" → hoje às 22h (ou amanhã, se já passou); "2026-10-01 08:30 texto" → essa data. */
function interpretarHorario(texto) {
    let m = texto.match(/^(\d{4}-\d{2}-\d{2})\s+(\d{1,2}):(\d{2})\s+(.+)$/s);
    if (m) {
        const em = new Date(`${m[1]}T${m[2].padStart(2, '0')}:${m[3]}:00`);
        return Number.isNaN(em.getTime()) ? null : { em, pedido: m[4].trim() };
    }
    m = texto.match(/^(\d{1,2}):(\d{2})\s+(.+)$/s);
    if (!m) return null;
    const em = new Date();
    em.setHours(Number(m[1]), Number(m[2]), 0, 0);
    if (em <= new Date()) em.setDate(em.getDate() + 1);
    return { em, pedido: m[3].trim() };
}

function formatar(data) {
    return data.toLocaleString('pt-BR', { timeZone: process.env.TZ ?? 'America/Sao_Paulo' });
}

// ---------- o que ele marcou: conferido a cada meio minuto ----------

setInterval(async () => {
    const agora = new Date();
    const vencidos = estado.agendados.filter((a) => new Date(a.em) <= agora);
    if (!vencidos.length) return;
    estado.agendados = estado.agendados.filter((a) => !vencidos.includes(a));
    gravarEstado();
    for (const a of vencidos) {
        try {
            await responder(a.chatId, `Está na hora do que você marcou: ${a.pedido}`);
        } catch (e) {
            console.error(e.message);
        }
        encaminhar(a.chatId, a.pedido);
    }
}, 30_000);

// ---------- o laço principal ----------

async function tratar(mensagem) {
    const chatId = mensagem.chat.id;
    const de = String(mensagem.from?.id ?? '');
    const texto = (mensagem.text ?? '').trim();

    if (!PERMITIDOS.has(de)) {
        // A frase diz o id de propósito: é assim que o dono descobre o próprio
        // número para pôr em TELEGRAM_PERMITIDOS na primeira vez.
        console.warn(`mensagem de ${de} (${mensagem.from?.username ?? '?'}) ignorada`);
        return responder(chatId, `Não conheço você. Seu id é ${de}.`);
    }

    if (mensagem.voice || mensagem.audio) {
        if (!TRANSCRITOR) {
            return responder(chatId, 'Ainda não entendo áudio aqui. Escreva o pedido.');
        }

        // Fora das faixas: transcrever leva segundos, e o áudio não deveria
        // esperar a suíte de outro pedido para ser ouvido. Sem `await` — o
        // laço principal precisa seguir livre para ouvir um `/parar`.
        (async () => {
            const andamento = await criarAndamento(chatId, 'Ouvindo');

            let ditado;
            try {
                ditado = await transcrever(mensagem);
            } catch (e) {
                console.log(`áudio de ${(mensagem.voice ?? mensagem.audio).duration ?? '?'}s: falhou em ${duracao(andamento.decorrido())} (${e.message})`);
                return andamento.fim(`Não consegui transcrever o áudio: ${e.message}. Tente de novo ou escreva.`);
            }

            // Só tempos e tamanhos no log — nunca o que foi dito.
            console.log(`áudio de ${(mensagem.voice ?? mensagem.audio).duration ?? '?'}s: transcrito em ${duracao(andamento.decorrido())}, ${ditado.length} caracteres`);

            await andamento.fim(`Entendi: "${ditado}"`);

            if (ditado.startsWith('/')) return comando(chatId, ditado);

            encaminhar(chatId, ditado);
        })().catch((e) => console.error(e));

        return;
    }

    if (!texto) return;

    if (texto.startsWith('/')) {
        return comando(chatId, texto);
    }

    encaminhar(chatId, texto);
}

async function ouvir() {
    console.log(`ponte no ar; repo ${REPO}; permitidos: ${[...PERMITIDOS].join(', ') || 'ninguém (ainda)'}`);

    // Aquecer: o agente do quadro de quem pode comandar e o transcritor já
    // sobem abertos, para o PRIMEIRO pedido do dia não pagar o arranque. Abrir
    // não gasta nada — nenhum dos dois faz coisa alguma até receber uma linha.
    // (Num chat privado, o id do chat é o id da pessoa.)
    for (const id of PERMITIDOS) abrirResidente(Number(id));
    if (TRANSCRITOR && TRANSCRITOR_RESIDENTE) transcritor = abrirTranscritor();

    for (;;) {
        try {
            const atualizacoes = await telegram('getUpdates', { offset: estado.offset, timeout: 50, allowed_updates: ['message'] });
            for (const u of atualizacoes) {
                estado.offset = u.update_id + 1;
                gravarEstado();
                // Um erro ao tratar UMA mensagem não pode travar as seguintes.
                if (u.message) await tratar(u.message).catch((e) => console.error(e));
            }
        } catch (e) {
            console.error(e.message);
            await new Promise((r) => setTimeout(r, 5_000));
        }
    }
}

ouvir();
