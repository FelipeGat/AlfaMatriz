// Uso: node rodar-ia.mjs <raiz do Matriz> <pedidos.json> <respostas.json>. Guarda a resposta bruta de cada pedido.
// Teste de 02/11/2026 (pedro-orcamento): os pedidos montados pelo Contexto real vão à IA desta máquina
// com os mesmos argumentos do runner do Pedro (sem ferramentas, modelo sonnet). Dados fictícios.
import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { homedir, tmpdir } from 'node:os';
import { join } from 'node:path';
const raiz = process.argv[2];
const { modeloArgs, permissoes } = await import(join(raiz, 'deploy/central-ia/politica.mjs'));
const pedidos = JSON.parse(readFileSync(process.argv[3], 'utf8'));
const base = join(homedir(), '.vscode-server/extensions');
const achados = existsSync(base) ? readdirSync(base).filter(d => d.startsWith('anthropic.claude-code-')).map(d => join(base, d, 'resources/native-binary/claude')).filter(existsSync) : [];
achados.sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
const bin = achados.at(-1);
if (!bin) throw new Error('claude não encontrado');
const perguntar = prompt => new Promise(resolve => {
    const dir = mkdtempSync(join(tmpdir(), 'bateria-orc-'));
    const args = ['-p', '--output-format', 'json', '--no-session-persistence', ...permissoes('claude', 'leitura', { semFerramentas: true }), ...modeloArgs('claude', 'sonnet')];
    const f = spawn(bin, args, { cwd: dir, stdio: ['pipe', 'pipe', 'pipe'] });
    let out = '';
    const t = setTimeout(() => f.kill('SIGKILL'), 240_000);
    f.stdout.on('data', d => { out += d; });
    f.on('close', () => { clearTimeout(t); rmSync(dir, { recursive: true, force: true });
        try { const j = JSON.parse(out.trim().split('\n').at(-1)); resolve(String(j.result ?? '')); } catch { resolve(out); } });
    f.stdin.end(prompt);
});
const res = [];
const fila = [...pedidos];
async function w() { for (let p = fila.shift(); p; p = fila.shift()) {
    const ini = Date.now(); const bruto = await perguntar(p.prompt);
    const m = bruto.match(/<<<PEDRO\s*(\{[\s\S]*\})\s*PEDRO>>>/); let b = null; try { b = m ? JSON.parse(m[1]) : null; } catch {}
    res.push({ n: p.n, nome: p.nome, bloco: b, bruto, s: Math.round((Date.now() - ini) / 1000) });
    console.log(`#${p.n} ${b ? 'ok' : 'SEM BLOCO'} ${Math.round((Date.now() - ini) / 1000)}s`);
} }
await Promise.all([w(), w()]);
res.sort((a, b) => a.n - b.n);
writeFileSync(process.argv[4], JSON.stringify(res, null, 2));
