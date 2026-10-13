// Níveis de custo no runner (FR-027): modelo/esforço por CLI e agenda sem IA.
import assert from 'node:assert/strict';
import { agendaSemIa, modeloArgs } from '../../deploy/central-ia/politica.mjs';

assert.deepEqual(modeloArgs('claude', 'sonnet', 'low'), ['--model', 'sonnet', '--effort', 'low']);
assert.deepEqual(modeloArgs('codex', 'gpt-5.6-luna', 'low'), ['-m', 'gpt-5.6-luna', '-c', 'model_reasoning_effort="low"']);
assert.deepEqual(modeloArgs('copilot', 'auto', 'medium'), ['--model', 'auto'], 'auto não aceita esforço');
assert.deepEqual(modeloArgs('copilot', 'gpt-6-luna', 'low'), ['--model', 'gpt-6-luna', '--reasoning-effort', 'low']);
assert.deepEqual(modeloArgs('claude', null, null), [], 'forte = padrão da CLI');
assert.deepEqual(modeloArgs('codex', 'x; rm -rf ~', 'ultra'), [], 'nome estranho não vira argumento');

const lista = agendaSemIa('hoje', '2026-10-12', '2026-10-12 09:00 [Trabalho] Reunião A\n2026-10-12 14:00 [Pessoal] Dentista\n2026-10-13 08:00 [Trabalho] Visita');
assert.match(lista, /📅 Agenda — hoje \(a partir de 12\/10\)/);
assert.match(lista, /\*\*12\/10\*\*\n- 09:00 \[Trabalho\] Reunião A\n- 14:00 \[Pessoal\] Dentista/);
assert.match(lista, /\*\*13\/10\*\*\n- 08:00/);
assert.match(lista, /3 compromisso\(s\).*sem IA/);
assert.match(agendaSemIa('amanhã', '2026-10-13', ''), /Nenhum compromisso no período/);
console.log('PASSOU: modelo e esforço por CLI, nome de modelo saneado, agenda sem IA agrupada por dia.');
