import assert from 'node:assert/strict';
import { centralIa } from '../../resources/js/central-ia.js';
globalThis.localStorage = { getItem: () => null, setItem() {} };
globalThis.document = { hidden: false };
globalThis.location = { search: '', pathname: '/central-ia' };
globalThis.history = { replaceState() {} };
const u = centralIa({ podeEnviar: true, painel: '/painel', historico: '/historico', tarefas: '/tarefas/__P__' });
u.$refs = {}; u.$nextTick = f => f(); u.reconectar = () => {}; u.garantirAba();
u.chats = [
    { id: 1, projeto: 'alfamatriz', titulo: 'Corrigir agenda', status: 'aberta', agente: 'Codex' },
    { id: 2, projeto: 'alfamatriz', titulo: 'Revisar menu', status: 'falhou', agente: 'Claude' },
    { id: 3, projeto: 'gestor', titulo: 'Relatório', status: 'pausada', agente: 'Codex' },
];
assert.equal(u.chatsFiltrados.length, 2);
u.filtroChatEstado = 'todos'; assert.equal(u.chatsFiltrados.length, 3);
u.filtroChatProjeto = 'alfamatriz'; assert.equal(u.chatsFiltrados.length, 2);
u.buscaChats = 'agenda'; assert.equal(u.chatsFiltrados[0].id, 1);
u.buscaChats = ''; u.filtroChatEstado = 'falhou'; assert.equal(u.chatsFiltrados[0].id, 2);
u.abrirChat(u.chats[0]); u.aba.rascunho = 'Rascunho A'; u.aba.mensagens = [{ id: 10, tarefa_id: 1 }];
u.abrirChat(u.chats[1]); assert.equal(u.aba.rascunho, ''); assert.equal(u.mensagens.length, 0);
u.aba.rascunho = 'Rascunho B'; u.abrirChat(u.chats[0]);
assert.equal(u.aba.rascunho, 'Rascunho A'); assert.equal(u.aba.tarefa, 1); assert.equal(u.tela, 'chats');
// Mesmo projeto, troca de chat durante resposta pendente do histórico.
let entregar;
const espera = new Promise(r => { entregar = r; });
u.pedir = async url => url === '/painel' ? { projetos: [], chats: u.chats, atividade: [], maquina: {}, telegram: {} } : espera;
const ciclo = u.atualizar(); await new Promise(r => setTimeout(r, 0));
u.abrirChat(u.chats[1]); entregar({ mensagens: [{ id: 20, tarefa_id: 1 }], recentes: [], ha_mais: false });
await ciclo; clearTimeout(u.timer);
assert.equal(u.aba.rascunho, 'Rascunho B'); assert.equal(u.aba.mensagens.length, 0);
// Envio tardio de tarefa nova não pode trocar o chat que o usuário abriu.
u.abrir('alfamatriz'); u.aba.rascunho = 'Novo assunto';
let confirmar, pedido;
u.atualizar = async () => {};
u.pedir = async (_url, _metodo, dados) => { pedido = dados; return new Promise(r => { confirmar = r; }); };
const envio = u.enviar();
u.abrirChat(u.chats[1]); confirmar({ tarefa_id: 99 }); await envio;
assert.equal(u.chatAtual, 2); assert.equal(u.aba.rascunho, 'Rascunho B');
assert.equal(pedido.tarefa_id, null);
// Mesmo se o seletor sofrer alteração, a mensagem pertence ao chat aberto.
u.aba.tarefa = 'nova';
u.pedir = async (_url, _metodo, dados) => { pedido = dados; return { tarefa_id: 2 }; };
await u.enviar(); assert.equal(pedido.tarefa_id, 2); assert.equal(u.chatAtual, 2);
// O caso de 20/10/2026: um rodando, dois na fila (um do mesmo chat, outro de um assunto parado).
{
    const v = centralIa({ podeEnviar: true, painel: '/painel', historico: '/historico', tarefas: '/tarefas/__P__' });
    v.paralelo = 2;
    v.chats = [{ id: 30, projeto: '', titulo: 'Sistema de Produção', status: 'executando', agente: 'Claude' }];
    v.assuntos = [{ id: 11, projeto: '', titulo: 'Agenda do Alefe', status: 'aberta', agente: 'Claude' },
        { id: 12, projeto: '', titulo: 'Parado', status: 'aberta', agente: null }];
    v.atividade = [{ id: 186, tarefa_id: 30, projeto: '', modo: 'implementar', status: 'executando' },
        { id: 189, tarefa_id: 30, projeto: '', modo: 'analisar', status: 'pendente' },
        { id: 190, tarefa_id: 11, projeto: '', modo: 'implementar', status: 'pendente' }];
    assert.deepEqual(v.chatsFiltrados.map(c => c.id), [30, 11], 'o assunto na fila aparece; o parado não');
    assert.equal(v.estadoNaLista(v.chats[0]), 'executando');
    assert.equal(v.filaDoChat(30).naFila, 1);
    assert.equal(v.estadoNaLista(v.assuntos[0]), 'na_fila');
    assert.match(v.filaDoChat(11).motivo, /espera a máquina pegar/, 'Geral não é projeto: não espera o outro chat');
    v.atividade[0].projeto = 'gestor'; v.atividade[2].projeto = 'gestor';
    assert.match(v.filaDoChat(11).motivo, /outro chat que implementa em gestor/);
    v.atividade[0].projeto = ''; v.atividade[2].projeto = '';
    v.filtroChatEstado = 'na_fila'; assert.deepEqual(v.chatsFiltrados.map(c => c.id), [11]);
}
console.log('PASSOU: filtros, rascunhos, retomada, isolamento no poll e destino de envio durante troca de chats.');
