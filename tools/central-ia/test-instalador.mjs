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
// Na hora de ligar, anota se o bot do código já estava no arquivo: o runner
// lê a configuração ao subir (revisão da T-374).
exe('launchctl', `case "$1" in print) [ -f "$HOME/carregado" ] ;; bootstrap) touch "$HOME/carregado"; grep -c '^CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO=' "$HOME/.config/alfa/central-ia-runner.env" >> "$HOME/ligado.log" || true ;; esac`);
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
assert.equal(readFileSync(CONF).length > 0 && (spawnSync('stat', ['-c', '%a', CONF]).stdout.toString().trim() || spawnSync('stat', ['-f', '%Lp', CONF]).stdout.toString().trim()), '600');
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
assert.equal(spawnSync('stat', ['-c', '%a', CONF]).stdout.toString().trim() || spawnSync('stat', ['-f', '%Lp', CONF]).stdout.toString().trim(), '600');

// 10. Sem código, o bot da conversa continua recusado: o @ dele ficou gravado na conexão.
assert.match(conf(), /^CENTRAL_IA_TELEGRAM_BOT_CONVERSA=conversa_bot$/m);
writeFileSync(CONF, conf().replace(/^CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO=.*\n/m, ''));
r = await rodar({ CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO: 'tok-conversa' }, ['--sem-anexos']);
assert.equal(r.status, 0, r.saida);
assert.match(r.saida, /bot em que você conversa/);
assert.doesNotMatch(conf(), /TOKEN_CONFIRMACAO/);

// 11. A chave antiga (CENTRAL_IA_TELEGRAM_TOKEN): com o bot da conversa, só sai;
// com outro bot, vira o bot do código. Nos dois casos some do arquivo.
writeFileSync(CONF, conf() + 'CENTRAL_IA_TELEGRAM_TOKEN=tok-conversa\n');
r = await rodar({}, ['--sem-anexos']);
assert.equal(r.status, 0, r.saida);
assert.doesNotMatch(conf(), /TELEGRAM_TOKEN/);
writeFileSync(CONF, conf() + 'CENTRAL_IA_TELEGRAM_TOKEN=tok-codigo\n');
r = await rodar({}, ['--sem-anexos']);
assert.equal(r.status, 0, r.saida);
assert.deepEqual(conf().match(/^CENTRAL_IA_TELEGRAM_TOKEN.*$/gm), ['CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO=tok-codigo']);

// 12. Máquina nova: o bot do código está no arquivo ANTES de o serviço ligar.
rmSync(join(home, '.config'), { recursive: true, force: true });
rmSync(join(home, 'carregado'), { force: true });
rmSync(join(home, 'ligado.log'), { force: true });
r = await rodar({ ...COD, CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO: 'tok-codigo' }, ARGS);
assert.equal(r.status, 0, r.saida);
assert.equal(readFileSync(join(home, 'ligado.log'), 'utf8'), '1\n', 'o serviço ligou antes de o bot do código estar gravado');

// Sem terminal (o CI, o `curl | bash` de um script): o trap do eco não
// pode imprimir o erro de abrir o /dev/tty.
assert.doesNotMatch(r.saida, /dev\/tty/, r.saida);

// 13. Chave antiga que o Telegram não confirma (fora do ar ou recusada): fica
// no arquivo, com aviso. Apagar sem saber perdia o token do bot do código.
writeFileSync(CONF, conf().replace(/^CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO=.*\n/m, '') + 'CENTRAL_IA_TELEGRAM_TOKEN=tok-errado\n');
r = await rodar({}, ['--sem-anexos']);
assert.equal(r.status, 0, r.saida);
assert.match(conf(), /^CENTRAL_IA_TELEGRAM_TOKEN=tok-errado$/m, 'não confirmada, a chave antiga fica');
assert.match(r.saida, /Não consegui conferir a linha antiga/);

