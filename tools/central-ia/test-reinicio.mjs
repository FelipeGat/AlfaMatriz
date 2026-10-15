import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { chmodSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

// Reinício para atualizar (T-374, 15/10/2026): o #17 chegou entre a última
// checagem do atualizar-quando-ocioso.sh e o kill, e morreu como "expirada".
// Agora o script só deixa o arquivo "central-ia-reiniciar", e quem sai é o
// próprio laço do runner, antes de pedir trabalho.

const RUNNER = new URL('../../deploy/central-ia/runner.mjs', import.meta.url).pathname;
const SCRIPT = new URL('../../deploy/central-ia/atualizar-quando-ocioso.sh', import.meta.url).pathname;
const espera = ms => new Promise(r => setTimeout(r, ms));

/* ---------- 1. o runner de verdade, contra um AlfaMatriz falso ---------- */
const home = mkdtempSync(join(tmpdir(), 'central-ia-reinicio-'));
const dados = join(home, '.local/share/alfa');
const projetos = join(home, 'projetos');
mkdirSync(dados, { recursive: true });
mkdirSync(projetos, { recursive: true });
const REINICIAR = join(dados, 'central-ia-reiniciar');

const proximas = []; // hora de cada "tem pedido para mim?"
const servidor = createServer((req, res) => {
    if (req.url.includes('proxima')) proximas.push(Date.now());
    req.resume();
    req.on('end', () => {
        if (req.url.includes('proxima')) { res.writeHead(204); return res.end(); }
        res.writeHead(200, { 'content-type': 'application/json' });
        res.end('{}');
    });
});
await new Promise(r => servidor.listen(0, '127.0.0.1', r));
const url = `http://127.0.0.1:${servidor.address().port}`;

let log = '';
const runner = spawn(process.execPath, [RUNNER], {
    env: { ...process.env, HOME: home, ALFAMATRIZ_URL: url, ALFAMATRIZ_RUNNER_TOKEN: 'teste', CENTRAL_IA_PROJETOS: projetos, CENTRAL_IA_MAQUINA: 'teste-reinicio' },
    stdio: ['ignore', 'pipe', 'pipe'],
});
runner.stdout.on('data', d => { log += d; });
runner.stderr.on('data', d => { log += d; });
const saiu = new Promise(r => runner.on('exit', codigo => r(codigo)));

for (let i = 0; i < 100 && proximas.length < 2; i++) await espera(100);
assert.ok(proximas.length >= 2, `o runner não chegou a pedir trabalho:\n${log}`);

// Pedido de reinício com a máquina livre: sai sozinho, sem pedir trabalho de novo.
const pedidoEm = Date.now();
writeFileSync(REINICIAR, '');
const codigo = await Promise.race([saiu, espera(15_000).then(() => 'não saiu')]);
if (codigo === 'não saiu') runner.kill('SIGKILL');
servidor.close();
assert.equal(codigo, 0, `o runner devia sair sozinho com 0:\n${log}`);
assert.equal(proximas.filter(t => t > pedidoEm).length, 0, 'pediu trabalho depois do pedido de reinício');
assert.equal(existsSync(REINICIAR), false, 'o runner devia apagar o arquivo ao sair');
assert.match(log, /reiniciando para carregar a versão nova/);
assert.match(log, /runner encerrado/);

// Com pedido em andamento ele não sai: a regra mora na mesma linha que pede trabalho.
const fonte = readFileSync(RUNNER, 'utf8');
assert.match(fonte, /if \(!rodando\.size && existsSync\(REINICIAR\)\)/);
assert.ok(fonte.indexOf('existsSync(REINICIAR)') < fonte.indexOf("api('proxima'"), 'o reinício tem de ser decidido antes de pedir trabalho');
rmSync(home, { recursive: true, force: true });

/* ---------- 2. o script, com launchctl/systemctl e pgrep de mentira ---------- */
// O serviço falso: `pid` guarda o pid atual; "kill" registra o reinício à força.
function cenario({ ocupado = '0', saiSozinho = false, liberaEm = 0 }) {
    const raiz = mkdtempSync(join(tmpdir(), 'central-ia-ocioso-'));
    const bin = join(raiz, 'bin');
    const alfa = join(raiz, '.local/share/alfa');
    mkdirSync(bin, { recursive: true });
    mkdirSync(alfa, { recursive: true });
    writeFileSync(join(raiz, 'pid'), '100');
    writeFileSync(join(alfa, 'central-ia-ocupado'), ocupado);
    const servico = `#!/bin/bash
R="${raiz}"
case "$*" in
  *kickstart*|*restart*) echo forçado >> "$R/kill"; echo 200 > "$R/pid" ;;
  *print*) echo "	pid = $(cat "$R/pid")" ;;
  *MainPID*) cat "$R/pid" ;;
esac`;
    for (const nome of ['launchctl', 'systemctl']) { writeFileSync(join(bin, nome), servico); chmodSync(join(bin, nome), 0o755); }
    writeFileSync(join(bin, 'pgrep'), '#!/bin/bash\nexit 1\n');
    writeFileSync(join(bin, 'sleep'), '#!/bin/bash\nexec /bin/sleep 0.05\n');
    chmodSync(join(bin, 'pgrep'), 0o755);
    chmodSync(join(bin, 'sleep'), 0o755);
    // O "runner": novo sai sozinho ao ver o arquivo (e o serviço o religa);
    // ocupado fica livre depois de `liberaEm` voltas.
    const ator = `#!/bin/bash
R="${raiz}"; A="${alfa}"; n=0
while [ ! -f "$R/fim" ]; do
  n=$((n+1))
  [ ${liberaEm} -gt 0 ] && [ $n -ge ${liberaEm} ] && echo 0 > "$A/central-ia-ocupado"
  if ${saiSozinho} && [ -f "$A/central-ia-reiniciar" ] && [ "$(cat "$A/central-ia-ocupado")" = 0 ]; then
    rm -f "$A/central-ia-reiniciar"; echo 300 > "$R/pid"; exit 0
  fi
  /bin/sleep 0.05
done`;
    writeFileSync(join(raiz, 'ator.sh'), ator);
    const a = spawn('bash', [join(raiz, 'ator.sh')], { stdio: 'ignore' });
    const r = spawnSync('bash', [SCRIPT], { env: { ...process.env, HOME: raiz, PATH: `${bin}:${process.env.PATH}` }, timeout: 20_000 });
    writeFileSync(join(raiz, 'fim'), '');
    a.kill();
    const res = {
        status: r.status,
        forcado: existsSync(join(raiz, 'kill')) ? readFileSync(join(raiz, 'kill'), 'utf8').trim().split('\n').length : 0,
        arquivo: existsSync(join(alfa, 'central-ia-reiniciar')),
        pid: readFileSync(join(raiz, 'pid'), 'utf8').trim(),
    };
    rmSync(raiz, { recursive: true, force: true });
    return res;
}

// Runner novo: sai sozinho; o script não mata nada.
let r = cenario({ saiSozinho: true });
assert.deepEqual(r, { status: 0, forcado: 0, arquivo: false, pid: '300' });

// Runner novo ocupado (esperando código): espera ele ficar livre e ainda assim não mata.
r = cenario({ saiSozinho: true, ocupado: '1', liberaEm: 40 });
assert.deepEqual(r, { status: 0, forcado: 0, arquivo: false, pid: '300' });

// Runner antigo (não conhece o arquivo): livre por 4 checagens seguidas, cai no caminho antigo.
r = cenario({ saiSozinho: false });
assert.deepEqual(r, { status: 0, forcado: 1, arquivo: false, pid: '200' });

// Runner antigo ocupado: não mata enquanto houver pedido; mata assim que fica livre.
r = cenario({ saiSozinho: false, ocupado: '1', liberaEm: 40 });
assert.deepEqual(r, { status: 0, forcado: 1, arquivo: false, pid: '200' });

// A última checagem do caminho antigo vem colada no kill, sem espera entre as duas.
const script = readFileSync(SCRIPT, 'utf8');
assert.match(script, /if ocioso "\$agora"; then rm -f "\$REINICIAR"; reiniciar; exit 0; fi/);

console.log('PASSOU: o runner sai sozinho antes de pedir trabalho quando o reinício é pedido; o script não mata o runner novo e só mata o antigo livre.');
