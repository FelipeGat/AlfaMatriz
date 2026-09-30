#!/usr/bin/env node
/**
 * A ponte entre o Telegram e o Claude Code do LXC — o agente do AlfaMatriz.
 *
 * O que ela faz: fica ouvindo o bot (@rossini_server_bot, o mesmo do changelog),
 * e cada mensagem de quem pode comandar vira uma rodada do Claude Code em modo
 * headless dentro do clone do repositório. A resposta volta pelo mesmo chat.
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
 * - Não roda duas coisas ao mesmo tempo. Uma fila, um Claude por vez: o host
 *   deste LXC divide disco e memória com os stagings (ver o CLAUDE.md do LXC),
 *   e dois agentes rodando a suíte em paralelo é o jeito de derrubar tudo.
 *
 * Sem dependência de npm, de propósito: `fetch` e `child_process` bastam, e um
 * `npm install` a menos é um `npm install` a menos para quebrar numa máquina
 * que ninguém está olhando.
 *
 * Autorizada pelo dono do produto em 30/09/2026, inclusive o modo sem
 * confirmação de permissões dentro do LXC de desenvolvimento.
 *
 * Configuração pelo ambiente (ver `alfa-agente.env.example`).
 */

import { spawn } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';

const TOKEN = obrigatorio('TELEGRAM_TOKEN');
const PERMITIDOS = new Set(
    (process.env.TELEGRAM_PERMITIDOS ?? '').split(',').map((s) => s.trim()).filter(Boolean),
);
const REPO = process.env.AGENTE_REPO ?? '/opt/dev/AlfaMatriz';
const ESTADO = process.env.AGENTE_ESTADO ?? '/var/lib/alfa-agente/estado.json';
const CLAUDE = process.env.AGENTE_CLAUDE ?? 'claude';
// Quatro horas: uma tarefa de código com suíte pode levar muito, mas nada
// legítimo passa disso. Depois, o processo é derrubado e o chat fica sabendo.
const TEMPO_MAXIMO_MS = Number(process.env.AGENTE_TEMPO_MAXIMO_MIN ?? 240) * 60_000;
const API = `https://api.telegram.org/bot${TOKEN}`;

/**
 * O que o agente precisa saber além do CLAUDE.md do repositório, que ele lê
 * sozinho: que está falando por Telegram, e o que nunca pode fazer daqui.
 */
const REGRAS_DO_AGENTE = `
Você está rodando como o agente do AlfaMatriz num servidor, comandado pelo Telegram por ${process.env.AGENTE_DONO ?? 'o dono do produto'}.
- Responda em português, texto puro (sem Markdown), curto: o Telegram é um chat, não um relatório. Até uns 2500 caracteres.
- Para mexer no quadro e na agenda do sistema NO AR, use o servidor MCP "alfamatriz-producao". O "alfamatriz" local é só do banco de desenvolvimento deste clone.
- Trabalhe na branch Rossini. Antes de dizer que algo está pronto, rode a suíte (php artisan test) e diga o resultado. Commit e push só quando pedidos.
- NUNCA crie tag nem faça deploy. Quando algo estiver pronto para produção, diga qual versão publicar e pare: quem publica é a pessoa, com /publicar.
- Se precisar de uma decisão que é dela, pergunte e pare em vez de escolher.
`.trim();

function obrigatorio(nome) {
    const valor = process.env[nome];
    if (!valor) {
        console.error(`Defina ${nome} no ambiente (ver alfa-agente.env.example).`);
        process.exit(1);
    }
    return valor;
}

// ---------- estado em disco: offset do Telegram, sessão por chat, agendamentos ----------

function lerEstado() {
    try {
        return { offset: 0, sessoes: {}, agendados: [], ...JSON.parse(readFileSync(ESTADO, 'utf8')) };
    } catch {
        return { offset: 0, sessoes: {}, agendados: [] };
    }
}

