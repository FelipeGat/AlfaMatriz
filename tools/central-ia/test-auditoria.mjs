// Quem viu o quê e quem alterou o quê: eventos das CLIs e git antes/depois.
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { compararGit, criarAuditor, retratoGit } from '../../deploy/central-ia/auditoria.mjs';
import { acessoEfetivo } from '../../deploy/central-ia/politica.mjs';

// Análise: decide a máquina; o servidor não amplia.
assert.equal(acessoEfetivo({ modo: 'analisar', acesso: 'total' }, 'total', 'total'), 'total');
assert.equal(acessoEfetivo({ modo: 'analisar', acesso: 'total' }, 'total', 'leitura'), 'leitura');
assert.equal(acessoEfetivo({ modo: 'analisar', acesso: 'total' }, 'restrito', 'total'), 'leitura');

// Eventos do Claude (stream-json), em pedaços.
const c = criarAuditor('claude');
const linha = JSON.stringify({ type: 'assistant', message: { content: [{ type: 'tool_use', name: 'Bash', input: { command: 'git log --all' } }, { type: 'tool_use', name: 'Read', input: { file_path: '/p/x.php' } }] } });
c.saida(linha.slice(0, 30)); c.saida(linha.slice(30) + '\n{"type":"result","result":"ok"}\n');
assert.deepEqual(c.acessos(), [{ ferramenta: 'Bash', alvo: 'git log --all' }, { ferramenta: 'Read', alvo: '/p/x.php' }]);

// Eventos do Codex (--json).
const x = criarAuditor('codex');
x.saida(JSON.stringify({ type: 'item.started', item: { type: 'command_execution', command: 'git fetch' } }) + '\n'
    + JSON.stringify({ type: 'item.completed', item: { type: 'file_change', changes: [{ path: 'a.txt', kind: 'add' }] } }) + '\n');
assert.deepEqual(x.acessos().map(a => a.ferramenta), ['shell', 'edição']);
assert.equal(criarAuditor('copilot').informado, false);

// Git: arquivo novo, arquivo já sujo mexido de novo, commit novo.
const dir = mkdtempSync(join(tmpdir(), 'central-ia-aud-'));
const git = (cwd, ...a) => execFileSync('git', ['-C', cwd, ...a], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
git(dir, 'init', '-q'); git(dir, 'config', 'user.email', 't@t'); git(dir, 'config', 'user.name', 't');
writeFileSync(join(dir, 'base.txt'), '1'); git(dir, 'add', '.'); git(dir, 'commit', '-qm', 'base');
writeFileSync(join(dir, 'base.txt'), '2');               // já sujo antes
const antes = retratoGit(git, dir);
writeFileSync(join(dir, 'base.txt'), '3');               // mexido de novo pela IA
writeFileSync(join(dir, 'novo.txt'), 'n');
writeFileSync(join(dir, 'c.txt'), 'c'); git(dir, 'add', 'c.txt'); git(dir, 'commit', '-qm', 'commit da IA');
const d = compararGit(git, dir, antes, retratoGit(git, dir));
assert.ok(d.arquivos.includes('novo.txt') && d.arquivos.includes('base.txt'), JSON.stringify(d));
assert.equal(d.commits.length, 1); assert.match(d.commits[0], /commit da IA/);
console.log('PASSOU: acesso da análise decidido na máquina, eventos do Claude e do Codex, arquivos e commits alterados.');
