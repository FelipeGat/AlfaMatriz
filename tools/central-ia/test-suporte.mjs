// O Pedro no runner (docs/specs/suporte-pedro-whatsapp): a IA roda SEM ferramentas,
// numa pasta vazia, com o pedido pelo stdin; e o que volta (ou o erro) vai ao Matriz.
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { atenderSuporte, gravarAberturas, gravarNoGestor, naPonte, responderComIa } from '../../deploy/central-ia/suporte.mjs';

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
// Vídeo (20/10/2026): quadros viram imagens para a IA, a fala entra no marcador e volta ao Matriz.
{
    const enviados3 = [];
    const api3 = async (caminho, corpo) => { enviados3.push([caminho, corpo]);
        return caminho === 'suporte/proxima' ? { status: 200, json: { id: 9, agente: 'claude', prompt: 'CLIENTE: ⟦VIDEO:7⟧', imagens: [{ id: 3, mime: 'image/png', base64: 'UE5H' }],
            videos: [{ id: 7, extensao: 'mp4', base64: 'TVA0' }] } } : { status: 200, json: { ok: true } }; };
    let recebido;
    await atenderSuporte('wsl', { api: api3, agentes: { claude: '/bin/claude' }, temWhisper: async () => true,
        analisarVideo: async (bytes, ext) => { assert.equal(ext, 'mp4'); assert.equal(bytes.toString(), 'MP4'); return { quadros: ['QTE=', 'QTI='], fala: 'ele trava aqui' }; },
        responder: async job => { recebido = job; return 'R'; } });
    assert.equal(recebido.prompt, 'CLIENTE: [VÍDEO nº 7 — 2 quadro(s) dele ANEXADO(S) como imagens, do início ao fim; veja-os] fala no vídeo: ele trava aqui');
    assert.deepEqual(recebido.imagens.map(i => [i.id, i.mime, i.base64]), [[3, 'image/png', 'UE5H'], [7, 'image/jpeg', 'QTE='], [7, 'image/jpeg', 'QTI=']]);
    assert.deepEqual(enviados3[1][1].transcricoes, [{ id: 7, texto: '[vídeo visto] ele trava aqui' }]);

    // Sem conseguir abrir: o marcador diz que fica para a equipe, e nada é registrado como visto.
    enviados3.length = 0;
    await atenderSuporte('wsl', { api: api3, agentes: { claude: '/bin/claude' }, temWhisper: async () => true,
        analisarVideo: async () => null, responder: async job => { recebido = job; return 'R'; } });
    assert.match(recebido.prompt, /não consegui abrir; fica registrado para a equipe técnica/);
    assert.equal(enviados3[1][1].transcricoes, undefined);
}
console.log('PASSOU: suporte — IA sem ferramentas pelo stdin, numa pasta vazia; fotos para a IA (Claude e Codex); áudio transcrito no prompt; vídeo em quadros e fala; resposta e erro voltam ao Matriz.');

// Gravação no Gestor (acesso-interno.md, FR-A8): o pedido vai no script, com --apply,
// sem nova tentativa; o resultado volta ao Matriz; e a ponte atende uma chamada por vez.
{
    const pasta = mkdtempSync(join(tmpdir(), 'ponte-'));
    let script = '';
    let argumentos = [];
    const executar = async (programa, args) => {
        argumentos = args;
        script = readFileSync(join(pasta, 'pedro_gestor_gravar.php'), 'utf8');
        return { stdout: 'lixo\nSUPORTE_GESTOR_GRAVADO:{"ok":true,"modo":"reabrir","numero":"700","id":9}\n' };
    };
    const pedido = { id: 1, modo: 'reabrir', atendimento: '700', cliente_id: 30, descricao: 'x', autor_email: 'f@x' };
    const r = await gravarNoGestor(`py|C:\\tmp\\run_prod.py|C:\\tmp\\secretario_gestor.php|${pasta}`, pedido, { executar });
    assert.deepEqual(r, { ok: true, modo: 'reabrir', numero: '700', id: 9 });
    assert.equal(argumentos.at(-1), '--apply');
    assert.ok(script.includes(`base64_decode('${Buffer.from(JSON.stringify(pedido)).toString('base64')}')`), 'o pedido entra no lugar do marcador do código');
    assert.ok(!existsSync(join(pasta, 'pedro_gestor_gravar.php')), 'o script não fica na pasta da ponte');

    let vezes = 0;
    const semResposta = await gravarNoGestor(`py|s|C:\\tmp\\a.php|${pasta}`, pedido, { executar: async () => { vezes++; return { stdout: '' }; } });
    assert.equal(semResposta.ok, false);
    assert.equal(vezes, 1, 'sem nova tentativa: a ponte pode ter gravado');

    const enviados = [];
    const api = async (rota, corpo) => { enviados.push([rota, corpo]); return rota === 'suporte/gestor/aberturas' ? { status: 200, json: { aberturas: [pedido] } } : { status: 200 }; };
    await gravarAberturas('cfg', 'wsl', { api, gravar: async () => ({ ok: true, modo: 'reabrir', numero: '700' }) });
    assert.deepEqual(enviados[1], ['suporte/gestor/aberturas/1', { maquina: 'wsl', numero: '700', modo: 'reabrir', agendado: false }]);

    const ordem = [];
    await Promise.all([
        naPonte(async () => { ordem.push('a1'); await new Promise(r => setTimeout(r, 30)); ordem.push('a2'); }),
        naPonte(async () => { ordem.push('b1'); ordem.push('b2'); }),
    ]);
    assert.deepEqual(ordem, ['a1', 'a2', 'b1', 'b2'], 'uma chamada da ponte por vez');
}
console.log('PASSOU: suporte — gravação no Gestor com --apply e sem repetir; o número volta ao Matriz; a ponte atende um por vez.');
