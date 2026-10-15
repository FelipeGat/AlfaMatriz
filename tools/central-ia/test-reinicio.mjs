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
// Revisão da T-374: o runner que sabe sair grava o pid em
// "central-ia-conhece-reinicio" e nunca é morto pelo script; "pausar" (Mac,
// plist mudado) faz o runner parar de pedir trabalho para o script recarregar
// o serviço; e um 401 depois de a máquina ser conectada de novo relê o token.

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
const CONHECE = join(dados, 'central-ia-conhece-reinicio');
const PAUSADO = join(dados, 'central-ia-pausado');

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
assert.equal(readFileSync(CONHECE, 'utf8'), String(runner.pid), 'o runner diz ao script que sabe sair sozinho');

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

/* ---------- 1b. pausar (Mac, plist mudado) e token novo depois de reconectar ---------- */
{
    const home = mkdtempSync(join(tmpdir(), 'central-ia-pausa-'));
    const dados = join(home, '.local/share/alfa');
    const projetos = join(home, 'projetos');
    const conf = join(home, 'runner.env');
    mkdirSync(dados, { recursive: true });
    mkdirSync(projetos, { recursive: true });
    let valido = 'velho';
    const usados = [];
    const proximas = [];
    const servidor = createServer((req, res) => {
        const token = (req.headers.authorization || '').replace('Bearer ', '');
        if (req.url.includes('proxima')) { proximas.push(Date.now()); usados.push(token); }
        req.resume();
        req.on('end', () => {
            if (token !== valido) { res.writeHead(401); return res.end('{}'); }
            if (req.url.includes('proxima')) { res.writeHead(204); return res.end(); }
            res.writeHead(200, { 'content-type': 'application/json' });
            res.end('{}');
        });
    });
    await new Promise(r => servidor.listen(0, '127.0.0.1', r));
    writeFileSync(conf, `ALFAMATRIZ_URL=http://127.0.0.1:${servidor.address().port}\nALFAMATRIZ_RUNNER_TOKEN=velho\nCENTRAL_IA_PROJETOS=${projetos}\nCENTRAL_IA_MAQUINA=teste-pausa\n`);
    const env = { ...process.env, HOME: home, CENTRAL_IA_RUNNER_CONFIG: conf };
    delete env.ALFAMATRIZ_RUNNER_TOKEN;
    let log = '';
    const runner = spawn(process.execPath, [RUNNER], { env, stdio: ['ignore', 'pipe', 'pipe'] });
    runner.stdout.on('data', d => { log += d; });
    runner.stderr.on('data', d => { log += d; });
    const saiu = new Promise(r => runner.on('exit', c => r(c)));
    for (let i = 0; i < 100 && proximas.length < 1; i++) await espera(100);

    // Conectada de novo: o servidor já recusa o token antigo, o arquivo tem o novo.
    writeFileSync(conf, readFileSync(conf, 'utf8').replace('=velho', '=novo'));
    valido = 'novo';
    const trocaEm = proximas.length;
    for (let i = 0; i < 100 && usados.slice(trocaEm).filter(t => t === 'novo').length < 2; i++) await espera(100);
    assert.ok(usados.slice(trocaEm).filter(t => t === 'novo').length >= 2, `o runner devia passar ao token novo:\n${log}`);
    assert.match(log, /token novo no arquivo de configuração/);
    assert.doesNotMatch(log, /acesso recusado/, 'o 401 do token trocado não é "máquina desconectada"');

    // Pausar: para de pedir trabalho, avisa com o pid e continua vivo.
    writeFileSync(join(dados, 'central-ia-reiniciar'), 'pausar\n');
    for (let i = 0; i < 100 && !existsSync(join(dados, 'central-ia-pausado')); i++) await espera(100);
    assert.equal(readFileSync(join(dados, 'central-ia-pausado'), 'utf8'), String(runner.pid), `o runner devia avisar que pausou:\n${log}`);
    const pausouEm = Date.now();
    await espera(4000);
    assert.equal(proximas.filter(t => t > pausouEm).length, 0, 'pausado não pede trabalho');
    assert.equal(runner.exitCode, null, 'pausado não sai: quem recarrega é o script');
    runner.kill('SIGTERM');
    assert.equal(await Promise.race([saiu, espera(10_000).then(() => 'não saiu')]), 0, `o SIGTERM do bootout encerra o pausado:\n${log}`);
    servidor.close();
    rmSync(home, { recursive: true, force: true });
}

/* ---------- 2. o script, com launchctl/systemctl e pgrep de mentira ---------- */
// O serviço falso: `pid` guarda o pid atual; "kill" registra o reinício à força.
function cenario({ ocupado = '0', saiSozinho = false, liberaEm = 0, atraso = 0, recarregar = false }) {
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
  *bootout*) echo descarregado >> "$R/recarga" ;;
  *bootstrap*) echo carregado >> "$R/recarga"; echo 400 > "$R/pid" ;;
  *print*) echo "	pid = $(cat "$R/pid")" ;;
  *MainPID*) cat "$R/pid" ;;