function gravarEstado(estado) {
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

// ---------- executar coisas ----------

let emExecucao = null;

function executar(comando, args, { cwd = REPO, tempoMs = TEMPO_MAXIMO_MS } = {}) {
    return new Promise((resolve) => {
        const filho = spawn(comando, args, { cwd, env: process.env, stdio: ['ignore', 'pipe', 'pipe'] });
        let saida = '';
        let erro = '';
        const relogio = setTimeout(() => filho.kill('SIGTERM'), tempoMs);
        filho.stdout.on('data', (d) => (saida += d));
        filho.stderr.on('data', (d) => (erro += d));
        filho.on('close', (codigo) => {
            clearTimeout(relogio);
            if (emExecucao === filho) emExecucao = null;
            resolve({ codigo, saida, erro });
        });
        emExecucao = filho;
    });
}

/**
 * Uma rodada do Claude Code. A sessão continua de uma mensagem para a outra
 * (`--resume`), como uma conversa: "agora faz o mesmo no AlfaGym" precisa
 * saber o que foi "o mesmo". `/novo` recomeça.
 */
async function rodarClaude(chatId, pedido) {
    const args = [
        '-p', pedido,
        '--output-format', 'json',
        '--append-system-prompt', REGRAS_DO_AGENTE,
        // O agente precisa editar arquivos e rodar a suíte sem ninguém para
        // clicar em "permitir". Este LXC é de desenvolvimento e o dano possível
        // é o do próprio clone — produção só se alcança pelo MCP, que tem as
        // regras do quadro, e pela tag, que ele não pode criar.
        '--dangerously-skip-permissions',
    ];
    const sessao = estado.sessoes[chatId];
    if (sessao) args.push('--resume', sessao);

    const { codigo, saida, erro } = await executar(CLAUDE, args);

    let resultado = null;
    try {
        resultado = JSON.parse(saida);
    } catch {
        // saída não é JSON: erro antes de o Claude começar
    }

    if (resultado?.session_id) {
        estado.sessoes[chatId] = resultado.session_id;
        gravarEstado(estado);
    }

    if (codigo !== 0 && !resultado?.result) {
        // Sessão perdida (o LXC reiniciou, o Claude atualizou): recomeça na
        // próxima em vez de falhar para sempre com o mesmo id.
        if (/session|resume/i.test(erro + saida)) {
            delete estado.sessoes[chatId];
            gravarEstado(estado);
        }
        return `O Claude não respondeu (código ${codigo}).\n${(erro || saida).trim().slice(-1500)}`;
    }

    return resultado?.result ?? saida;
}

// ---------- fila: um pedido por vez ----------

const fila = [];
let ocupado = false;

function enfileirar(tarefa) {
    fila.push(tarefa);
    if (!ocupado) proximo();
}

async function proximo() {
    const tarefa = fila.shift();
    if (!tarefa) {
        ocupado = false;
        return;
    }
    ocupado = true;
    try {
        await tarefa();
    } catch (e) {
        console.error(e);
    }
    proximo();
}

// ---------- comandos ----------

const VERSAO = /^v\d+\.\d+\.\d+$/;

async function comando(chatId, texto) {
    const [nome, ...resto] = texto.trim().split(/\s+/);
    const argumento = resto.join(' ');

    switch (nome) {
        case '/start':
        case '/ajuda':
            return responder(chatId, [
                'Sou o agente do AlfaMatriz. Mande um pedido em texto e eu trabalho no repositório ou no quadro.',
                '',
                '/status — branch, últimos commits e o que está rodando',
                '/publicar vX.Y.Z — cria e envia a tag de produção (a partir da main)',
                '/agendar HH:MM pedido — roda o pedido hoje nesse horário (ou AAAA-MM-DD HH:MM pedido)',
                '/agendados — o que está marcado · /cancelar N — desmarca',
                '/parar — interrompe o que estiver rodando',
                '/novo — começa uma conversa nova com o Claude',
            ].join('\n'));

        case '/status': {
            const git = await executar('git', ['-c', 'color.ui=never', 'status', '-sb'], { tempoMs: 30_000 });
            const log = await executar('git', ['log', '--oneline', '-5'], { tempoMs: 30_000 });
            return responder(chatId, [
                ocupado ? 'Rodando um pedido agora.' : 'Ocioso.',
                fila.length ? `${fila.length} pedido(s) na fila.` : '',
                '',
                git.saida.trim(),
                '',
                log.saida.trim(),
            ].join('\n').replace(/\n{3,}/g, '\n\n'));
        }

        case '/publicar': {
            if (!VERSAO.test(argumento)) {
                return responder(chatId, 'Diga a versão no formato vX.Y.Z, por exemplo /publicar v1.12.0.');
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

        case '/parar':
            if (emExecucao) {
                emExecucao.kill('SIGTERM');
                return responder(chatId, 'Interrompido.');
            }
            return responder(chatId, 'Nada rodando.');

        case '/novo':
            delete estado.sessoes[chatId];
            gravarEstado(estado);
            return responder(chatId, 'Conversa nova. O próximo pedido começa do zero.');

        case '/agendar': {
            const quando = interpretarHorario(argumento);
            if (!quando) {
                return responder(chatId, 'Use /agendar HH:MM pedido, ou /agendar AAAA-MM-DD HH:MM pedido.');
            }
            estado.agendados.push({ id: Date.now(), chatId, em: quando.em.toISOString(), pedido: quando.pedido });
            gravarEstado(estado);
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
            gravarEstado(estado);
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

setInterval(() => {
    const agora = new Date();
    const vencidos = estado.agendados.filter((a) => new Date(a.em) <= agora);
    if (!vencidos.length) return;
    estado.agendados = estado.agendados.filter((a) => !vencidos.includes(a));
    gravarEstado(estado);
    for (const a of vencidos) {
        enfileirar(async () => {
            await responder(a.chatId, `Está na hora do que você marcou: ${a.pedido}`);
            await responder(a.chatId, await rodarClaude(a.chatId, a.pedido));
        });
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

    if (!texto) return;

    if (texto.startsWith('/')) {
        return comando(chatId, texto);
    }

    const esperando = ocupado;
    enfileirar(async () => {
        await responder(chatId, esperando ? 'Chegou a sua vez. Trabalhando…' : 'Recebido. Trabalhando…');
        await responder(chatId, await rodarClaude(chatId, texto));
    });
    if (esperando) await responder(chatId, 'Na fila. Aviso quando começar.');
}

async function ouvir() {
    console.log(`ponte no ar; repo ${REPO}; permitidos: ${[...PERMITIDOS].join(', ') || 'ninguém (ainda)'}`);
    for (;;) {
        try {
            const atualizacoes = await telegram('getUpdates', { offset: estado.offset, timeout: 50, allowed_updates: ['message'] });
            for (const u of atualizacoes) {
                estado.offset = u.update_id + 1;
                gravarEstado(estado);
                if (u.message) await tratar(u.message);
            }
        } catch (e) {
            console.error(e.message);
            await new Promise((r) => setTimeout(r, 5_000));
        }
    }
}

ouvir();
