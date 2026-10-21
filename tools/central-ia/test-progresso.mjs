import assert from 'node:assert/strict';
import { acompanharProgresso, descreverAcao } from '../../deploy/central-ia/progresso.mjs';
let agora = 0;
const p = acompanharProgresso(() => agora);
assert.deepEqual(p.retrato(), { sequencia: 1, etapa: 'preparando', atividade_em: null, processo_ativo: null, acao: null, acao_em: null, fala: null, acoes: 0 });
p.processo(true); p.etapa('agente');
p.saida('{"type":"item.started","item":{"type":"command_');
assert.equal(p.retrato().etapa, 'agente');
agora = 30000;
p.saida('execution","command":"php artisan test --filter=Central"}}\n');
const comando = p.retrato();
assert.equal(comando.etapa, 'comando');
assert.equal(comando.atividade_em, new Date(agora).toISOString());
assert.equal(comando.acao, 'rodando: php artisan test --filter=Central');
assert.equal(comando.acao_em, new Date(agora).toISOString());
assert.equal(comando.acoes, 1);
p.saida('invalido\n{"type":"item.completed","item":{"type":"command_execution","aggregated_output":"SAIDA-BRUTA"}}\n{"type":"item.completed","item":{"type":"agent_message","text":"Rodei os testes; 3 falharam."}}\n');
const resposta = p.retrato();
assert.equal(resposta.etapa, 'resposta');
assert.equal(resposta.fala, 'Rodei os testes; 3 falharam.');
assert.ok(!JSON.stringify(resposta).includes('SAIDA-BRUTA'), 'A saída das ferramentas nunca sai da máquina');
p.saida('{"type":"turn.completed"}\n'); p.processo(false);
const final = p.retrato();
assert.equal(final.etapa, 'finalizando'); assert.equal(final.processo_ativo, false);
agora = 120000;
assert.equal(p.retrato().atividade_em, final.atividade_em, 'Batida sem saída não simula atividade');

// Claude (stream-json): até 17/10/2026 o andamento ficava parado em "Aguardando resposta".
const c = acompanharProgresso(() => 60000);
c.saida(JSON.stringify({ type: 'assistant', message: { content: [{ type: 'text', text: 'Vou olhar o controller.' },
    { type: 'tool_use', name: 'Read', input: { file_path: 'app/Http/Controllers/PatrimonioController.php' } }] } }) + '\n');
let r = c.retrato();
assert.equal(r.etapa, 'comando'); assert.equal(r.acao, 'lendo app/Http/Controllers/PatrimonioController.php');
assert.equal(r.fala, 'Vou olhar o controller.');
c.saida(JSON.stringify({ type: 'user', message: { content: [{ type: 'tool_result', content: 'CONTEUDO-DO-ARQUIVO' }] } }) + '\n');
r = c.retrato();
assert.equal(r.etapa, 'agente'); assert.ok(!JSON.stringify(r).includes('CONTEUDO-DO-ARQUIVO'));
c.saida(JSON.stringify({ type: 'assistant', message: { content: [{ type: 'tool_use', name: 'Bash', input: { command: 'x'.repeat(500) } }] } }) + '\n');
r = c.retrato();
assert.ok(r.acao.length <= 160); assert.equal(r.acoes, 2);
c.saida('{"type":"result","result":"fim"}\n');
assert.equal(c.retrato().etapa, 'finalizando');
assert.equal(descreverAcao('Grep', { pattern: 'patrimonio', path: 'app' }), 'buscando "patrimonio" em app');
console.log('PASSOU: Claude mostra a última ação e a última fala, sem conteúdo de arquivo e com corte.');

console.log('PASSOU: eventos fragmentados, sequência, metadados sem conteúdo e última atividade real.');

const { centralIa } = await import('../../resources/js/central-ia.js');
const ui = centralIa({ podeEnviar: true });
ui.agora = Date.parse('2026-10-11T12:00:00Z'); ui.maquina.online = true;
const pedido = { agente: 'Codex', status: 'executando', criada_em: '2026-10-11T11:55:00Z',
    progresso_em: '2026-10-11T11:59:30Z', progresso: { etapa: 'comando' } };
assert.match(ui.andamento(pedido), /Executando uma ferramenta/);
ui.agora += 90000;
assert.match(ui.andamento(pedido), /Sem confirmação recente desta execução/);
assert.match(ui.andamento({ ...pedido, status: 'pendente' }), /Na fila/);
ui.maquina.online = false;
assert.match(ui.andamento({ ...pedido, status: 'pendente' }), /Máquina desconectada/);
const trabalhando = { ...pedido, progresso_em: new Date(ui.agora - 10000).toISOString(),
    progresso: { etapa: 'comando', acao: 'lendo app/Models/Bem.php', acao_em: new Date(ui.agora - 20000).toISOString(), acoes: 7, fala: 'Achei o filtro.' } };
assert.equal(ui.detalheDoAndamento(trabalhando), 'Agora: lendo app/Models/Bem.php (há 20 s) · 7 ações — “Achei o filtro.”');
assert.equal(ui.detalheDoAndamento({ ...trabalhando, status: 'pendente' }), '');
assert.equal(ui.detalheDoAndamento(pedido), '');
console.log('PASSOU: indicador web, confirmação por execução e desconexão com relógio controlado.');