esac`;
    for (const nome of ['launchctl', 'systemctl']) { writeFileSync(join(bin, nome), servico); chmodSync(join(bin, nome), 0o755); }
    writeFileSync(join(bin, 'pgrep'), '#!/bin/bash\nexit 1\n');
    // O caminho do Mac (o único com recarga do plist) também no Linux do CI.
    if (recarregar) { writeFileSync(join(bin, 'uname'), '#!/bin/bash\necho Darwin\n'); chmodSync(join(bin, 'uname'), 0o755); }
    writeFileSync(join(bin, 'sleep'), '#!/bin/bash\nexec /bin/sleep 0.05\n');
    chmodSync(join(bin, 'pgrep'), 0o755);
    chmodSync(join(bin, 'sleep'), 0o755);
    // O "runner": o novo grava que conhece o arquivo e sai sozinho ao vê-lo
    // (e o serviço o religa), ou pausa se o pedido for "pausar"; só o vê
    // depois de `atraso` voltas (um `proxima` lento); ocupado fica livre
    // depois de `liberaEm` voltas.
    if (saiSozinho) writeFileSync(join(alfa, 'central-ia-conhece-reinicio'), '100');
    const ator = `#!/bin/bash
R="${raiz}"; A="${alfa}"; n=0
while [ ! -f "$R/fim" ]; do
  n=$((n+1))
  [ ${liberaEm} -gt 0 ] && [ $n -ge ${liberaEm} ] && echo 0 > "$A/central-ia-ocupado"
  if ${saiSozinho} && [ $n -ge ${atraso} ] && [ -f "$A/central-ia-reiniciar" ] && [ "$(cat "$A/central-ia-ocupado")" = 0 ]; then
    if [ "$(cat "$A/central-ia-reiniciar")" = pausar ]; then rm -f "$A/central-ia-reiniciar"; cat "$R/pid" > "$A/central-ia-pausado"
    else rm -f "$A/central-ia-reiniciar"; echo 300 > "$R/pid"; exit 0; fi
  fi
  /bin/sleep 0.05
done`;
    writeFileSync(join(raiz, 'ator.sh'), ator);
    const a = spawn('bash', [join(raiz, 'ator.sh')], { stdio: 'ignore' });
    const r = spawnSync('bash', [SCRIPT], { env: { ...process.env, HOME: raiz, PATH: `${bin}:${process.env.PATH}`, CENTRAL_IA_RECARREGAR: recarregar ? '1' : '0' }, timeout: 20_000 });
    writeFileSync(join(raiz, 'fim'), '');
    a.kill();
    const res = {
        status: r.status,
        forcado: existsSync(join(raiz, 'kill')) ? readFileSync(join(raiz, 'kill'), 'utf8').trim().split('\n').length : 0,
        arquivo: existsSync(join(alfa, 'central-ia-reiniciar')),
        pid: readFileSync(join(raiz, 'pid'), 'utf8').trim(),
        ...(recarregar ? { recarga: existsSync(join(raiz, 'recarga')) ? readFileSync(join(raiz, 'recarga'), 'utf8').trim().split('\n') : [] } : {}),
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

// Runner novo e lento (um `proxima` de até 20 s antes de ver o arquivo): livre
// por muito mais que 4 checagens, e mesmo assim não é morto.
r = cenario({ saiSozinho: true, atraso: 60 });
assert.deepEqual(r, { status: 0, forcado: 0, arquivo: false, pid: '300' });

// Mac com plist mudado: o runner novo pausa e o script descarrega e carrega o serviço.
r = cenario({ saiSozinho: true, recarregar: true });
assert.deepEqual(r, { status: 0, forcado: 0, arquivo: false, pid: '400', recarga: ['descarregado', 'carregado'] });

// Mac com plist mudado e runner antigo: cai no caminho antigo, mas recarregando o plist.
r = cenario({ saiSozinho: false, recarregar: true });
assert.deepEqual(r, { status: 0, forcado: 0, arquivo: false, pid: '400', recarga: ['descarregado', 'carregado'] });

// Runner antigo (não conhece o arquivo): livre por 4 checagens seguidas, cai no caminho antigo.
r = cenario({ saiSozinho: false });
assert.deepEqual(r, { status: 0, forcado: 1, arquivo: false, pid: '200' });

// Runner antigo ocupado: não mata enquanto houver pedido; mata assim que fica livre.
r = cenario({ saiSozinho: false, ocupado: '1', liberaEm: 40 });
assert.deepEqual(r, { status: 0, forcado: 1, arquivo: false, pid: '200' });

// A última checagem do caminho antigo vem colada no kill, sem espera entre as duas.
const script = readFileSync(SCRIPT, 'utf8');
assert.match(script, /if ocioso "\$agora"; then rm -f "\$REINICIAR"; reiniciar; exit 0; fi/);

console.log('PASSOU: o runner sai sozinho antes de pedir trabalho quando o reinício é pedido; o script não mata o runner novo (nem o lento) e só mata o antigo livre; no Mac, plist mudado recarrega o serviço com o runner pausado; token trocado no arquivo vale no primeiro 401.');
