import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

// O runner de verdade relendo o arquivo de configuração (revisão da T-374):
// 1. o bot do código gravado DEPOIS de o processo subir passa a valer sem
//    reinício (antes a máquina recém-instalada ficava sem ele e recusava todo
//    pedido que modifica arquivos);
// 2. a máquina desconectada (401, espera longa) e conectada de novo volta a
//    pedir trabalho em segundos, e não só quando a espera acabar.

const RUNNER = new URL('../../deploy/central-ia/runner.mjs', import.meta.url).pathname;
const espera = ms => new Promise(r => setTimeout(r, ms));

const home = mkdtempSync(join(tmpdir(), 'central-ia-releitura-'));
const projetos = join(home, 'projetos');
const conf = join(home, 'runner.env');
mkdirSync(join(home, '.local/share/alfa'), { recursive: true });
mkdirSync(projetos, { recursive: true });

let valido = 'velho';
const batidas = []; // politica.telegram de cada batida
const proximas = []; // [hora, token]
const servidor = createServer((req, res) => {
    const token = (req.headers.authorization || '').replace('Bearer ', '');
    let corpo = '';
    req.on('data', d => { corpo += d; });
    req.on('end', () => {
        if (req.url.includes('proxima')) proximas.push([Date.now(), token]);
        if (token !== valido) { res.writeHead(401); return res.end('{}'); }
        if (req.url.includes('batida')) batidas.push(JSON.parse(corpo).politica?.telegram);
        if (req.url.includes('proxima')) { res.writeHead(204); return res.end(); }
        res.writeHead(200, { 'content-type': 'application/json' });
        res.end('{}');
    });
});
await new Promise(r => servidor.listen(0, '127.0.0.1', r));
writeFileSync(conf, [
    `ALFAMATRIZ_URL=http://127.0.0.1:${servidor.address().port}`,
    'ALFAMATRIZ_RUNNER_TOKEN=velho',
    `CENTRAL_IA_PROJETOS=${projetos}`,
    'CENTRAL_IA_MAQUINA=teste-releitura',
    `CENTRAL_IA_SEGREDO_CONFIRMACAO=${'a'.repeat(64)}`,
    'CENTRAL_IA_TELEGRAM_CHAT_ID=555',
    // A chave antiga não vale mais como bot do código.
    'CENTRAL_IA_TELEGRAM_TOKEN=tok-antigo',
    '',
].join('\n'));
const env = { ...process.env, HOME: home, CENTRAL_IA_RUNNER_CONFIG: conf };
for (const k of ['ALFAMATRIZ_RUNNER_TOKEN', 'CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO', 'CENTRAL_IA_TELEGRAM_CHAT_ID', 'CENTRAL_IA_SEGREDO_CONFIRMACAO']) delete env[k];
let log = '';
const runner = spawn(process.execPath, [RUNNER], { env, stdio: ['ignore', 'pipe', 'pipe'] });
runner.stdout.on('data', d => { log += d; });
runner.stderr.on('data', d => { log += d; });
const saiu = new Promise(r => runner.on('exit', c => r(c)));

try {
    /* ---------- 1. bot do código gravado depois de subir ---------- */
    for (let i = 0; i < 100 && batidas.length < 1; i++) await espera(100);
    assert.equal(batidas[0], false, `sem o bot do código (e a chave antiga não conta), a batida diz telegram:false:\n${log}`);
    writeFileSync(conf, readFileSync(conf, 'utf8') + 'CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO=tok-codigo\n');
    // A batida é a cada 30 s.
    for (let i = 0; i < 400 && batidas.length < 2; i++) await espera(100);
    assert.equal(batidas[1], true, `a batida seguinte devia ver o bot do código do arquivo:\n${log}`);
    assert.match(log, /bot do código lido do arquivo/);

    /* ---------- 2. desconectada e conectada de novo ---------- */
    valido = 'outro'; // o servidor recusa o token da máquina: desconectada
    for (let i = 0; i < 100 && !/acesso recusado/.test(log); i++) await espera(100);
    assert.match(log, /acesso recusado/, log);
    await espera(1500); // já na espera de 30 s
    writeFileSync(conf, readFileSync(conf, 'utf8').replace('=velho', '=novo'));
    valido = 'novo';
    const reconectadaEm = Date.now();
    for (let i = 0; i < 100 && !proximas.some(([t, k]) => t > reconectadaEm && k === 'novo'); i++) await espera(100);
    const primeira = proximas.find(([t, k]) => t > reconectadaEm && k === 'novo');
    assert.ok(primeira, `conectada de novo, a máquina devia voltar a pedir trabalho logo:\n${log}`);
    assert.ok(primeira[0] - reconectadaEm < 8000, `levou ${primeira[0] - reconectadaEm} ms para voltar`);
    for (let i = 0; i < 30 && !/acesso ao AlfaMatriz de volta/.test(log); i++) await espera(100);
    assert.match(log, /acesso ao AlfaMatriz de volta/);
} finally {
    runner.kill('SIGTERM');
    await Promise.race([saiu, espera(5000)]);
    servidor.close();
    rmSync(home, { recursive: true, force: true });
}
console.log('PASSOU: o runner relê o bot do código gravado depois de subir, ignora a chave antiga e volta em segundos quando a máquina é conectada de novo.');
