import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { chmodSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

// "Passar na frente" (docs/specs/central-ia-fila-por-projeto): o runner de
// verdade, contra um AlfaMatriz falso e um "claude" falso que fica trabalhando.
// Quando a resposta do andamento diz `pausar`, o runner encerra o agente (o
// grupo do processo, com o `sleep` que ele abriu) e conclui como `pausada`,
// com a sessão que passou ao agente e o estado do git — é dela que a retomada
// continua. E o andamento diz que esta máquina sabe pausar.

const RUNNER = new URL('../../deploy/central-ia/runner.mjs', import.meta.url).pathname;
const espera = ms => new Promise(r => setTimeout(r, ms));

const home = mkdtempSync(join(tmpdir(), 'central-ia-pausa-'));
const projetos = join(home, 'projetos');
const bin = join(home, 'bin');
const projeto = join(projetos, 'p1');
const marca = join(home, 'agente-comecou');
const argumentos = join(home, 'agente-args');
const grupo = join(home, 'agente-grupo');
mkdirSync(join(home, '.local/share/alfa'), { recursive: true });
mkdirSync(projeto, { recursive: true });
mkdirSync(bin, { recursive: true });
writeFileSync(join(projeto, 'README.md'), 'p1\n');
spawnSync('git', ['-C', projeto, 'init', '-q']);

// O /usage (limites da assinatura) sai na hora; o pedido de verdade grava um
// turno do assistente e fica "trabalhando" num sleep de 127 s.
writeFileSync(join(bin, 'claude'), `#!/bin/sh
case "$*" in
  *--session-id*|*--resume*) ;;
  *) echo '{}'; exit 0 ;;
esac
printf '%s\\n' "$*" > '${argumentos}'
echo $$ > '${grupo}'
cat > /dev/null
echo '{"type":"system","subtype":"init","session_id":"x"}'
echo '{"type":"assistant","message":{"content":[{"type":"text","text":"lendo o código"}]}}'
touch '${marca}'
sleep 127
echo '{"type":"result","result":"não devia chegar aqui","session_id":"x"}'
`);
chmodSync(join(bin, 'claude'), 0o755);

const job = {
    id: 901, conta: 1, agente: 'claude', projeto: 'p1', modo: 'analisar', instrucao: 'leia o código', anexos: [], timeout_minutos: 5,
    tarefa: { id: 77, titulo: 'Pedro(IA)', worktree: null, branch: null, commit_base: null }, contexto: null, bloco: '', fontes: [],
    cstk: false, memoria: false, confirmacao_regra: 'nunca', pops: [], acesso: 'restrito', na_pasta: true, quadro: null, continuar: null,
};
let entregue = false;
const andamentos = [];
let conclusao = null;
const servidor = createServer((req, res) => {
    let corpo = '';
    req.on('data', d => { corpo += d; });
    req.on('end', () => {
        const json = (status, dados) => { res.writeHead(status, { 'content-type': 'application/json' }); res.end(JSON.stringify(dados)); };
        if (req.url.includes('/runner/proxima')) {
            if (entregue) { res.writeHead(204); return res.end(); }
            entregue = true;
            return json(200, job);
        }
        if (req.url.includes('/execucoes/901/progresso')) {
            const dados = JSON.parse(corpo);
            andamentos.push(dados);
            // Só depois de o agente começar: é o caso de parar no meio do trabalho.
            return json(200, { ok: true, pausar: existsSync(marca) });
        }
        if (req.url.includes('/execucoes/901/conclusao')) { conclusao = JSON.parse(corpo); return json(200, {}); }
        return json(200, {});
    });
});
await new Promise(r => servidor.listen(0, '127.0.0.1', r));
const url = `http://127.0.0.1:${servidor.address().port}`;

let log = '';
const inicio = Date.now();
const runner = spawn(process.execPath, [RUNNER], {
    env: { ...process.env, HOME: home, PATH: `${bin}:${process.env.PATH}`, ALFAMATRIZ_URL: url, ALFAMATRIZ_RUNNER_TOKEN: 'teste',
        CENTRAL_IA_PROJETOS: projetos, CENTRAL_IA_MAQUINA: 'teste-pausa', CENTRAL_IA_CONFIRMAR: 'nunca' },
    stdio: ['ignore', 'pipe', 'pipe'],
});
runner.stdout.on('data', d => { log += d; });
runner.stderr.on('data', d => { log += d; });

try {
    // O andamento sai a cada 30 s: o pedido de pausa chega no segundo.
    for (let i = 0; i < 900 && !conclusao; i++) await espera(100);
    assert.ok(conclusao, `o runner não concluiu o pedido pausado:\n${log}`);
    assert.equal(conclusao.status, 'pausada', JSON.stringify(conclusao));
    assert.ok(Date.now() - inicio < 75_000, 'parou o agente na hora, sem esperar o sleep de 127 s');
    assert.ok(andamentos.length >= 2 && andamentos.every(a => a.pode_pausar === true), 'o andamento diz que a máquina sabe pausar');

    const args = readFileSync(argumentos, 'utf8');
    const sessao = args.match(/--session-id (\S+)/)?.[1];
    assert.ok(sessao, args);
    assert.equal(conclusao.sessao_agente, sessao, 'a sessão que o agente recebeu é a que a retomada vai continuar');
    assert.equal(conclusao.checkpoint?.cwd, projeto, 'a pasta vai junto: a sessão só retoma na mesma pasta');
    assert.ok(!conclusao.erro, 'pausa não é falha');
    // O runner abre o agente num grupo próprio (detached): o pid do sh é o do grupo.
    const pgid = Number(readFileSync(grupo, 'utf8'));
    let vivo = true;
    for (let i = 0; i < 30 && vivo; i++) {
        try { process.kill(-pgid, 0); await espera(100); } catch { vivo = false; }
    }
    assert.ok(!vivo, 'o grupo do agente inteiro foi encerrado, com o sleep que ele abriu');
    assert.match(log, /#901 pausado a pedido/);
    console.log('PASSOU: o andamento diz que a máquina sabe pausar; o pedido de pausa encerra o agente no meio e conclui "pausada" com a sessão e a pasta.');
} finally {
    runner.kill('SIGTERM');
    await espera(500);
    servidor.close();
    rmSync(home, { recursive: true, force: true });
}
