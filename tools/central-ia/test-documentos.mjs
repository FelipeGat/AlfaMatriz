// Pastas de documentos espelhadas (docs/specs/documentos-espelhados): lista,
// filtra e envia em partes. Sem rede: a API é falsa.
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { configuracao, espelhar, listar } from '../../deploy/central-ia/documentos.mjs';

assert.deepEqual(configuracao('Orçamentos VixCard|/mnt/c/x/Orçamentos; ruim/nome|/y ; Outra|/z'),
    [{ nome: 'Orçamentos VixCard', caminho: '/mnt/c/x/Orçamentos' }, { nome: 'Outra', caminho: '/z' }]);

const raiz = mkdtempSync(join(tmpdir(), 'docs-'));
mkdirSync(join(raiz, 'Lorenzo'));
writeFileSync(join(raiz, 'Lorenzo', 'proposta.pdf'), 'a'.repeat(20));
writeFileSync(join(raiz, 'Lorenzo', '~$rascunho.docx'), 'x');
writeFileSync(join(raiz, 'virus.exe'), 'x');
writeFileSync(join(raiz, '.oculto'), 'x');
writeFileSync(join(raiz, 'grande.mp4'), 'b'.repeat(25));
symlinkSync('/etc/passwd', join(raiz, 'link.txt'));
const lista = listar(raiz).map(a => a.caminho).sort();
assert.deepEqual(lista, ['Lorenzo/proposta.pdf', 'grande.mp4'], 'sem temporário, executável, oculto nem link');

const chamadas = [];
const api = async (c, corpo) => { chamadas.push([c, corpo]); return { status: 200, json: { pasta_id: 7, enviar: ['grande.mp4', 'sumiu.pdf'], removidos: 1, parte_bytes: 10 } }; };
const partes = [];
const apiArquivo = async (c, campos, buf) => { partes.push({ c, ...campos, bytes: buf.length }); return 200; };
const r = await espelhar({ nome: 'Orçamentos', caminho: raiz }, 'wsl', { api, apiArquivo });
assert.equal(chamadas[0][0], 'documentos/inventario');
assert.equal(chamadas[0][1].pasta, 'Orçamentos');
assert.equal(r.enviados, 1, 'só o que existe na máquina');
assert.deepEqual(partes.map(p => [p.c, p.parte, p.partes, p.bytes]), [['documentos/7/partes', '0', '3', 10], ['documentos/7/partes', '1', '3', 10], ['documentos/7/partes', '2', '3', 5]]);
assert.match(partes[0].sha256, /^[a-f0-9]{64}$/);
assert.equal(r.removidos, 1);
console.log('PASSOU: documentos — lista filtrada, inventário e envio em partes.');
