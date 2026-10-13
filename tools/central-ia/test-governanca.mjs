import assert from 'node:assert/strict';
import { centralIa } from '../../resources/js/central-ia.js';
globalThis.localStorage = { getItem: () => null, setItem() {} };
globalThis.document = { hidden: false };
globalThis.location = { search: '', pathname: '/central-ia' };
globalThis.history = { replaceState() {} };
const u = centralIa({ administracao: '/admin', politicaUsuario: '/usuarios/__U__/politica' });
u.$refs = {}; u.$nextTick = f => f(); u.garantirAba();
u.administracao = { permitida: true, usuarios: [{ id: 2 }] }; u.usuarioAtividade = '2';
let resolver;
u.pedir = () => new Promise(r => { resolver = r; });
const consulta = u.carregarUsuario(); u.usuarioAtividade = '3';
resolver({ politica: { revisao: 1 }, usuario: { id: 2 } }); await consulta;
assert.equal(u.resumoUsuario, null, 'resposta atrasada não troca o usuário');
u.politicaUsuario = { revisao: 0, habilitados: {}, pedidos_dia: {}, limite_total: 5, paralelo: 1, deploy: true, onedrive: true, execucao_bloqueada: false };
u.pedir = async (url, metodo, dados) => {
    assert.equal(url, '/usuarios/3/politica');
    // Liberar a execução é do dono da Central (12/10/2026); deploy e OneDrive nunca saem da tela.
    assert.deepEqual(Object.keys(dados).sort(), ['revisao', 'habilitados', 'pedidos_dia', 'limite_total', 'paralelo', 'execucao_bloqueada'].sort());
    assert.equal(dados.execucao_bloqueada, false);
    return { politica: { revisao: 1 } };
};
await u.salvarPolitica(); assert.equal(u.politicaUsuario.revisao, 1);
u.falhou({ status: 403, detalhe: 'Permissão revogada' });
assert.deepEqual(u.administracao, { permitida: false, usuarios: [] });
assert.equal(u.resumoUsuario, null); assert.equal(u.politicaUsuario, null);
console.log('PASSOU: seleção concorrente, payload restrito e limpeza após revogação.');
