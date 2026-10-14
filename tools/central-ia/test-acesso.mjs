import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { acompanharAcesso, ESPERAS, ESTADO_INICIAL, ritmoDaConsulta } from '../../deploy/central-ia/politica.mjs';

// Repete o mesmo resultado n vezes e devolve o estado final e os avisos dados.
function repetir(estado, resultado, n) {
    const avisos = [];
    for (let i = 0; i < n; i++) {
        const r = acompanharAcesso(estado, resultado);
        estado = r.estado;
        if (r.aviso) avisos.push(r.aviso);
    }
    return { estado, avisos };
}

// Máquina desconectada na tela: UM aviso, e a espera cresce até o teto em vez
// de bater a cada 3 s para sempre (teste no staging, 14/10/2026).
let r = repetir(ESTADO_INICIAL, 401, 1000);
assert.equal(r.avisos.length, 1);
assert.match(r.avisos[0], /desconectada em Minhas máquinas/);
assert.match(r.avisos[0], /gere um comando novo/);
assert.deepEqual(r.estado, { situacao: 'recusado', espera: ESPERAS.recusado[1] });
assert.equal(acompanharAcesso(ESTADO_INICIAL, 401).estado.espera, ESPERAS.recusado[0]);
assert.equal(acompanharAcesso(ESTADO_INICIAL, 403).estado.situacao, 'recusado');

// O admin liberou de novo: volta ao ritmo normal, com uma linha só.
const volta = acompanharAcesso(r.estado, 200);
assert.deepEqual(volta.estado, ESTADO_INICIAL);
assert.match(volta.aviso, /de volta/);
assert.equal(acompanharAcesso(volta.estado, 200).aviso, null);

// Fora de alcance (Tailscale desligado): mesmo desenho, com teto menor.
r = repetir(ESTADO_INICIAL, 'rede', 500);
assert.equal(r.avisos.length, 1);
assert.match(r.avisos[0], /fora de alcance/);
assert.deepEqual(r.estado, { situacao: 'rede', espera: ESPERAS.rede[1] });

// Rede caiu e voltou com o token recusado: troca de situação avisa de novo, do começo.
const troca = acompanharAcesso(r.estado, 401);
assert.deepEqual(troca.estado, { situacao: 'recusado', espera: ESPERAS.recusado[0] });
assert.match(troca.aviso, /acesso recusado/);

// Erro passageiro do servidor não muda nada: nem estado, nem aviso.
for (const status of [500, 502, 429]) {
    assert.deepEqual(acompanharAcesso(ESTADO_INICIAL, status), { estado: ESTADO_INICIAL, aviso: null });
    const recusado = { situacao: 'recusado', espera: 60_000 };
    assert.deepEqual(acompanharAcesso(recusado, status), { estado: recusado, aviso: null });
}

// Máquina parada pergunta a cada 10 s; em conversa (até 5 min do último trabalho), a cada 3 s.
assert.equal(ritmoDaConsulta(0), 3_000);
assert.equal(ritmoDaConsulta(5 * 60_000 - 1), 3_000);
assert.equal(ritmoDaConsulta(5 * 60_000), 10_000);
assert.equal(ritmoDaConsulta(24 * 3600_000), 10_000);
// Por dia, parada: de ~29 mil consultas para ~8,6 mil.
assert.equal(Math.round(86_400_000 / ritmoDaConsulta(Infinity)), 8_640);

// O runner usa a regra no laço e na batida, e a espera longa não segura o "parar".
const runner = readFileSync(new URL('../../deploy/central-ia/runner.mjs', import.meta.url), 'utf8');
assert.match(runner, /notarAcesso\(r\.status\)/);
assert.match(runner, /notarAcesso\('rede'\)/);
assert.match(runner, /if \(acesso\.situacao === 'recusado'\) return;/);
assert.match(runner, /t < espera && !parar/);
assert.match(runner, /acesso\.situacao === 'ok' \? ritmoDaConsulta\(Date\.now\(\) - ultimoTrabalho\) : acesso\.espera/);
assert.doesNotMatch(runner, /setTimeout\(r, 3000\)/);

console.log('PASSOU: máquina desconectada ou fora de alcance avisa uma vez, espaça até o teto e volta ao normal quando o acesso volta.');
