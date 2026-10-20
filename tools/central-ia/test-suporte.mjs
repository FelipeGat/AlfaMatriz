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
// Fase 2: fotos para a IA.
{
    let visto;
    const executar = async (bin, args, entrada) => { visto = { args, entrada }; return { codigo: 0, killed: false, stdout: '{"type":"system"}\n{"type":"result","result":"<<<PEDRO {} PEDRO>>>","is_error":false}\n' }; };
    const foto = { id: 9, mime: 'image/jpeg', base64: Buffer.from('JPEG').toString('base64') };
    const t = await responderComIa({ agente: 'claude', modelo: 'sonnet', prompt: 'PROMPT', imagens: [foto, { id: 10, mime: 'application/pdf', base64: 'x' }] }, { claude: '/bin/claude' }, { executar });
    assert.equal(t, '<<<PEDRO {} PEDRO>>>');
    assert.ok(visto.args.includes('--input-format') && visto.args.includes('stream-json'), 'com foto, entrada em stream-json');
    const msg = JSON.parse(visto.entrada);
    assert.deepEqual(msg.message.content.map(c => c.type), ['image', 'text'], 'só a foto válida vai, e o prompt por último');
    assert.equal(msg.message.content[0].source.data, foto.base64);
    assert.ok(visto.args.includes('--disallowedTools'), 'continua sem ferramentas');
}
{
    let visto;
    const executar = async (bin, args, entrada, dir) => { visto = args; (await import('node:fs')).writeFileSync(args[args.indexOf('-o') + 1], 'ok'); return { codigo: 0, killed: false, stdout: '' }; };
    await responderComIa({ agente: 'codex', prompt: 'P', imagens: [{ id: 1, mime: 'image/png', base64: 'UE5H' }] }, { codex: '/bin/codex' }, { executar });
    assert.equal(visto[visto.indexOf('-i') + 1].endsWith('.png'), true, 'Codex recebe a foto com -i');
}
// Fase 2: áudio transcrito no lugar do marcador, e a transcrição volta ao Matriz.
{
    const enviados2 = [];
    const api2 = async (caminho, corpo) => { enviados2.push([caminho, corpo]);
        return caminho === 'suporte/proxima' ? { status: 200, json: { id: 8, agente: 'claude', prompt: 'CLIENTE: ⟦AUDIO:5⟧\nCLIENTE: ⟦AUDIO:6⟧', audios: [{ id: 5, extensao: 'ogg', base64: 'T0dH' }] } } : { status: 200, json: { ok: true } }; };
    let prompt;
    await atenderSuporte('wsl', { api: api2, agentes: { claude: '/bin/claude' }, temWhisper: async () => true,
        transcrever: async () => 'a catraca apagou', responder: async job => { prompt = job.prompt; return 'R'; } });
    assert.equal(prompt, 'CLIENTE: [áudio, transcrito] a catraca apagou\nCLIENTE: [áudio não transcrito]');
    assert.deepEqual(enviados2[1][1].transcricoes, [{ id: 5, texto: 'a catraca apagou' }]);
}
console.log('PASSOU: suporte — IA sem ferramentas pelo stdin, numa pasta vazia; fotos para a IA (Claude e Codex); áudio transcrito no prompt; resposta e erro voltam ao Matriz.');
