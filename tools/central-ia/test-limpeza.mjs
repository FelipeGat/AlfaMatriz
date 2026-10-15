// T-378: o log e a auditoria giram no limite; a worktree só sai encerrada e sem nada que só exista nela.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { girar, lerNomeDaWorktree, limparWorktrees, motivoParaFicar } from '../../deploy/central-ia/limpeza.mjs';

const tmp = mkdtempSync(join(tmpdir(), 'central-ia-limpeza-'));

// ---------- girar ----------
const log = join(tmp, 'runner.log');
writeFileSync(log, 'x'.repeat(100), { mode: 0o600 });
assert.equal(girar(log, { limite: 100 }), false, 'no limite exato, fica');
writeFileSync(log, 'a'.repeat(101));
assert.equal(girar(log, { limite: 100 }), true, 'um byte acima, gira');
assert.equal(existsSync(log), false, 'renomeado');
assert.equal(readFileSync(`${log}.1`, 'utf8').length, 101);
assert.equal(statSync(`${log}.1`).mode & 0o777, 0o600, 'a cópia guarda a permissão');
writeFileSync(log, 'b'.repeat(101));
girar(log, { limite: 100 });
writeFileSync(log, 'c'.repeat(101));
girar(log, { limite: 100, copias: 2 });
assert.equal(readFileSync(`${log}.1`, 'utf8')[0], 'c');
assert.equal(readFileSync(`${log}.2`, 'utf8')[0], 'b', 'guarda só as duas últimas');
assert.equal(existsSync(`${log}.3`), false);
// Copiar e esvaziar: o arquivo continua o mesmo (o launchd segue escrevendo nele).
writeFileSync(log, 'd'.repeat(101));
const ino = statSync(log).ino;
assert.equal(girar(log, { limite: 100, copiar: true }), true);
assert.equal(statSync(log).ino, ino);
assert.equal(statSync(log).size, 0);
assert.equal(readFileSync(`${log}.1`, 'utf8')[0], 'd');
assert.equal(girar(join(tmp, 'nao-existe.log')), false);

// ---------- nome da worktree ----------
assert.deepEqual(lerNomeDaWorktree('alfa-matriz-t42'), { projeto: 'alfa-matriz', tarefa: 42 });
assert.equal(lerNomeDaWorktree('alfamatriz-te17'), null, 'execução sem tarefa não entra');

// ---------- worktrees ----------
const git = (cwd, ...a) => execFileSync('git', ['-C', cwd, ...a], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
const projetos = join(tmp, 'projetos');
const proj = join(projetos, 'app');
mkdirSync(proj, { recursive: true });
git(proj, 'init', '-q', '-b', 'main'); git(proj, 'config', 'user.email', 't@t'); git(proj, 'config', 'user.name', 't');
writeFileSync(join(proj, 'a.txt'), '1'); writeFileSync(join(proj, '.gitignore'), 'node_modules/\n');
git(proj, 'add', '.'); git(proj, 'commit', '-qm', 'base');
const worktrees = join(projetos, '.central-ia', 'worktrees');
const criar = id => { const wt = join(worktrees, `app-t${id}`); git(proj, 'worktree', 'add', '-q', '-b', `central-ia/t${id}`, wt, 'HEAD'); return wt; };

const limpa = criar(1);
mkdirSync(join(limpa, 'node_modules')); writeFileSync(join(limpa, 'node_modules', 'x.js'), ''); // ignorado: descartável
const suja = criar(2); writeFileSync(join(suja, 'a.txt'), 'mudou');
const nova = criar(3); writeFileSync(join(nova, 'novo.txt'), 'só aqui');
const commit = criar(4); writeFileSync(join(commit, 'c.txt'), 'c'); git(commit, 'add', '.'); git(commit, 'commit', '-qm', 'fora da main');
const aberta = criar(5);
const rodando = criar(6);
const mesclada = criar(7); writeFileSync(join(mesclada, 'm.txt'), 'm'); git(mesclada, 'add', '.'); git(mesclada, 'commit', '-qm', 'vai para a main');
git(proj, 'merge', '-q', '--ff-only', 'central-ia/t7');

assert.equal(motivoParaFicar(git, limpa), null);
assert.equal(motivoParaFicar(git, suja), 'tem alteração pendente');
assert.equal(motivoParaFicar(git, nova), 'tem alteração pendente');
assert.equal(motivoParaFicar(git, commit), 'tem commit fora da main');
assert.match(motivoParaFicar(git, join(tmp, 'nao-e-git')), /git falhou/, 'na dúvida, fica');

const feitas = limparWorktrees({ git, worktrees, projetos, encerradas: [1, 2, 3, 4, 6, 7], emCurso: new Set([6]) });
const por = Object.fromEntries(feitas.map(f => [f.worktree.split('-t').pop(), f]));
assert.equal(existsSync(limpa), false, 'encerrada e limpa: sai');
assert.equal(por['1'].removida, true);
assert.equal(existsSync(mesclada), false, 'commit já na main: sai');
for (const [wt, id] of [[suja, '2'], [nova, '3'], [commit, '4']]) {
    assert.equal(existsSync(wt), true); assert.equal(por[id].removida, false);
}
assert.equal(existsSync(join(nova, 'novo.txt')), true, 'o trabalho fica');
assert.equal(existsSync(aberta), true, 'tarefa não encerrada: fica');
assert.equal(existsSync(rodando), true, 'executando agora: fica');
assert.equal(por['5'], undefined); assert.equal(por['6'], undefined);
// A branch fica: reabrir a tarefa recria a pasta em cima dela.
assert.ok(git(proj, 'branch', '--list', 'central-ia/t1'));
// Sem main nenhuma, não há com o que comparar: fica.
const solto = join(tmp, 'solto'); mkdirSync(solto);
git(solto, 'init', '-q', '-b', 'trabalho'); git(solto, 'config', 'user.email', 't@t'); git(solto, 'config', 'user.name', 't');
writeFileSync(join(solto, 'a'), '1'); git(solto, 'add', '.'); git(solto, 'commit', '-qm', 'x');
assert.equal(motivoParaFicar(git, solto), 'não achei a main do projeto');
assert.deepEqual(limparWorktrees({ git, worktrees: join(tmp, 'nao-existe'), projetos, encerradas: [1] }), []);

// ---------- auditoria: auditar() gira sozinha no limite ----------
const aud = join(tmp, 'auditoria.log');
process.env.CENTRAL_IA_AUDITORIA = aud;
const { auditar } = await import('../../deploy/central-ia/confirmacao.mjs');
auditar({ decisao: 'primeira' });
assert.equal(statSync(aud).mode & 0o777, 0o600);
writeFileSync(aud, 'x'.repeat(10 * 1024 * 1024 + 1));
auditar({ decisao: 'depois_do_limite' });
assert.equal(statSync(`${aud}.1`).size, 10 * 1024 * 1024 + 1);
assert.match(readFileSync(aud, 'utf8'), /^\{.*depois_do_limite.*\}\n$/, 'o arquivo novo começa com o evento');
assert.equal(statSync(aud).mode & 0o777, 0o600, 'o arquivo novo também é só do dono');

console.log('test-limpeza: ok');
