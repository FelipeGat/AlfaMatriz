// O Pedro no runner (docs/specs/suporte-pedro-whatsapp): a IA roda SEM ferramentas,
// numa pasta vazia, com o pedido pelo stdin; e o que volta (ou o erro) vai ao Matriz.
import assert from 'node:assert/strict';
import { atenderSuporte, responderComIa } from '../../deploy/central-ia/suporte.mjs';

const chamadas = [];
const executar = async (bin, args, entrada, cwd) => {
    chamadas.push({ bin, args, entrada, cwd });
    return { codigo: 0, killed: false, stdout: JSON.stringify({ type: 'result', is_error: false, result: '<<<PEDRO {"resposta":"oi","acao":"responder"} PEDRO>>>' }) };
};
const texto = await responderComIa({ agente: 'claude', modelo: 'sonnet', prompt: 'PROMPT' }, { claude: '/bin/claude' }, { executar });
assert.match(texto, /<<<PEDRO/);
const { args, entrada, cwd } = chamadas[0];
assert.equal(entrada, 'PROMPT', 'o pedido vai pelo stdin');
assert.ok(args.includes('--disallowedTools') && args.includes('Bash') && args.includes('Read') && args.includes('WebFetch'), 'nenhuma ferramenta');
assert.ok(args.includes('--no-session-persistence'));
assert.deepEqual(args.slice(args.indexOf('--model'), args.indexOf('--model') + 2), ['--model', 'sonnet']);
assert.match(cwd, /pedro-/, 'pasta temporária vazia, fora dos projetos');

await assert.rejects(responderComIa({ agente: 'claude', prompt: 'x' }, { claude: '/bin/claude' },
    { executar: async () => ({ killed: false, stdout: JSON.stringify({ is_error: true, result: 'Credit balance is too low' }) }) }), /Credit balance/);
await assert.rejects(responderComIa({ agente: 'claude', prompt: 'x' }, { claude: '/bin/claude' }, { executar: async () => ({ killed: true, stdout: '' }) }), /passou do tempo/);

const enviados = [];
const api = async (caminho, corpo) => {
    enviados.push([caminho, corpo]);
    return caminho === 'suporte/proxima' ? { status: 200, json: { id: 7, agente: 'claude', prompt: 'P' } } : { status: 200, json: { ok: true } };
};
const r = await atenderSuporte('wsl', { api, agentes: { claude: '/bin/claude' }, responder: async () => { throw new Error('máquina sem login'); } });
assert.equal(r.id, 7);
assert.deepEqual(enviados[1], ['suporte/7/resposta', { maquina: 'wsl', texto: null, erro: 'máquina sem login' }], 'o erro também volta, para o Matriz responder sozinho');
assert.equal((await atenderSuporte('wsl', { api: async () => ({ status: 204 }), agentes: {} })).status, 204);
console.log('PASSOU: suporte — IA sem ferramentas pelo stdin, numa pasta vazia; resposta e erro voltam ao Matriz.');