// 14. Chave antiga de outro bot, com o bot do código já gravado: fica, com aviso.
writeFileSync(CONF, conf().replace(/^CENTRAL_IA_TELEGRAM_TOKEN=.*\n/m, '') + 'CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO=tok-codigo\nCENTRAL_IA_TELEGRAM_TOKEN=tok-outro\n');
BOTS['tok-outro'] = 'outro_bot';
r = await rodar({}, ['--sem-anexos']);
assert.equal(r.status, 0, r.saida);
assert.match(conf(), /^CENTRAL_IA_TELEGRAM_TOKEN=tok-outro$/m);
assert.match(conf(), /^CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO=tok-codigo$/m);
assert.match(r.saida, /@outro_bot\) de .* não é usada/);

// 15. Instalação antiga, sem o @ do bot da conversa: a chave antiga não é
// apagada, não se pergunta o token (não haveria como recusar o bot da
// conversa) e o aviso manda gerar um comando novo.
writeFileSync(CONF, conf().replace(/^CENTRAL_IA_TELEGRAM_(TOKEN|TOKEN_CONFIRMACAO|BOT_CONVERSA)=.*\n/gm, '') + 'CENTRAL_IA_TELEGRAM_TOKEN=tok-codigo\n');
r = await rodar({}, ['--sem-anexos']);
assert.equal(r.status, 0, r.saida);
assert.match(conf(), /^CENTRAL_IA_TELEGRAM_TOKEN=tok-codigo$/m, 'sem o bot da conversa, nada se conclui sobre a chave antiga');
assert.doesNotMatch(r.saida, /No @BotFather/, 'sem o bot da conversa, não pergunta o token');
assert.match(r.saida, /instalador antigo\. Gere um comando novo/);
assert.doesNotMatch(r.saida, /mais tarde/, 'sem o bot da conversa, só o aviso do fim (gerar comando novo)');

// 16. Sem o bot da conversa cadastrado no servidor (sem chat): o aviso manda
// cadastrá-lo, e não só gerar outro comando.
writeFileSync(CONF, conf().replace(/^CENTRAL_IA_TELEGRAM_(TOKEN|CHAT_ID)=.*\n/gm, ''));
r = await rodar({ CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO: 'tok-codigo' }, ['--sem-anexos']);
assert.equal(r.status, 0, r.saida);
assert.match(r.saida, /Primeiro cadastre o seu bot da conversa/);
assert.doesNotMatch(r.saida, /instalador antigo/);
assert.doesNotMatch(conf(), /TOKEN_CONFIRMACAO/);

// 17 a 20 (3ª revisão da T-374). A chave antiga é julgada DEPOIS do bot
// gravado e do token novo: quem cai ali não pode levar o único token bom.
const soTelegram = linhas => writeFileSync(CONF, conf().replace(/^CENTRAL_IA_TELEGRAM_\w+=.*\n/gm, '')
    + ['CENTRAL_IA_TELEGRAM_CHAT_ID=555', 'CENTRAL_IA_TELEGRAM_BOT_CONVERSA=conversa_bot', ...linhas].join('\n') + '\n');
const soOBotDoCodigo = (token, msg) => {
    assert.deepEqual(conf().match(/^CENTRAL_IA_TELEGRAM_TOKEN.*$/gm), [`CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO=${token}`], msg);
    assert.doesNotMatch(r.saida, /Pode apagá-la/, msg);
    assert.doesNotMatch(r.saida, /No @BotFather/, `${msg}: não pergunta o token que já tem`);
};

// 17. Token novo recusado pelo Telegram: a chave antiga (outro bot válido) vira o bot do código.
soTelegram(['CENTRAL_IA_TELEGRAM_TOKEN=tok-outro']);
r = await rodar({ CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO: 'tok-errado' }, ['--sem-anexos']);
assert.equal(r.status, 0, r.saida);
assert.match(r.saida, /Telegram recusou esse token/);
soOBotDoCodigo('tok-outro', 'novo recusado');

