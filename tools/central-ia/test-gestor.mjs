import assert from 'node:assert/strict';
import { hora, lerGestor, lerSaida } from '../../deploy/central-ia/gestor.mjs';

// Retrato do Gestor pela ponte (Secretário, Etapa 5): a saída é lida pela
// linha marcada, o formato é conferido, e a ponte NUNCA recebe --apply.
const ok = 'Conectando...\nSECRETARIO_GESTOR:{"orcamentos_aprovacao":{"quantidade":4,"valor":38200,"itens":[{"texto":"Orç. 191","data":"2026-10-10","valor":12000}]},"funil_parado":{"quantidade":0,"itens":[]}}\n';
assert.deepEqual(lerSaida(ok).orcamentos_aprovacao, { quantidade: 4, valor: 38200, itens: [{ texto: 'Orç. 191', data: '2026-10-10', valor: 12000 }] });
assert.equal(lerSaida('sem marca'), null);
assert.equal(lerSaida('SECRETARIO_GESTOR:{"apagar":{"quantidade":1}}'), null, 'bloco desconhecido');
assert.equal(lerSaida('SECRETARIO_GESTOR:{"funil_parado":{"quantidade":"x"}}'), null);

const chamadas = [];
const executar = async (p, args, opts) => { chamadas.push({ p, args, opts }); return { stdout: ok }; };
const r = await lerGestor('/mnt/c/Python314/python.exe|C:\\tmp\\run_prod.py|C:\\tmp\\secretario_gestor.php', { executar });
assert.equal(r.ok, true);
assert.deepEqual(chamadas[0].args, ['C:\\tmp\\run_prod.py', 'C:\\tmp\\secretario_gestor.php']);
assert.ok(!chamadas.some(c => c.args.includes('--apply')), 'Nunca --apply');
assert.equal(chamadas[0].opts.cwd, undefined, 'Sem pasta configurada, sem cwd');
await lerGestor('py|ponte.py|script.php|/pasta/da/ponte', { executar });
assert.equal(chamadas[1].opts.cwd, '/pasta/da/ponte', 'A pasta vem da configuração da máquina');
assert.deepEqual(await lerGestor('incompleto'), { ok: false, erro: 'CENTRAL_IA_GESTOR incompleto' });
assert.equal((await lerGestor('a|b|c', { executar: async () => { throw Object.assign(new Error('x'), { killed: true }); } })).erro, 'a ponte passou do tempo');

assert.equal(hora(null), true);
assert.equal(hora(new Date('2026-10-17T05:00:00'), new Date('2026-10-17T05:30:00')), false);
assert.equal(hora(new Date('2026-10-17T05:00:00'), new Date('2026-10-17T06:05:00')), true);
assert.equal(hora(new Date('2026-10-17T06:10:00'), new Date('2026-10-17T06:40:00')), true, 'janela antes do briefing');
console.log('PASSOU: retrato lido pela marca, formato conferido, ponte sem --apply, pasta da configuração e ritmo de leitura.');
