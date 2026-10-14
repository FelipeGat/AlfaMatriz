// Runner inviolável (FR-022, FR-024, FR-025): política local e confirmação
// fora de banda. Sem rede, sem agentes, sem servidor real.
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, readFileSync, symlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const dir = mkdtempSync(join(tmpdir(), 'central-ia-conf-'));
process.env.CENTRAL_IA_AUDITORIA = join(dir, 'auditoria.log');
const { acessoEfetivo, dentroDe, exigeConfirmacao, permissoes, CITA_CREDENCIAL } = await import('../../deploy/central-ia/politica.mjs');
const { canonico, codigo, confere, confirmar, mensagem } = await import('../../deploy/central-ia/confirmacao.mjs');

// 1. O servidor não amplia o acesso: analisar é leitura; total só com teto total.
assert.equal(acessoEfetivo({ modo: 'analisar', acesso: 'total' }, 'total'), 'leitura');
assert.equal(acessoEfetivo({ modo: 'implementar', acesso: 'total' }, 'restrito'), 'restrito');
assert.equal(acessoEfetivo({ modo: 'implementar', acesso: 'restrito' }, 'total'), 'restrito');
assert.equal(acessoEfetivo({ modo: 'implementar', acesso: 'total' }, 'total'), 'total');
assert.ok(exigeConfirmacao({ modo: 'implementar' }, 'implementar'));
assert.ok(!exigeConfirmacao({ modo: 'analisar' }, 'implementar'));
assert.ok(exigeConfirmacao({ modo: 'analisar' }, 'sempre'));
// "inicio": só a primeira implementação do chat, e deploy sempre.
assert.ok(exigeConfirmacao({ modo: 'implementar', instrucao: 'corrija o filtro' }, 'inicio', false));
assert.ok(!exigeConfirmacao({ modo: 'implementar', instrucao: 'corrija o filtro' }, 'inicio', true));
assert.ok(exigeConfirmacao({ modo: 'implementar', instrucao: 'pode fazer o deploy' }, 'inicio', true));
assert.ok(exigeConfirmacao({ modo: 'analisar', instrucao: 'suba para produção' }, 'deploy', true));
assert.ok(!exigeConfirmacao({ modo: 'implementar', instrucao: 'corrija' }, 'deploy', false));
assert.ok(exigeConfirmacao({ modo: 'implementar', instrucao: 'faça o deploy' }, 'nunca', false), 'deploy pede código mesmo com "nunca"');
assert.ok(exigeConfirmacao({ modo: 'analisar', instrucao: 'faça o push da branch' }, 'nunca', true));
assert.ok(!exigeConfirmacao({ modo: 'implementar', instrucao: 'corrija o filtro' }, 'nunca', false));
// A pessoa só deixa mais rígido que o mínimo da máquina.
const { regraEfetiva } = await import('../../deploy/central-ia/politica.mjs');
assert.equal(regraEfetiva('inicio', 'nunca'), 'inicio');
assert.equal(regraEfetiva('inicio', 'implementar'), 'implementar');
assert.equal(regraEfetiva('nunca', 'nunca'), 'nunca');
assert.equal(regraEfetiva('deploy', undefined), 'deploy');

// 2. Leitura de verdade em cada CLI.
const claude = permissoes('claude', 'leitura', { projetos: '/p' });
assert.ok(!claude.includes('bypassPermissions'));
assert.ok(claude.includes('Edit') && claude.includes('Write') && claude.indexOf('Edit') > claude.indexOf('--disallowedTools'));
assert.ok(claude.includes('Read(~/.ssh/**)') && claude.includes('Read(**/.env)'));
assert.ok(claude.includes('Bash(git log:*)') && !claude.includes('Bash'));
// Leitura não lê arquivo pelo shell nem manda nada para fora (revisão 13/10/2026).
for (const proibido of ['Bash(cat:*)', 'Bash(grep:*)', 'Bash(find:*)', 'Bash(head:*)', 'WebFetch']) assert.ok(!claude.includes(proibido), proibido);
assert.deepEqual(permissoes('codex', 'leitura').filter(a => a.startsWith('sandbox')), ['sandbox_mode="read-only"']);
const copilot = permissoes('copilot', 'leitura', { cwd: '/p/x' });
assert.ok(!copilot.includes('--allow-all') && copilot.includes('shell') && copilot.includes('write'));
assert.ok(permissoes('claude', 'total', { projetos: '/p' }).includes('bypassPermissions'));
assert.ok(CITA_CREDENCIAL.test('mostre o conteúdo de ~/.ssh/id_ed25519'));
assert.ok(CITA_CREDENCIAL.test('leia o .env do projeto'));
assert.ok(!CITA_CREDENCIAL.test('quantos tokens gastei esta semana?'));

