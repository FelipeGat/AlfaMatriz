// Chats Business (docs/specs/chats-business): o extrato da semana que semeia um
// chat de assunto, e a lista de conversas com o nome dado pela pessoa.
// Sem rede, sem agentes; HOME temporário.
import assert from 'node:assert/strict';
import { mkdirSync, mkdtempSync, utimesSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const home = mkdtempSync(join(tmpdir(), 'central-ia-extrato-'));
process.env.HOME = home;
const { extrair, formatar, montarSemente } = await import('../../deploy/central-ia/extrato.mjs');
const { usoLocal, arquivoDaSessao, IMPORTADAS } = await import('../../deploy/central-ia/uso-local.mjs');

const raiz = join(home, 'projetos');
mkdirSync(raiz);
const agora = Date.now();
const iso = dias => new Date(agora - dias * 86400_000).toISOString();
const linhas = l => l.map(x => JSON.stringify(x)).join('\n') + '\n';

// 1. Claude: pedido, ferramenta, subagente, interno e turno de 10 dias atrás.
const dirClaude = join(home, '.claude/projects/-home-x-projetos');
mkdirSync(dirClaude, { recursive: true });
const sessao = 'aaaaaaaa-1111-2222-3333-444444444444';
const arqClaude = join(dirClaude, `${sessao}.jsonl`);
writeFileSync(arqClaude, linhas([
    { type: 'user', sessionId: sessao, cwd: raiz, entrypoint: 'claude-vscode', timestamp: iso(10), message: { content: 'pedido antigo, fora da semana' } },
    { type: 'assistant', timestamp: iso(10), message: { content: [{ type: 'text', text: 'resposta antiga' }] } },
    { type: 'custom-title', sessionId: sessao, customTitle: 'Proposta Antiga' },
    { type: 'user', sessionId: sessao, cwd: raiz, timestamp: iso(2), message: { content: 'monte a proposta na pasta ~/OneDrive/Propostas/VixCard' } },
    { type: 'assistant', timestamp: iso(2), message: { content: [{ type: 'tool_use', name: 'Bash', input: { command: 'ls' } }] } },
    { type: 'user', timestamp: iso(2), message: { content: [{ type: 'tool_result', content: 'SAIDA DE FERRAMENTA SECRETA' }] } },
    { type: 'assistant', isSidechain: true, timestamp: iso(2), message: { content: [{ type: 'text', text: 'subagente falando' }] } },
    { type: 'user', isMeta: true, timestamp: iso(2), message: { content: 'mensagem interna' } },
    { type: 'user', timestamp: iso(2), message: { content: '<command-name>/clear</command-name>' } },
    { type: 'assistant', timestamp: iso(2), message: { content: [{ type: 'text', text: 'Proposta salva em ~/OneDrive/Propostas/VixCard/cliente.pdf' }] } },
    { type: 'custom-title', sessionId: sessao, customTitle: 'Proposta Comercial VixCard' },
    { type: 'user', timestamp: iso(1), message: { content: 'x'.repeat(9000) } },
]));

const turnos = extrair(arqClaude, { desdeMs: agora - 7 * 86400_000 });
assert.equal(turnos.length, 2, 'só os turnos da semana, sem interno nem comando');
assert.match(turnos[0].pedido, /OneDrive\/Propostas/);
assert.match(turnos[0].resposta, /cliente\.pdf/);
const tudo = JSON.stringify(turnos);
for (const fora of ['SAIDA DE FERRAMENTA', 'subagente', 'mensagem interna', '/clear', 'pedido antigo']) assert.ok(!tudo.includes(fora), fora);
assert.equal(turnos[1].pedido.length, 4000, 'pedido cortado em 4 mil');

// 2. formatar: dos mais recentes para os antigos, dentro do limite.
const f = formatar(turnos, 4100);
assert.equal(f.incluidos, 1); assert.equal(f.fora, 1);
assert.ok(f.texto.includes('xxxx') && !f.texto.includes('OneDrive'));
assert.equal(formatar(turnos).incluidos, 2);

// 3. Codex: response_item de mensagem; contexto do ambiente não é pedido.
const arqCodex = join(home, 'rollout-teste.jsonl');
writeFileSync(arqCodex, linhas([
    { type: 'response_item', timestamp: iso(1), payload: { type: 'message', role: 'user', content: [{ type: 'input_text', text: '<environment_context><cwd>/x</cwd></environment_context>' }] } },
    { type: 'response_item', timestamp: iso(1), payload: { type: 'message', role: 'user', content: [{ type: 'input_text', text: 'qual a etapa da obra?' }] } },
    { type: 'response_item', timestamp: iso(1), payload: { type: 'message', role: 'assistant', content: [{ type: 'output_text', text: 'Etapa: acabamento.' }] } },
]));
const tc = extrair(arqCodex, { agente: 'codex', desdeMs: agora - 7 * 86400_000 });
assert.deepEqual(tc.map(t => [t.pedido, t.resposta.trim()]), [['qual a etapa da obra?', 'Etapa: acabamento.']]);

// 4. montarSemente: só pelo localizador da máquina; conversa desconhecida falha.
const s = montarSemente([{ agente: 'claude', id: sessao, titulo: 'Proposta Comercial VixCard' }],
    { localizar: (a, id) => (a === 'claude' && id === sessao ? { arquivo: arqClaude, copia: false } : null), agora });
assert.match(s.texto, /NÃO execute/);
assert.match(s.texto, /Proposta Comercial VixCard \(Claude, 2 turnos/);
assert.deepEqual(s.lidas.map(l => [l.id, l.incluidos]), [[sessao, 2]]);
assert.throws(() => montarSemente([{ agente: 'claude', id: 'outra' }], { localizar: () => null }), /não foi encontrada/);

// 5. usoLocal: o nome que a pessoa deu (o último), conversa da raiz com
// projeto '' e a cópia do Mac na lista; arquivoDaSessao só acha o que listou.
mkdirSync(IMPORTADAS, { recursive: true });
const copia = 'bbbbbbbb-1111-2222-3333-444444444444';
const arqCopia = join(IMPORTADAS, `${copia}.jsonl`);
writeFileSync(arqCopia, linhas([
    { type: 'user', sessionId: copia, cwd: '/Users/felipe/projetos', timestamp: iso(1), message: { content: 'andamento da obra' } },
    { type: 'custom-title', sessionId: copia, customTitle: 'Relatório avanço de obra' },
]));
utimesSync(arqCopia, new Date(), new Date());
const lista = usoLocal(raiz).sessoes;
const daRaiz = lista.find(x => x.id === sessao);
assert.equal(daRaiz.titulo, 'Proposta Comercial VixCard', 'o último nome vale');
assert.equal(daRaiz.projeto, '');
assert.equal(daRaiz.origem, 'wsl');
const doMac = lista.find(x => x.id === copia);
assert.equal(doMac.origem, 'mac_copia'); assert.equal(doMac.projeto, '');
assert.deepEqual(arquivoDaSessao('claude', copia), { arquivo: arqCopia, copia: true });
assert.equal(arquivoDaSessao('codex', sessao), null, 'agente errado não acha');
assert.equal(arquivoDaSessao('claude', 'nao-existe'), null);
assert.ok(!JSON.stringify(lista).includes('OneDrive'), 'o texto da conversa não sai na lista');

console.log('PASSOU: extrato da semana (sem ferramenta, subagente, interno), corte, partes, Codex, semente pelo localizador, títulos, raiz e cópia do Mac.');
