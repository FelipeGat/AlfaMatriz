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

// T-374: conversa aberta FORA da pasta de projetos não sai da máquina — nem o
// título, nem o começo do pedido —, mesmo citando caminhos de um projeto. A de
// dentro sai. O consumo das duas continua contado.
mkdirSync(join(home, 'projetos/gestor'), { recursive: true });
const conversa = (cwd, sessionId, texto) => [
    JSON.stringify({ type: 'user', cwd, sessionId, entrypoint: 'claude-vscode', timestamp: hoje, message: { content: texto } }),
    ...Array.from({ length: 6 }, (_, i) => JSON.stringify({ type: 'assistant', timestamp: hoje, requestId: `r-${sessionId}-${i}`,
        message: { id: `${sessionId}-${i}`, model: 'claude-teste', content: `lendo ${join(home, 'projetos/gestor')}/x.php`, usage: { input_tokens: 1, output_tokens: 1 } } })),
].join('\n') + '\n';
const fora = join(home, '.claude/projects/-pessoal'); mkdirSync(fora, { recursive: true });
writeFileSync(join(fora, 'pessoal.jsonl'), conversa(join(home, 'pessoal'), 'sessao-pessoal-1', 'ASSUNTO PESSOAL que não pode sair'));
const dentro = join(home, '.claude/projects/-gestor'); mkdirSync(dentro, { recursive: true });
writeFileSync(join(dentro, 'gestor.jsonl'), conversa(join(home, 'projetos/gestor'), 'sessao-gestor-1', 'corrigir o relatório'));
const r = usoLocal(join(home, 'projetos'));
assert.deepEqual(r.sessoes.map(x => x.id), ['sessao-gestor-1'], 'só a conversa de dentro da pasta de projetos');
assert.ok(!JSON.stringify(r.sessoes).includes('PESSOAL'), 'o texto da conversa de fora não sai');
assert.equal(total(r), 375 + 12, 'o consumo das duas continua contado');

console.log('PASSOU: consumo do Claude deduplicado entre sessão original e cópia, também na leitura incremental; conversa fora da pasta de projetos não sai da máquina.');
