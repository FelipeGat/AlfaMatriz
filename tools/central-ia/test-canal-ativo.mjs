import assert from 'node:assert/strict';
import { centralIa } from '../../resources/js/central-ia.js';
globalThis.localStorage = { getItem: () => null };
const u = centralIa({ canal: '/canal', podeEnviar: true });
u.garantirAba(); u.reconectar = () => {}; u.aba.rascunho = 'Não enviar ao transferir';
let chamadas = 0;
u.pedir = async (url, metodo, dados) => { chamadas++; assert.equal(url, '/canal'); return { ativo: dados.canal, projeto: null, tarefa_id: null }; };
await u.transferirCanal('telegram');
assert.equal(u.atendimento.ativo, 'telegram'); assert.equal(u.aba.rascunho, 'Não enviar ao transferir');
await u.enviar(); assert.equal(chamadas, 1, 'Canal web inativo não envia nem chama agente');
// A transferência falha sem mudar canal ou apagar o rascunho.
u.pedir = async () => { throw { detalhe: 'Sem permissão' }; };
await u.transferirCanal('web'); assert.equal(u.atendimento.ativo, 'telegram'); assert.equal(u.aviso, 'Sem permissão');
assert.equal(u.transferindoCanal, false); assert.equal(u.aba.rascunho, 'Não enviar ao transferir');
u.aba.pendente = { client_id: 'teste' }; await u.transferirCanal('web'); assert.equal(u.atendimento.ativo, 'telegram');
assert.equal(u.canal('web'), 'AlfaMatriz', 'Rótulo da origem não é sobrescrito pelo estado do atendimento');
console.log('PASSOU: transferência preserva rascunho, bloqueia envio inativo, trata falha e protege envio pendente.');
