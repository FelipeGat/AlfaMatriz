import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { prepararAnexos, IMAGEM_OFFICE } from '../../deploy/central-ia/anexos.mjs';

// Texto e documentos de escritório (17/10/2026). Os .docx e .xlsx nascem no
// próprio LibreOffice da imagem, a partir de texto sintético.
const tmp = mkdtempSync(join(tmpdir(), 'central-ia-docs-test-'));
writeFileSync(join(tmp, 'relatorio.txt'), 'RELATORIO DOCX TESTE\nsegunda linha\n');
writeFileSync(join(tmp, 'planilha.csv'), 'item,valor\nPLANILHA XLSX TESTE,42\n');
const gerar = (origem, formato) => execFileSync('docker', ['run', '--rm', '--network=none', '--user', `${process.getuid()}:${process.getgid()}`,
    '-e', 'HOME=/tmp', '--mount', `type=bind,src=${tmp},dst=/media`, IMAGEM_OFFICE, 'soffice', '--headless',
    '-env:UserInstallation=file:///tmp/perfil', '--convert-to', formato, '--outdir', '/media', `/media/${origem}`], { stdio: 'ignore' });
gerar('relatorio.txt', 'docx'); gerar('planilha.csv', 'xlsx');

const arquivos = [
    ['notas.md', 'text/markdown', Buffer.from('# Notas\nTEXTO MARKDOWN TESTE\n')],
    ['dados.csv', 'text/csv', Buffer.from('a;b\nCSV DIRETO TESTE;1\n')],
    ['relatorio.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', readFileSync(join(tmp, 'relatorio.docx'))],
    ['planilha.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', readFileSync(join(tmp, 'planilha.xlsx'))],
];
const ids = arquivos.map((_, i) => `${i + 1}`.repeat(8) + '-1111-4111-8111-111111111111');
const server = createServer((req, res) => {
    const i = ids.indexOf(req.url.split('/').at(-1));
    if (i < 0) { res.writeHead(404).end(); return; }
    res.end(arquivos[i][2]);
});
await new Promise(r => server.listen(0, '127.0.0.1', r));
const cfg = { url: `http://127.0.0.1:${server.address().port}`, token: 'token-sintetico' };
try {
    const anexos = arquivos.map(([nome, mime, bytes], i) => ({ id: ids[i], nome, mime, sha256: createHash('sha256').update(bytes).digest('hex') }));
    const r = await prepararAnexos(anexos, cfg, tmp);
    for (const esperado of [/TEXTO MARKDOWN TESTE/, /CSV DIRETO TESTE/, /RELATORIO DOCX TESTE/, /PLANILHA XLSX TESTE/, /Documento convertido para PDF/]) {
        assert.match(r.contexto, esperado);
    }
    assert.equal(r.imagens.length, 2, 'Uma página por documento convertido; texto puro não vira imagem');
    console.log('PASSOU: markdown e csv lidos direto; docx e xlsx convertidos pelo LibreOffice, com texto e página.');
} finally {
    server.close(); rmSync(tmp, { recursive: true, force: true });
}
