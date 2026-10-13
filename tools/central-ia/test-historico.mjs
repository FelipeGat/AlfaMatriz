import assert from 'node:assert/strict';
import { centralIa } from '../../resources/js/central-ia.js';
globalThis.location = { search: '', pathname: '/central-ia' };
globalThis.history = { replaceState() {} };
globalThis.document = { hidden: false };
globalThis.localStorage = { setItem() {}, getItem() { return null; } };
const cfg = { painel: '/painel', historico: '/mensagens', tarefas: '/tarefas/__P__' };
function criar() {
    const u = centralIa(cfg); u.$refs = {}; u.$nextTick = fn => fn(); u.garantirAba(); return u;
}
const u = criar();
u.aba.rascunho = 'Texto ainda não enviado';
u.telegram = { vinculado: true, projeto: 'alfamatriz', tarefa_id: 9 };
u.reconectar = () => {}; u.abrir('alfamatriz'); u.verTarefa = 8; u.aba.verTarefa = 8;
u.sub = 'contexto'; u.abrirTelegram();
assert.equal(u.atual, 'alfamatriz'); assert.equal(u.verTarefa, null); assert.equal(u.sub, 'conversa');
assert.equal(u.aba.tarefa, 9); assert.equal(u.abas[''].rascunho, 'Texto ainda não enviado');
u.aba.mensagens = [{ id: 1, tarefa_id: 9 }, { id: 2, tarefa_id: 9 }];
u.tarefas = [{ id: 9, ponto: { mensagem_id: 2 } }];
assert.equal(u.mensagensVisiveis.length, 2, 'Atalho reabre histórico salvo da conversa');
u.aba.rascunho = 'Pedido em edição'; u.aba.tarefa = 8; u.abrirTelegram();
assert.equal(u.aba.rascunho, 'Pedido em edição'); assert.equal(u.aba.tarefa, 8, 'Não muda destino de um rascunho existente');

// Uma resposta de poll já iniciada em outro projeto não pode preencher a tela nova.
const race = criar(); race.reconectar = () => {}; race.abrir('gestor');
let entregar;
const espera = new Promise(r => { entregar = r; });
const painel = { projetos: [], atividade: [], maquina: {}, paralelo: 2, telegram: { vinculado: false }, tarefas: [] };
race.pedir = async url => url === cfg.painel ? painel : url.startsWith('/mensagens') ? espera : { tarefas: [] };
const ciclo = race.atualizar();
await new Promise(r => setTimeout(r, 0));
race.abrir('alfamatriz');
entregar({ mensagens: [{ id: 500, projeto: 'gestor' }], recentes: [], ha_mais: false });
await ciclo; clearTimeout(race.timer);
assert.equal(race.aba.mensagens.length, 0, 'Não mistura respostas do projeto anterior');

// Primeira visita acompanha Telegram uma vez; escolha explícita não é substituída.
const inicio = criar(); inicio.reconectar = () => {}; inicio.escolherConversaInicial = true;
inicio.pedir = async url => url === cfg.painel ? { ...painel, telegram: u.telegram } : url.startsWith('/mensagens') ? { mensagens: [{ id: 3, tarefa_id: 9 }], ha_mais: false } : { tarefas: [] };
await inicio.atualizar(); clearTimeout(inicio.timer);
assert.equal(inicio.atual, 'alfamatriz'); assert.equal(inicio.aba.mensagens[0].id, 3);
inicio.abrir('gestor'); await inicio.atualizar(); clearTimeout(inicio.timer);
assert.equal(inicio.atual, 'gestor');
console.log('PASSOU: atalho Telegram, histórico salvo, rascunhos, primeira visita e troca de projeto durante poll.');