// 18. Token novo é o bot da conversa: idem.
soTelegram(['CENTRAL_IA_TELEGRAM_TOKEN=tok-outro']);
r = await rodar({ CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO: 'tok-conversa' }, ['--sem-anexos']);
assert.equal(r.status, 0, r.saida);
assert.match(r.saida, /bot em que você conversa/);
soOBotDoCodigo('tok-outro', 'novo é o bot da conversa');

// 19. O bot do código gravado era o da conversa: sai, e a chave antiga o substitui.
soTelegram(['CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO=tok-conversa', 'CENTRAL_IA_TELEGRAM_TOKEN=tok-outro']);
r = await rodar({}, ['--sem-anexos']);
assert.equal(r.status, 0, r.saida);
soOBotDoCodigo('tok-outro', 'atual era o bot da conversa');

// 20. O bot do código gravado foi revogado: a chave antiga, que o Telegram confirma, o substitui.
soTelegram(['CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO=tok-revogado', 'CENTRAL_IA_TELEGRAM_TOKEN=tok-outro']);
r = await rodar({}, ['--sem-anexos']);
assert.equal(r.status, 0, r.saida);
soOBotDoCodigo('tok-outro', 'atual revogado');

// 21. Token novo bom e chave antiga de outro bot: o novo vale e a antiga sobra (com o @ do que vale).
soTelegram(['CENTRAL_IA_TELEGRAM_TOKEN=tok-outro']);
r = await rodar({ CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO: 'tok-codigo' }, ['--sem-anexos']);
assert.equal(r.status, 0, r.saida);
assert.match(conf(), /^CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO=tok-codigo$/m);
assert.match(conf(), /^CENTRAL_IA_TELEGRAM_TOKEN=tok-outro$/m);
assert.match(r.saida, /@outro_bot\) de .* não é usada: o bot do código é o @codigo_bot/);

