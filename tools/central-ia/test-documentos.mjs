// Pastas de documentos espelhadas (docs/specs/documentos-espelhados): lista,
// filtra e envia em partes. Sem rede: a API é falsa.
import assert from 'node:assert/strict';
import { existsSync, mkdtempSync, mkdirSync, readFileSync, symlinkSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { aplicarAlteracoes, configuracao, dentroDaRaiz, espelhar, gerarMiniatura, listar } from '../../deploy/central-ia/documentos.mjs';

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
assert.deepEqual(chamadas[0][1].pastas, ['Lorenzo'], 'o inventário leva as subpastas');

// Mão dupla (documentos-mao-dupla): a máquina aplica o que o Matriz pediu.
const fora = mkdtempSync(join(tmpdir(), 'fora-'));
symlinkSync(fora, join(raiz, 'atalho'));
assert.equal(dentroDaRaiz(raiz, '../x.pdf'), null);
assert.equal(dentroDaRaiz(raiz, '/etc/passwd'), null);
assert.equal(dentroDaRaiz(raiz, 'atalho/x.pdf'), null, 'não sai da raiz por link');
assert.equal(dentroDaRaiz(raiz, 'Lorenzo/script.sh'), null);
assert.ok(dentroDaRaiz(raiz, 'Lorenzo/nova.pdf'));

const conteudo = Buffer.from('proposta nova');
const sha = createHash('sha256').update(conteudo).digest('hex');
const ops = [
    { id: 1, pasta: 'Orçamentos', tipo: 'criar_pasta', caminho: 'Padaria Monte Líbano' },
    { id: 2, pasta: 'Orçamentos', tipo: 'enviar', caminho: 'Lorenzo/proposta.pdf', tamanho: conteudo.length, sha256: sha },
    { id: 3, pasta: 'Orçamentos', tipo: 'mover', caminho: 'grande.mp4', destino: 'Padaria Monte Líbano/video.mp4' },
    { id: 4, pasta: 'Orçamentos', tipo: 'mover', caminho: 'Lorenzo', destino: 'Padaria Monte Líbano' },
    { id: 5, pasta: 'Orçamentos', tipo: 'apagar', caminho: 'virus.exe' },
    { id: 6, pasta: 'Orçamentos', tipo: 'apagar', caminho: '../fora' },
    { id: 7, pasta: 'Outra pasta', tipo: 'apagar', caminho: 'x.pdf' },
];
const respostas = {};
const apiOps = async (c, corpo) => {
    if (c === 'documentos/operacoes') return { status: 200, json: { operacoes: ops } };
    respostas[c.split('/').pop()] = corpo;
    return { status: 200, json: { ok: true } };
};
const n = await aplicarAlteracoes([{ nome: 'Orçamentos', caminho: raiz }], 'wsl', { api: apiOps, baixar: async () => conteudo });
assert.equal(n, 6, 'a da pasta que não é desta máquina fica de fora');
assert.equal(respostas[1].situacao, 'feita');
assert.ok(existsSync(join(raiz, 'Padaria Monte Líbano')));
assert.equal(respostas[2].caminho_final, 'Lorenzo/proposta (2).pdf', 'não sobrescreve: ganha sufixo');
assert.equal(readFileSync(join(raiz, 'Lorenzo', 'proposta (2).pdf'), 'utf8'), 'proposta nova');
assert.equal(readFileSync(join(raiz, 'Lorenzo', 'proposta.pdf'), 'utf8'), 'a'.repeat(20), 'o original fica');
assert.match(respostas[2].modificado, /^\d{4}-\d{2}-\d{2}T/);
assert.equal(respostas[3].situacao, 'feita');
assert.ok(existsSync(join(raiz, 'Padaria Monte Líbano', 'video.mp4')));
assert.deepEqual([respostas[4].situacao, respostas[4].erro], ['falhou', 'o destino já existe']);
assert.deepEqual([respostas[5].situacao, respostas[6].situacao], ['falhou', 'falhou'], 'executável e fora da raiz recusados');
assert.ok(existsSync(join(raiz, 'virus.exe')));
const errado = await aplicarAlteracoes([{ nome: 'Orçamentos', caminho: raiz }], 'wsl', { api: async (c, corpo) => c === 'documentos/operacoes'
    ? { status: 200, json: { operacoes: [{ id: 9, pasta: 'Orçamentos', tipo: 'enviar', caminho: 'x.pdf', tamanho: 3, sha256: 'a'.repeat(64) }] } }
    : (respostas[9] = corpo, { status: 200 }), baixar: async () => Buffer.from('abc') });
assert.equal(errado, 1);
assert.deepEqual([respostas[9].situacao, respostas[9].erro], ['falhou', 'o arquivo não confere (sha256)']);
assert.ok(!existsSync(join(raiz, 'x.pdf')));
// Miniaturas: o Matriz pede, a máquina gera (aqui um conversor falso) e envia; o que falha vai sem arquivo.
const comandos = [];
const converter = async (dir, comando, args) => {
    comandos.push([comando, args.join(' ')]);
    if (comando === 'pdftoppm') writeFileSync(join(dir, 'mini.jpg'), 'JPEG-pdf');
    else throw new Error('vídeo quebrado');
};
assert.equal(String(await gerarMiniatura(join(raiz, 'Lorenzo', 'proposta.pdf'), { converter })), 'JPEG-pdf');
assert.match(comandos[0][1], /-singlefile -scale-to 480 -jpeg \/media\/original\.pdf/);
writeFileSync(join(raiz, 'demo.mp4'), 'mp4!');
const enviadas = [];
const semArquivo = [];
const rm = await espelhar({ nome: 'Orçamentos', caminho: raiz }, 'wsl', {
    api: async (c, corpo) => c === 'documentos/inventario'
        ? { status: 200, json: { pasta_id: 7, enviar: [], miniaturas: ['Lorenzo/proposta.pdf', 'demo.mp4', 'nao-existe.pdf'] } }
        : (semArquivo.push([c, corpo.caminho]), { status: 200, json: { ok: false } }),
    apiArquivo: async (c, campos, buf) => { enviadas.push([c, campos.caminho, String(buf)]); return 200; },
    converter, midiaInstalada: async () => true,
});
assert.deepEqual(enviadas, [['documentos/7/miniaturas', 'Lorenzo/proposta.pdf', 'JPEG-pdf']]);
assert.deepEqual(semArquivo, [['documentos/7/miniaturas', 'demo.mp4']], 'vídeo que não converte avisa sem arquivo; o que não existe é ignorado');
assert.equal(rm.miniaturas, 1);
const semMidia = await espelhar({ nome: 'Orçamentos', caminho: raiz }, 'wsl', {
    api: async () => ({ status: 200, json: { pasta_id: 7, enviar: [], miniaturas: ['Lorenzo/proposta.pdf'] } }),
    apiArquivo: async () => { throw new Error('não devia enviar'); }, converter, midiaInstalada: async () => false,
});
assert.equal(semMidia.miniaturas, 0, 'sem a imagem de mídia, fica para depois (nada marcado como sem)');
console.log('PASSOU: documentos — lista filtrada, inventário com pastas, envio em partes, alterações aplicadas com segurança e miniaturas.');
