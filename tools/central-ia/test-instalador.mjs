import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { chmodSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

// O instalador da Central (T-374), rodado de verdade contra um AlfaMatriz
// falso e um launchctl de mentira — o caminho do Mac, também no Linux do CI.
// Revisão da T-374: o código vem pelo ambiente e é trocado antes de qualquer
// pergunta, com o corpo fora da linha de comando; máquina nova com nome
// repetido recebe o nome que o servidor deu; chave repetida no env não fica
// com o valor velho; plist mudado pede a recarga do serviço; e download pela
// metade não executa nada.
// Dois bots por pessoa: o token do bot do código vem do terminal, nunca do
// servidor; o bot da conversa é recusado no lugar dele, e a instalação antiga
// que mandava o código por ele perde esse token.

const FONTE = readFileSync(new URL('../../deploy/central-ia/instalar.sh', import.meta.url), 'utf8');

const trocas = [];
const enviados = [];
// Telegram falso: dois bots válidos, o da conversa e o do código.
const BOTS = { 'tok-conversa': 'conversa_bot', 'tok-codigo': 'codigo_bot' };
const servidor = createServer((req, res) => {
    let corpo = '';
    req.on('data', d => { corpo += d; });
    req.on('end', () => {
        if (req.url === '/healthz') { res.writeHead(200); return res.end('ok'); }
        if (req.url === '/api/central-ia/instalacao') {
            const j = JSON.parse(corpo);
            trocas.push(j);
            if (j.codigo !== 'C'.repeat(40)) { res.writeHead(422); return res.end('{}'); }
            res.writeHead(200, { 'content-type': 'application/json' });
            return res.end(JSON.stringify({ token: `tok-${trocas.length}`, maquina: j.nova ? `${j.maquina}-2` : j.maquina, conta: 'Ana', telegram: { chat_id: '555', bot: 'conversa_bot' } }));
        }
        const tg = req.url.match(/^\/bot([^/]+)\/(getMe|sendMessage)$/);
        if (tg) {
            res.writeHead(200, { 'content-type': 'application/json' });
            if (!BOTS[tg[1]]) return res.end(JSON.stringify({ ok: false, error_code: 401 }));
            if (tg[2] === 'sendMessage') enviados.push({ token: tg[1], chat: JSON.parse(corpo).chat_id });
            return res.end(JSON.stringify({ ok: true, result: { username: BOTS[tg[1]] } }));
        }
        const m = req.url.match(/^\/central-ia\/runner\/arquivos\/(.+)$/);
        if (m) {
            res.writeHead(200);
            // O "atualizar" de mentira só registra com que pedido foi chamado.
            return res.end(m[1] === 'atualizar-quando-ocioso.sh'
                ? '#!/bin/bash\necho "recarregar=${CENTRAL_IA_RECARREGAR:-}" >> "$HOME/atualizar.log"\n'
                : 'export {};\n');
        }
        res.writeHead(404); res.end();
    });
});
await new Promise(r => servidor.listen(0, '127.0.0.1', r));
const URL_FALSA = `http://127.0.0.1:${servidor.address().port}`;
const script = FONTE.replace('__ALFAMATRIZ_URL__', URL_FALSA).replace('__ARQUIVOS__', 'runner.mjs atualizar-quando-ocioso.sh');

const home = mkdtempSync(join(tmpdir(), 'central-ia-instalador-'));
const bin = join(home, 'bin');
mkdirSync(bin);
mkdirSync(join(home, 'projetos'));
const exe = (nome, corpo) => { writeFileSync(join(bin, nome), `#!/bin/bash\n${corpo}\n`); chmodSync(join(bin, nome), 0o755); };
exe('uname', 'echo Darwin');
exe('hostname', 'echo MacBook-Pro');
// O serviço "já carregado" depois da primeira instalação.
exe('launchctl', `case "$1" in print) [ -f "$HOME/carregado" ] ;; bootstrap) touch "$HOME/carregado" ;; esac`);
exe('docker', 'exit 1');

// Assíncrono: o AlfaMatriz falso roda neste mesmo processo.
const rodar = (env = {}, args = [], fonte = script) => new Promise(resolve => {
    const p = spawn('bash', ['-s', '--', ...args], {
        env: { ...process.env, HOME: home, PATH: `${bin}:${process.env.PATH}`, CENTRAL_IA_TELEGRAM_API: URL_FALSA, ...env },
        stdio: ['pipe', 'pipe', 'pipe'],
        // Sessão nova, sem terminal: a pergunta do token não pode esperar teclado.
        detached: true,
    });
    let saida = '';
    p.stdout.on('data', d => { saida += d; });
    p.stderr.on('data', d => { saida += d; });
    const limite = setTimeout(() => p.kill('SIGKILL'), 60_000);
    p.on('close', status => { clearTimeout(limite); resolve({ status, saida }); });
    p.stdin.end(fonte);
});
const CONF = join(home, '.config/alfa/central-ia-runner.env');
const PLIST = join(home, 'Library/LaunchAgents/br.com.alfa.central-ia-runner.plist');
const conf = () => readFileSync(CONF, 'utf8');
// O "atualizar" sai em segundo plano (`nohup … &`): espera a linha dele.
const LOG = join(home, 'atualizar.log');
const atualizacoes = async n => {
    for (let i = 0; i < 50 && (existsSync(LOG) ? readFileSync(LOG, 'utf8').split('\n').length - 1 : 0) < n; i++) await new Promise(r => setTimeout(r, 100));
    return existsSync(LOG) ? readFileSync(LOG, 'utf8') : '';
};

// 1. Download pela metade: nada roda.
let r = await rodar({ CENTRAL_IA_CODIGO: 'C'.repeat(40) }, ['--sem-anexos'], script.slice(0, Math.floor(script.length / 2)));
assert.notEqual(r.status, 0);
assert.equal(trocas.length, 0, 'metade do script não pode trocar o código');
assert.equal(existsSync(join(home, '.local')), false, 'metade do script não pode baixar nada');

// 2. Máquina nova, código pelo ambiente: troca antes de perguntar, corpo em JSON, nome do servidor.
r = await rodar({ CENTRAL_IA_CODIGO: 'C'.repeat(40) }, ['--sem-anexos', '--projetos', join(home, 'projetos')]);
assert.equal(r.status, 0, r.saida);
assert.deepEqual(trocas.at(-1), { codigo: 'C'.repeat(40), maquina: 'MacBook-Pro', nova: true });
assert.match(conf(), /^CENTRAL_IA_MAQUINA=MacBook-Pro-2$/m, 'o nome com sufixo que o servidor deu');
assert.match(conf(), /^ALFAMATRIZ_RUNNER_TOKEN=tok-1$/m);
assert.equal(readFileSync(CONF).length > 0 && (spawnSync('stat', ['-f', '%Lp', CONF]).stdout.toString().trim() || spawnSync('stat', ['-c', '%a', CONF]).stdout.toString().trim()), '600');
assert.ok(existsSync(PLIST));
assert.equal(existsSync(join(home, 'atualizar.log')), false, 'serviço novo: só liga, não reinicia');

// 3. Conectada de novo (já tem configuração), com a chave do token repetida à mão.
writeFileSync(CONF, conf() + 'ALFAMATRIZ_RUNNER_TOKEN=repetido-a-mao\n');
r = await rodar({ CENTRAL_IA_CODIGO: 'C'.repeat(40) }, ['--sem-anexos', '--projetos', join(home, 'projetos')]);
assert.equal(r.status, 0, r.saida);
assert.deepEqual(trocas.at(-1), { codigo: 'C'.repeat(40), maquina: 'MacBook-Pro-2', nova: false });
assert.deepEqual(conf().match(/^ALFAMATRIZ_RUNNER_TOKEN=.*$/gm), ['ALFAMATRIZ_RUNNER_TOKEN=tok-2'], 'uma linha só, com o token novo');
assert.equal(await atualizacoes(1), 'recarregar=0\n', 'plist igual: reinício comum');

// 4. Atualizar sem código com outro PATH: o plist muda e o serviço tem de ser recarregado.
r = await rodar({ PATH: `${bin}:/usr/local/bin:${process.env.PATH}` }, ['--sem-anexos']);
assert.equal(r.status, 0, r.saida);
assert.equal(trocas.length, 2, 'sem código, nada é trocado');
assert.equal(await atualizacoes(2), 'recarregar=0\nrecarregar=1\n');

// 5. Código errado: para antes de perguntar e sem mexer na configuração.
const antes = conf();
r = await rodar({ CENTRAL_IA_CODIGO: 'X'.repeat(40) }, ['--sem-anexos']);
assert.notEqual(r.status, 0);
assert.match(r.saida, /Código de instalação inválido/);
assert.equal(conf(), antes);

// 6. Instalação antiga: o código saía pelo bot da conversa. Esse token sai do arquivo.
const COD = { CENTRAL_IA_CODIGO: 'C'.repeat(40) };
const ARGS = ['--sem-anexos', '--projetos', join(home, 'projetos')];
writeFileSync(CONF, conf() + 'CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO=tok-conversa\n');
r = await rodar(COD, ARGS);
assert.equal(r.status, 0, r.saida);
assert.match(conf(), /^CENTRAL_IA_TELEGRAM_CHAT_ID=555$/m, 'o chat vem do servidor');
assert.doesNotMatch(conf(), /TOKEN_CONFIRMACAO/, 'o token do bot da conversa não fica como bot do código');
assert.match(r.saida, /Sem o bot do código/);
assert.equal(enviados.length, 0);

// 7. O bot da conversa colado como bot do código: recusado.
r = await rodar({ ...COD, CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO: 'tok-conversa' }, ARGS);
assert.equal(r.status, 0, r.saida);
assert.match(r.saida, /bot em que você conversa/);
assert.doesNotMatch(conf(), /TOKEN_CONFIRMACAO/);

// 8. Token que o Telegram recusa: não grava.
r = await rodar({ CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO: 'tok-errado' }, ['--sem-anexos']);
assert.equal(r.status, 0, r.saida);
assert.match(r.saida, /Telegram recusou esse token/);
assert.doesNotMatch(conf(), /TOKEN_CONFIRMACAO/);

// 9. O bot do código, numa atualização sem código: grava 0600 e manda o teste por ele.
r = await rodar({ CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO: 'tok-codigo' }, ['--sem-anexos']);
assert.equal(r.status, 0, r.saida);
assert.deepEqual(conf().match(/^CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO=.*$/gm), ['CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO=tok-codigo']);
assert.deepEqual(enviados, [{ token: 'tok-codigo', chat: '555' }]);
assert.equal(spawnSync('stat', ['-f', '%Lp', CONF]).stdout.toString().trim() || spawnSync('stat', ['-c', '%a', CONF]).stdout.toString().trim(), '600');

servidor.close();
rmSync(home, { recursive: true, force: true });
console.log('PASSOU: instalador troca o código (do ambiente) antes de perguntar, grava o nome do servidor, não deixa chave repetida, recarrega o plist mudado, não roda pela metade e só aceita como bot do código um bot que não é o da conversa.');