// 22. Teste do item 211 (Windows com WSL, 16/10/2026): o Telegram atendia em
// ~335 ms e o Node desiste de cada família em 250 ms; todo `node -e` que usa a
// rede tem de levar o ajuste do runner. O atraso não se reproduz no 127.0.0.1,
// por isso a conferência é no texto.
const comFetch = [...FONTE.matchAll(/"\$NODE" -e ([^\n]*)\n[^\n]*fetch\(/g)].map(m => m[1]);
assert.equal(comFetch.length, 2, 'os dois fetch do instalador (getMe e a mensagem de teste)');
for (const inicio of comFetch) assert.match(inicio, /^"\$REDE"'/, `fetch sem o ajuste de rede: ${inicio}`);
assert.match(FONTE, /^REDE='require\("node:net"\)\.setDefaultAutoSelectFamilyAttemptTimeout\?\.\(2500\);'$/m);

// 23. WSL, sem systemd (decisão do dono, 16/10/2026): o Windows desliga a
// distro com o terminal fechado, e o WSLg cobre o canal do systemd do usuário.
// O instalador grava o supervisor e o script da Inicialização do Windows, que
// o abre, e o roda na hora.
const homeW = mkdtempSync(join(tmpdir(), 'central-ia-instalador-wsl-'));
const binW = join(homeW, 'bin');
mkdirSync(binW);
mkdirSync(join(homeW, '.local/bin'), { recursive: true });
const exeW = (nome, corpo) => { writeFileSync(join(binW, nome), `#!/bin/bash\n${corpo}\n`); chmodSync(join(binW, nome), 0o755); };
exeW('uname', 'case "$1" in -r) echo 6.6.87.2-microsoft-standard-WSL2 ;; *) echo Linux ;; esac');
exeW('hostname', 'echo Desktop');
// Como no desktop do teste: o canal do systemd não responde.
exeW('systemctl', 'echo "$*" >> "$HOME/systemctl.log"; exit 1');
exeW('loginctl', 'exit 0');
exeW('docker', 'exit 1');
// A trava do supervisor: presa enquanto "ele" roda (a marca no HOME).
exeW('flock', '[ ! -f "$HOME/supervisor-no-ar" ]');
// Lê o stdin como o cmd.exe de verdade: sem o </dev/null, comeria o script.
exeW('cmd.exe', 'cat >/dev/null; echo "C:\\Users\\ana\\AppData\\Roaming"');
exeW('wslpath', `if [ "$1" = -w ]; then echo "W:$2"; else echo "$HOME/c/Users/ana/AppData/Roaming"; fi`);
// O .vbs de verdade abre o supervisor.
exeW('wscript.exe', 'cat >/dev/null; echo "$1" >> "$HOME/wscript.log"; touch "$HOME/supervisor-no-ar"');
exeW('setsid', 'echo "$*" >> "$HOME/setsid.log"; touch "$HOME/supervisor-no-ar"');
const STARTUP = join(homeW, 'c/Users/ana/AppData/Roaming/Microsoft/Windows/Start Menu/Programs/Startup/central-ia-wsl.vbs');
const DEST_W = join(homeW, '.local/share/alfa');
const UNIDADE_W = join(homeW, '.config/systemd/user/central-ia-runner.service');
const W = { HOME: homeW, PATH: `${binW}:${process.env.PATH}`, WSL_DISTRO_NAME: 'Ubuntu' };
const EU = spawnSync('id', ['-un']).stdout.toString().trim();
r = await rodar({ ...W, ...COD }, ['--sem-anexos', '--projetos', join(homeW, 'projetos')]);
assert.equal(r.status, 0, r.saida);
assert.match(r.saida, /sistema: wsl/);
assert.match(r.saida, /o runner fica rodando com o terminal fechado/);
assert.doesNotMatch(r.saida, /systemd não está ligado/, 'no WSL o systemd não é cobrado');
assert.equal(existsSync(UNIDADE_W), false, 'no WSL não há unidade do systemd');
assert.equal(existsSync(join(homeW, 'systemctl.log')), false, 'nem se pergunta ao systemd');
const vbs = readFileSync(STARTUP, 'utf8');
assert.ok(vbs.includes(`Win32_Process").Create "wsl.exe -d Ubuntu -u ${EU} --cd ~ --exec /usr/bin/env CENTRAL_IA_SUPERVISOR_WSL=1 /bin/bash .local/share/alfa/central-ia-wsl.sh", Null, inicio, pid`), `pelo WMI, fora da árvore do terminal, rodando o supervisor:\n${vbs}`);
assert.match(vbs, /CommandLine LIKE '%CENTRAL_IA_SUPERVISOR_WSL%'/, 'a marca que impede um segundo');
assert.match(vbs, /inicio\.ShowWindow = 0/, 'escondido');
assert.doesNotMatch(vbs, /WScript\.Shell/, 'aberto pelo Run, morre com o terminal');
assert.match(vbs, /winmgmts:\\\\\.\\root\\cimv2/, 'o caminho do WMI com as barras certas');
assert.match(vbs, /If abertos\.Count = 0 Then/, 'não abre um segundo');
assert.match(vbs, /^[\x00-\x7f]*$/, 'ASCII: o wscript lê na página de código do Windows');
assert.equal(readFileSync(join(homeW, 'wscript.log'), 'utf8'), `W:${STARTUP}\n`, 'rodado na hora');
assert.equal(existsSync(join(homeW, 'setsid.log')), false, 'com o Windows à vista, não roda pelo terminal');
const supervisor = readFileSync(join(DEST_W, 'central-ia-wsl.sh'), 'utf8');
assert.match(supervisor, /flock -n 9 \|\| exit 0/, 'um supervisor só');
assert.match(supervisor, /\. "\$DIR\/central-ia-wsl\.env"/, 'o ambiente relido a cada subida');
const ambiente = readFileSync(join(DEST_W, 'central-ia-wsl.env'), 'utf8');
assert.match(ambiente, /^NODE=\/\S*node$/m, 'o caminho do node do terminal');
assert.match(ambiente, /^CENTRAL_IA_LOG=\S*\/\.local\/share\/alfa\/central-ia-runner\.log$/m, 'sem journal, quem gira o log é o runner');
assert.doesNotMatch(ambiente, /\/mnt\//, 'sem as pastas do Windows no PATH');
assert.match(ambiente, new RegExp(`^PATH=${join(homeW, '.local/bin').replace(/[.*+?^${}()|[\]\\/]/g, '\\$&')}:`, 'm'), 'o ~/.local/bin do claude, mesmo fora do PATH do terminal');
assert.equal(existsSync(join(homeW, 'atualizar.log')), false, 'supervisor novo: só liga, não reinicia');
assert.match(r.saida, /Pronto\./, 'o cmd.exe não comeu o resto do script');

// 23b. Atualizar com o supervisor no ar: reinicia pelo script de atualizar.
r = await rodar({ ...W }, ['--sem-anexos']);
assert.equal(r.status, 0, r.saida);
assert.match(await (async () => { for (let i = 0; i < 50 && !existsSync(join(homeW, 'atualizar.log')); i++) await new Promise(res => setTimeout(res, 100)); return readFileSync(join(homeW, 'atualizar.log'), 'utf8'); })(), /recarregar=0/);
assert.match(r.saida, /reinicia sozinho/);

// 23c. Instalação de antes (serviço do systemd), sem o canal do systemd: a
// unidade é trocada por uma que não religa, o systemd do usuário a relê pelo
// SIGHUP, e ela sai — senão rodariam dois runners.
mkdirSync(join(homeW, '.config/systemd/user/default.target.wants'), { recursive: true });
writeFileSync(UNIDADE_W, '[Service]\nExecStart=/x/node runner.mjs\nRestart=always\n');
writeFileSync(join(homeW, '.config/systemd/user/default.target.wants/central-ia-runner.service'), '');
exeW('pgrep', 'case "$*" in *"-x systemd"*) echo 999999 ;; *) exit 1 ;; esac');
r = await rodar({ ...W }, ['--sem-anexos']);
assert.equal(r.status, 0, r.saida);
assert.equal(existsSync(UNIDADE_W), false, 'a unidade antiga sai');
assert.equal(existsSync(join(homeW, '.config/systemd/user/default.target.wants/central-ia-runner.service')), false);
assert.match(r.saida, /O serviço antigo do systemd saiu/);

// 24. WSL sem o Windows à vista (interop desligado) e supervisor fora do ar:
// roda pelo terminal e avisa.
// Um cmd.exe que falha, e não a falta dele: rodado num WSL de verdade, o do
// Windows estaria no PATH.
exeW('cmd.exe', 'cat >/dev/null; exit 1');
rmSync(join(homeW, 'supervisor-no-ar'));
r = await rodar({ ...W }, ['--sem-anexos']);
assert.equal(r.status, 0, r.saida);
assert.match(r.saida, /Deixe um terminal do Ubuntu aberto/);
assert.match(readFileSync(join(homeW, 'setsid.log'), 'utf8'), /nohup \/bin\/bash .*central-ia-wsl\.sh/);
rmSync(homeW, { recursive: true, force: true });

servidor.close();
rmSync(home, { recursive: true, force: true });
console.log('PASSOU: instalador troca o código (do ambiente) antes de perguntar, grava o nome do servidor, não deixa chave repetida, recarrega o plist mudado, não roda pela metade só aceita como bot do código um bot que não é o da conversa (com ou sem código, e na chave antiga) grava o bot do código antes de ligar o serviço, e só apaga a chave antiga quando sabe o que ela era; todo fetch leva o ajuste de rede do WSL, e no WSL o runner roda sem systemd, pelo supervisor que a Inicialização do Windows abre (e a unidade antiga sai).');
