import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { execFileSync, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { createHash } from 'node:crypto';
import { prepararAnexos } from '../../deploy/central-ia/anexos.mjs';

// Teste real dos conversores com mídias sintéticas; nenhuma IA nem serviço do projeto.
const tmp = mkdtempSync(join(tmpdir(), 'central-ia-media-test-'));
const objetos = [null,
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 300] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
const conteudo = 'BT /F1 14 Tf 20 250 Td (CENTRAL PDF TESTE) Tj ET';
objetos.push(`<< /Length ${conteudo.length} >>\nstream\n${conteudo}\nendstream`);
let pdf = '%PDF-1.4\n', offsets = [0];
for (let i = 1; i < objetos.length; i++) { offsets.push(pdf.length); pdf += `${i} 0 obj\n${objetos[i]}\nendobj\n`; }
const xref = pdf.length;
pdf += `xref\n0 ${objetos.length}\n0000000000 65535 f \n`;
for (const o of offsets.slice(1)) pdf += String(o).padStart(10, '0') + ' 00000 n \n';
pdf += `trailer\n<< /Size ${objetos.length} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
writeFileSync(join(tmp, 'teste.pdf'), pdf);
execFileSync('docker', ['run', '--rm', '--network=none', '--user', `${process.getuid()}:${process.getgid()}`,
    '--mount', `type=bind,src=${tmp},dst=/media`, 'alfamatriz-central-media:local', 'ffmpeg',
    '-v', 'error', '-f', 'lavfi', '-i', 'color=c=blue:s=64x64:r=2', '-t', '3', '-pix_fmt', 'yuv420p', '/media/teste.mp4']);
const bytes = [readFileSync(join(tmp, 'teste.pdf')), readFileSync(join(tmp, 'teste.mp4'))];
const ids = ['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'];
const server = createServer((req, res) => {
    assert.equal(req.headers.authorization, 'Bearer token-sintetico');
    const i = ids.indexOf(req.url.split('/').at(-1));
    if (i < 0) { res.writeHead(404).end(); return; }
    res.end(bytes[i]);
});
await new Promise(r => server.listen(0, '127.0.0.1', r));
const cfg = { url: `http://127.0.0.1:${server.address().port}`, token: 'token-sintetico' };
try {
    const anexos = ids.map((id, i) => ({ id, nome: i ? 'video.mp4' : 'documento.pdf', mime: i ? 'video/mp4' : 'application/pdf',
        sha256: createHash('sha256').update(bytes[i]).digest('hex'), url: 'https://nao-usar.invalid' }));
    const resultado = await prepararAnexos(anexos, cfg, tmp);
    assert.match(resultado.contexto, /CENTRAL PDF TESTE/);
    assert.match(resultado.contexto, /Áudio não transcrito/);
    assert.equal(resultado.imagens.length, 4, 'Uma página e três quadros');
    for (const arquivo of resultado.imagens) assert.ok(readFileSync(arquivo).length > 0);
    await assert.rejects(prepararAnexos([{ ...anexos[0], id: 'invalido' }], cfg, tmp), /Anexo inválido/);
    if (process.env.CENTRAL_IA_TEST_CODEX) {
        // Validação real do CLI com as mesmas imagens que o runner entregaria.
        // Só mídias sintéticas; não pede ferramentas, alterações ou outras IAs.
        const resposta = join(tmp, 'resposta-codex.txt');
        const leitura = promisify(execFile)(process.env.CENTRAL_IA_TEST_CODEX, ['exec', '--json', '--skip-git-repo-check',
            '-c', 'sandbox_mode="read-only"', '-c', 'approval_policy="never"',
            ...resultado.imagens.flatMap(p => ['--image', p]), '-o', resposta,
            'Teste de leitura visual. Sem usar ferramentas, responda em português apenas com o texto visível na primeira imagem (página de PDF) e a cor predominante nas demais imagens (quadros de vídeo).'],
        { cwd: tmp, timeout: 240000, maxBuffer: 2 * 1024 * 1024 });
        leitura.child.stdin.end(); // Igual ao runner: nenhum stdin adicional pendente.
        await leitura;
        const texto = readFileSync(resposta, 'utf8');
        assert.match(texto, /CENTRAL PDF TESTE/i);
        assert.match(texto, /azul/i);
        console.log('PASSOU: Codex leu efetivamente a página do PDF e os quadros azuis do vídeo.');
    }
    console.log('PASSOU: download autenticado, integridade, PDF real, quadros de vídeo e ID inválido.');
} finally {
    server.close(); rmSync(tmp, { recursive: true, force: true });
}
