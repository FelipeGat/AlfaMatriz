// Runner inviolável (FR-022, FR-024, FR-025): política local e confirmação
// fora de banda. Sem rede, sem agentes, sem servidor real.
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, readFileSync, symlinkSync, writeFileSync } from 'node:fs';
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

// T-374: nada preso à máquina de uma pessoa.
{
    const { arquivoDeMemoria, agendaSemIa } = await import('../../deploy/central-ia/politica.mjs');
    const home = mkdtempSync(join(tmpdir(), 'central-ia-mem-'));
    const projetos = join(home, 'meus projetos');
    const derivada = join(home, '.claude/projects', projetos.replace(/[^A-Za-z0-9]/g, '-'), 'memory/MEMORY.md');
    assert.equal(arquivoDeMemoria('', projetos, home), null, 'sem memória na máquina, nada vai');
    mkdirSync(join(derivada, '..'), { recursive: true }); writeFileSync(derivada, '- nota');
    assert.equal(arquivoDeMemoria('', projetos, home), derivada, 'a memória do Claude para a pasta de projetos');
    assert.equal(arquivoDeMemoria('nenhuma', projetos, home), null, '`nenhuma` desliga na máquina');
    assert.equal(arquivoDeMemoria(join(home, 'nao-existe.md'), projetos, home), null);
    assert.match(agendaSemIa('hoje', '2026-10-14', '', 'Agenda do AlfaMatriz'), /Lido agora \(Agenda do AlfaMatriz\), sem IA/);
    assert.ok(!/Google/.test(agendaSemIa('hoje', '2026-10-14', '2026-10-14 09:00 [Compromisso] Reunião')));
    console.log('T-374: memória escolhida pela máquina e agenda sem fonte fixa');
}

// T-368: a auditoria não chama de "leitura" a implementação que rodou sem código.
{
    const { decisaoSemConfirmacao } = await import('../../deploy/central-ia/politica.mjs');
    assert.deepEqual(decisaoSemConfirmacao({ modo: 'analisar' }, 'inicio', 'leitura'), { decisao: 'leitura' });
    assert.deepEqual(decisaoSemConfirmacao({ modo: 'implementar' }, 'inicio', 'restrito'), { decisao: 'sem_confirmacao', regra: 'inicio', acesso: 'restrito' });
    assert.equal(decisaoSemConfirmacao({ modo: 'analisar' }, 'nunca', 'total').decisao, 'sem_confirmacao', 'análise com acesso total também não é leitura');
    console.log('T-368: auditoria sem "leitura" falsa');
}

// T-368: chat confirmado vale só no mesmo servidor, na mesma conta e no mesmo
// chat. O chat 5 confirmado no staging liberou o chat 5 da produção, de outra
// conta, sem pedir código.
{
    const { chaveDoChat, chatConfirmado, marcarChatConfirmado, VALIDADE_CHAT_MS } = await import('../../deploy/central-ia/confirmacao.mjs');
    const arq = join(dir, 'chats-confirmados.json');
    const staging = 'https://alfamatriz-staging.exemplo', producao = 'https://matriz.exemplo';
    const agora = 1_000_000;
    marcarChatConfirmado(arq, chaveDoChat(staging, 7, 5), agora);
    assert.ok(chatConfirmado(arq, chaveDoChat(staging, 7, 5), agora), 'o mesmo chat continua liberado');
    assert.ok(chatConfirmado(arq, chaveDoChat(staging + '/', 7, 5), agora), 'barra no fim do endereço não muda o servidor');
    assert.ok(!chatConfirmado(arq, chaveDoChat(producao, 7, 5), agora), 'mesmo número em outro servidor pede código');
    assert.ok(!chatConfirmado(arq, chaveDoChat(staging, 8, 5), agora), 'mesmo número em outra conta pede código');
    assert.ok(!chatConfirmado(arq, chaveDoChat(staging, 7, 6), agora), 'outro chat pede código');
    assert.ok(!chatConfirmado(arq, chaveDoChat(staging, 7, 5), agora + VALIDADE_CHAT_MS + 1), 'vence em 12 h');
    // Servidor sem a conta no pedido: não há chave, nada fica liberado nem gravado.
    assert.equal(chaveDoChat(producao, undefined, 5), null);
    assert.ok(!chatConfirmado(arq, chaveDoChat(producao, undefined, 5), agora));
    marcarChatConfirmado(arq, chaveDoChat(producao, undefined, 5), agora);
    assert.deepEqual(Object.keys(JSON.parse(readFileSync(arq, 'utf8'))), [chaveDoChat(staging, 7, 5)]);
    // O arquivo do formato antigo (só o número do chat) não libera nada.
    const antigo = join(dir, 'chats-antigos.json');
    writeFileSync(antigo, JSON.stringify({ 5: agora + VALIDADE_CHAT_MS }));
    assert.ok(!chatConfirmado(antigo, chaveDoChat(producao, 7, 5), agora), 'entrada antiga, sem servidor e conta, não vale');
    marcarChatConfirmado(antigo, chaveDoChat(producao, 7, 9), agora);
    assert.deepEqual(Object.keys(JSON.parse(readFileSync(antigo, 'utf8'))), [chaveDoChat(producao, 7, 9)], 'a entrada antiga some na próxima gravação');
    // Codex com acesso total fora de deploy só com a opção da máquina (16/10/2026).
const sandbox = a => a.find(x => x.startsWith('sandbox_mode='));
assert.equal(sandbox(permissoes('codex', 'total')), 'sandbox_mode="workspace-write"', 'padrão: sem rede fora de deploy');
assert.equal(sandbox(permissoes('codex', 'total', { deploy: true })), 'sandbox_mode="danger-full-access"');
assert.equal(sandbox(permissoes('codex', 'total', { codexTotal: true })), 'sandbox_mode="danger-full-access"', 'opção da máquina');
assert.equal(sandbox(permissoes('codex', 'restrito', { codexTotal: true })), 'sandbox_mode="workspace-write"', 'a opção não passa do teto');

console.log('T-368: chat confirmado preso a servidor + conta + chat');
}

// Secretário (job.acesso 'nenhum'): conteúdo de terceiros vai sem ferramenta
// nenhuma, mesmo com a máquina e o servidor em acesso total.
assert.equal(acessoEfetivo({ modo: 'analisar', acesso: 'nenhum' }, 'total', 'total'), 'leitura', 'nenhum nunca vira total');
{
    const c = permissoes('claude', 'total', { projetos: '/p', semFerramentas: true });
    assert.ok(!c.includes('bypassPermissions') && !c.includes('--allowedTools'), 'sem bypass e sem lista de permitidas');
    for (const f of ['Read', 'Bash', 'WebSearch', 'WebFetch', 'Grep', 'Glob']) assert.ok(c.includes(f), `nega ${f}`);
    assert.ok(permissoes('codex', 'total', { semFerramentas: true, codexTotal: true }).includes('sandbox_mode="read-only"'));
    const cp = permissoes('copilot', 'total', { cwd: '/p', semFerramentas: true });
    assert.ok(!cp.includes('--allow-all') && cp.includes('shell') && cp.includes('write'));
}
console.log('PASSOU: pedido do Secretário sem ferramentas em qualquer acesso.');
