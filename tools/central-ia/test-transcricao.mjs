import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { mkdtempSync, writeFileSync, chmodSync, readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createHash } from 'node:crypto';
import { transcrever } from '../../deploy/central-ia/transcricao.mjs';

// O runner transcreve na máquina (Secretário, Etapa 4). Aqui o "docker" é um
// script falso: o que se confere é o caminho do runner — download autenticado,
// sha256, saída lida e a pasta temporária apagada. O teste real do container
// é feito no teste local (docs/specs/secretario-reunioes/tasks.md 4.1.2).
const base = mkdtempSync(join(tmpdir(), 'teste-transcricao-'));
const audio = Buffer.from('OggS-audio-de-teste');
const sha = createHash('sha256').update(audio).digest('hex');
const pedidos = [];
const server = createServer((req, res) => {
    pedidos.push({ url: req.url, auth: req.headers.authorization });
    if (req.url.startsWith('/api/central-ia/runner/transcricoes/7/audio')) return res.end(audio);
    res.writeHead(404).end();
});
await new Promise(r => server.listen(0, '127.0.0.1', r));
const cfg = { url: `http://127.0.0.1:${server.address().port}`, token: 'token-sintetico', maquina: 'wsl' };
const docker = (saida, codigo = 0) => {
    const f = join(base, `docker-${Math.random().toString(36).slice(2)}.sh`);
    writeFileSync(f, `#!/bin/sh\nprintf '%s\\n' '${saida}'\nexit ${codigo}\n`);
    chmodSync(f, 0o755);
    return f;
};
try {
    const ok = await transcrever({ id: 7, extensao: 'ogg', sha256: sha }, cfg, base,
        { docker: docker('{"duracao": 12.5, "trechos": [{"inicio": 0, "fim": 4.2, "texto": " Bom dia "}, {"inicio": 5, "fim": 3, "texto": "fim antes do início"}, {"inicio": 6, "fim": 7, "texto": ""}]}') });
    assert.equal(ok.status, 'ok');
    assert.deepEqual(ok.trechos, [{ inicio: 0, fim: 4.2, texto: 'Bom dia' }, { inicio: 5, fim: 5, texto: 'fim antes do início' }]);
    assert.equal(ok.duracao, 12.5);
    assert.equal(pedidos[0].auth, 'Bearer token-sintetico');
    assert.match(pedidos[0].url, /maquina=wsl/);
    assert.deepEqual(readdirSync(base).filter(n => n.startsWith('secretario-reuniao-')), [], 'Pasta temporária apagada');

    const semFala = await transcrever({ id: 7, extensao: 'ogg', sha256: sha }, cfg, base, { docker: docker('{"erro": "não encontrei fala no áudio"}', 1) });
    assert.deepEqual(semFala, { status: 'falhou', erro: 'não encontrei fala no áudio' });

    const errado = await transcrever({ id: 7, extensao: 'ogg', sha256: 'a'.repeat(64) }, cfg, base, { docker: docker('{}') });
    assert.deepEqual(errado, { status: 'falhou', erro: 'o áudio não confere (sha256)' });

    const quebrado = await transcrever({ id: 7, extensao: 'ogg', sha256: sha }, cfg, base, { docker: docker('isso não é json') });
    assert.deepEqual(quebrado, { status: 'falhou', erro: 'resposta ilegível do transcritor' });

    assert.equal((await transcrever({ id: 'x', extensao: 'ogg', sha256: sha }, cfg, base)).status, 'falhou');
    assert.equal((await transcrever({ id: 7, extensao: 'exe', sha256: sha }, cfg, base)).status, 'falhou');
    assert.equal((await transcrever({ id: 8, extensao: 'ogg', sha256: sha }, cfg, base, { docker: docker('{}') })).erro, 'não consegui baixar o áudio (HTTP 404)');
    console.log('PASSOU: download autenticado, sha256, trechos normalizados, falhas legíveis e pasta temporária apagada.');
} finally {
    server.close();
    rmSync(base, { recursive: true, force: true });
}