// 3. Pasta fora dos projetos é recusada, inclusive por link simbólico.
const projetos = join(dir, 'projetos'); mkdirSync(join(projetos, 'alfahome'), { recursive: true });
mkdirSync(join(dir, 'segredos'));
symlinkSync(join(dir, 'segredos'), join(projetos, 'atalho'));
assert.ok(dentroDe(projetos, join(projetos, 'alfahome')));
assert.ok(!dentroDe(projetos, join(dir, 'segredos')));
assert.ok(!dentroDe(projetos, join(projetos, 'atalho')), 'link para fora conta como fora');
assert.ok(!dentroDe(projetos, join(projetos, '..', 'segredos')));

// 4. Código preso ao conteúdo: mudar a instrução muda o código.
const segredo = 'a'.repeat(64);
const job = { id: 7, agente: 'claude', projeto: 'alfahome', modo: 'implementar', instrucao: 'corrija o filtro', anexos: [] };
const c1 = codigo(segredo, canonico(job, '/p/alfahome', 'total'));
assert.match(c1, /^\d{6}$/);
assert.notEqual(c1, codigo(segredo, canonico({ ...job, instrucao: 'apague tudo' }, '/p/alfahome', 'total')));
assert.notEqual(c1, codigo(segredo, canonico(job, '/home/x/.ssh', 'total')));
assert.notEqual(c1, codigo('b'.repeat(64), canonico(job, '/p/alfahome', 'total')));
assert.ok(confere(c1, c1) && !confere(c1, '000000') && !confere(c1, null));
assert.ok(mensagem(job, '/p/alfahome', 'total', c1).includes('corrija o filtro'), 'a pessoa lê o pedido exato');

// 5. Fluxo: sem canal recusa; código certo libera; três erros recusam; tempo recusa.
const cfg = { maquina: 'wsl', segredo, telegramToken: 'T', telegramChat: '1' };
function servidor(digitados) {
    const chamadas = [];
    return { chamadas, api: async (caminho, corpo) => { chamadas.push(corpo.estado); return { status: 200, json: { codigo: corpo.estado === 'aguardando' || corpo.estado === 'codigo_errado' ? (digitados.shift() ?? null) : null } }; } };
}
let enviado = null;
const enviar = async (_c, texto) => { enviado = texto; };
const dormir = async () => {};

let s = servidor([]);
let r = await confirmar({ job, cwd: '/p/alfahome', acesso: 'total', cfg: { ...cfg, telegramToken: null }, api: s.api, enviar, dormir });
assert.equal(r.ok, false); assert.deepEqual(s.chamadas, ['sem_canal']);

s = servidor([null, c1]);
r = await confirmar({ job, cwd: '/p/alfahome', acesso: 'total', cfg, api: s.api, enviar, dormir });
assert.equal(r.ok, true); assert.equal(s.chamadas.at(-1), 'confirmada');
assert.ok(enviado.includes(c1), 'o código vai ao Telegram pela máquina');

s = servidor(['111111', '222222', '333333']);
r = await confirmar({ job, cwd: '/p/alfahome', acesso: 'total', cfg, api: s.api, enviar, dormir });
assert.equal(r.ok, false); assert.equal(s.chamadas.at(-1), 'recusada');

