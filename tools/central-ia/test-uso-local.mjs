// Consumo do Claude sem contagem em dobro entre arquivos (fork/resume copiam
// o histórico). HOME sintético; nenhum registro real é lido.
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, appendFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const home = mkdtempSync(join(tmpdir(), 'central-ia-uso-'));
process.env.HOME = home;
const dir = join(home, '.claude/projects/-p');
mkdirSync(dir, { recursive: true });
mkdirSync(join(home, 'projetos'), { recursive: true });
const hoje = new Date().toISOString();
const resposta = (id, entrada) => JSON.stringify({ type: 'assistant', timestamp: hoje, requestId: `r-${id}`,
    message: { id, model: 'claude-teste', usage: { input_tokens: entrada, output_tokens: 1 } } });

// Sessão original com duas respostas; o fork copia as duas e acrescenta uma.
writeFileSync(join(dir, 'original.jsonl'), [resposta('m1', 100), resposta('m2', 200)].join('\n') + '\n');
writeFileSync(join(dir, 'fork.jsonl'), [resposta('m1', 100), resposta('m2', 200), resposta('m3', 50)].join('\n') + '\n');

const { usoLocal } = await import('../../deploy/central-ia/uso-local.mjs');
const total = r => r.consumo.linhas.filter(l => l.agente === 'claude').reduce((s, l) => s + l.entrada, 0);
assert.equal(total(usoLocal(join(home, 'projetos'))), 350, 'cada resposta conta uma vez, em qualquer arquivo');

// Leitura incremental: nova resposta só no fork, e cópia repetida no original.
appendFileSync(join(dir, 'fork.jsonl'), resposta('m4', 25) + '\n');
appendFileSync(join(dir, 'original.jsonl'), resposta('m3', 50) + '\n');
assert.equal(total(usoLocal(join(home, 'projetos'))), 375);

console.log('PASSOU: consumo do Claude deduplicado entre sessão original e cópia, também na leitura incremental.');
