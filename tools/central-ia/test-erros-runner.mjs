import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

// Só as funções puras de interpretação; nunca inicia o runner nem um CLI.
const source = readFileSync(new URL('../../deploy/central-ia/runner.mjs', import.meta.url), 'utf8');
const trecho = source.slice(source.indexOf('function erroDoProcesso('), source.indexOf('function resumoDaBranch('));
const sandbox = { existsSync: () => true, readFileSync: () => 'resposta parcial' };
vm.runInNewContext(trecho, sandbox);
const interpretar = sandbox.interpretar;
const error = { codigo: 1, out: '', err: '', estourou: false };
assert.throws(() => interpretar({ agente: 'codex' }, { ...error, out: '{"type":"turn.failed","error":{"message":"You have reached your usage limit"}}' }, 'fake'), /usage limit/);
assert.throws(() => interpretar({ agente: 'copilot' }, { ...error, out: 'premium request limit reached' }), /premium request limit/);
assert.throws(() => interpretar({ agente: 'claude' }, { ...error, out: '{"is_error":true,"result":"You have hit your weekly limit"}' }), /weekly limit/);
assert.equal(interpretar({ agente: 'codex' }, { ...error, codigo: 0 }, 'fake').resposta, 'resposta parcial');
assert.equal(interpretar({ agente: 'copilot' }, { ...error, codigo: 0, out: 'Feito.' }, '', 'sessao').resposta, 'Feito.');
const fakeSecret = 'sk-SOMENTE_TESTE';
const sanitized = sandbox.erroDoProcesso({ ...error, err: `service unavailable Bearer ${fakeSecret}` });
assert.ok(!sanitized.includes(fakeSecret));
assert.match(source, /forced_login_method="chatgpt"/);
assert.match(source, /'OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'ANTHROPIC_AUTH_TOKEN'/);
console.log('PASSOU: erro estruturado, saída parcial, franquias dos três CLIs, redação de segredo sintético e autenticação ChatGPT exigida. Nenhum agente executado.');