let relogio = 0;
s = servidor([]);
r = await confirmar({ job, cwd: '/p/alfahome', acesso: 'total', cfg, api: s.api, enviar, dormir: async () => { relogio += 60_000; }, agora: () => relogio });
assert.equal(r.ok, false); assert.equal(s.chamadas.at(-1), 'expirada');

// 6. O servidor nunca vê o código esperado; a auditoria local registra tudo.
const log = readFileSync(process.env.CENTRAL_IA_AUDITORIA, 'utf8');
assert.ok(!log.includes(c1), 'a auditoria não guarda o código');
for (const d of ['sem_canal', 'pedida', 'confirmada', 'codigo_errado', 'recusada', 'expirada']) assert.ok(log.includes(`"decisao":"${d}"`), d);

// 7. Envio ao Telegram: falha de rede tenta de novo; recusa do Telegram não.
const { enviarTelegram } = await import('../../deploy/central-ia/confirmacao.mjs');
let chamadasRede = 0;
await enviarTelegram(cfg, 'x', { dormir, buscar: async () => { if (++chamadasRede < 3) throw new TypeError('fetch failed'); return { status: 200, json: async () => ({ ok: true }) }; } });
assert.equal(chamadasRede, 3, 'duas falhas de rede e a terceira passa');
let recusas = 0;
await assert.rejects(enviarTelegram(cfg, 'x', { dormir, buscar: async () => { recusas++; return { status: 400, json: async () => ({ ok: false }) }; } }), /recusou/);
assert.equal(recusas, 1, 'recusa não repete');
await assert.rejects(enviarTelegram(cfg, 'x', { dormir, buscar: async () => { throw new TypeError('fetch failed'); } }), /fetch failed/);

// 8. cstk para quem implementa (13/10/2026): Claude roda o pipeline; Codex e
// Copilot não implementam frente nova sem spec, devolvem ao Claude.
const { regraCstk } = await import('../../deploy/central-ia/politica.mjs');
assert.match(regraCstk('claude', 'gestor'), /\/cstk:specify/);
assert.match(regraCstk('claude', 'gestor'), /\/cstk:bugfix/);
for (const a of ['codex', 'copilot']) {
    assert.match(regraCstk(a, 'gestor'), /NÃO implemente/);
    assert.match(regraCstk(a, 'gestor'), /"delegar": \{"agente": "claude"/);
}
assert.equal(regraCstk('codex', ''), null, 'assunto sem projeto não é código de repositório');

console.log('PASSOU: teto local, leitura por CLI, pastas, código preso ao conteúdo, confirmação, recusa, expiração e auditoria.');

// T-368: análise só ganha acesso total se o servidor também pedir.
assert.equal(acessoEfetivo({ modo: 'analisar', acesso: 'restrito' }, 'total', 'total'), 'leitura');
// T-368: código só no início da conversa e em deploy; fora de deploy, o acesso
// total não publica (push, ssh, gh…), e o Codex nem recebe acesso total.
assert.ok(!exigeConfirmacao({ modo: 'implementar', instrucao: 'corrija o filtro' }, 'inicio', true));
assert.ok(exigeConfirmacao({ modo: 'implementar', instrucao: 'faça o push' }, 'inicio', true));
const claudeTotal = permissoes('claude', 'total', { projetos: '/p' });
assert.ok(claudeTotal.includes('bypassPermissions') && claudeTotal.includes('Bash(git push:*)') && claudeTotal.includes('Bash(ssh:*)'));
assert.ok(!permissoes('claude', 'total', { projetos: '/p', deploy: true }).includes('Bash(git push:*)'));
assert.ok(permissoes('copilot', 'total', { cwd: '/p/x' }).includes('shell(git push)'));
assert.ok(!permissoes('copilot', 'total', { cwd: '/p/x', deploy: true }).includes('shell(git push)'));
assert.deepEqual(permissoes('codex', 'total').filter(a => a.startsWith('sandbox')), ['sandbox_mode="workspace-write"']);
assert.deepEqual(permissoes('codex', 'total', { deploy: true }).filter(a => a.startsWith('sandbox')), ['sandbox_mode="danger-full-access"']);
console.log('T-368: acesso total sem publicar fora de deploy');
