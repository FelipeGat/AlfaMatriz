import assert from 'node:assert/strict';
import { hora, lerAgenda, lerSaida } from '../../deploy/central-ia/agenda-google.mjs';

// Agenda do Google pela máquina: lida pela marca, formato conferido, link só https.
const ok = 'aviso qualquer\nSECRETARIO_AGENDA:' + JSON.stringify({
    agendas: [{ id: 'vix@group', nome: 'VixCard', primaria: false }, { id: 'eu@gmail.com', nome: 'Felipe', primaria: true }],
    eventos: [
        { agenda: 'vix@group', id: 'e1', titulo: 'Visita RFID', inicio: '2026-10-20T09:00:00-03:00', fim: '2026-10-20T10:00:00-03:00', dia_inteiro: false, link: 'https://www.google.com/calendar/event?eid=e1' },
        { agenda: 'eu@gmail.com', id: 'e2', titulo: 'Feriado', inicio: '2026-11-02', fim: '2026-11-03', dia_inteiro: true, link: 'javascript:alert(1)' },
        { agenda: 'outra', id: 'e3', titulo: 'De agenda que não veio', inicio: '2026-10-20', fim: '2026-10-21', dia_inteiro: true },
        { agenda: 'vix@group', id: 'e4', titulo: 'Data quebrada', inicio: 'amanhã', fim: 'depois', dia_inteiro: false },
    ],
}) + '\n';
const d = lerSaida(ok);
assert.equal(d.agendas.length, 2);
assert.deepEqual(d.eventos.map(e => e.id), ['e1', 'e2']);
assert.equal(d.eventos[1].link, null, 'Só https');
assert.equal(lerSaida('sem marca'), null);
assert.equal(lerSaida('SECRETARIO_AGENDA:{"agendas":"x"}'), null);

const chamadas = [];
const r = await lerAgenda('py|C:\\pasta\\exportador.py|/pasta', { executar: async (p, args, o) => { chamadas.push({ p, args, o }); return { stdout: ok }; } });
assert.equal(r.ok, true);
assert.deepEqual(chamadas[0].args, ['C:\\pasta\\exportador.py']);
assert.equal(chamadas[0].o.cwd, '/pasta');
assert.deepEqual(await lerAgenda(''), { ok: false, erro: 'CENTRAL_IA_GOOGLE incompleto' });
assert.equal((await lerAgenda('a|b', { executar: async () => { throw Object.assign(new Error('x'), { killed: true }); } })).erro, 'o exportador passou do tempo');
assert.equal(hora(null), true);
assert.equal(hora(new Date('2026-10-17T10:00:00'), new Date('2026-10-17T10:05:00')), false);
assert.equal(hora(new Date('2026-10-17T10:00:00'), new Date('2026-10-17T10:10:00')), true);
console.log('PASSOU: agenda do Google pela máquina — marca, formato, link https, cwd da configuração e ritmo.');
