#!/usr/bin/env node
// A bateria do Pedro (docs/specs/pedro-comunicacao, FR-015): cada pedido montado pelo
// CenariosTest vai à IA DESTA máquina com os mesmos argumentos do runner (suporte.mjs:
// claude -p, sem ferramentas, modelo do Pedro), e a resposta fica ao lado.
//
//   node tools/central-ia/bateria-pedro/rodar.mjs <rótulo> [modelo]   (antes, depois, r2-antes, r2-depois…)
//
// Os pedidos só têm dados fictícios; nada vai ao Matriz nem ao WhatsApp.
import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { homedir, tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { modeloArgs, permissoes } from '../../../deploy/central-ia/politica.mjs';

const raiz = join(dirname(fileURLToPath(import.meta.url)), '../../..');
const rotulo = process.argv[2];
const modelo = process.argv[3] || 'sonnet';
if (!/^[a-z0-9-]{2,20}$/.test(rotulo || '')) {
    console.error('uso: rodar.mjs <rótulo> [modelo]');
    process.exit(2);
}
const pasta = join(raiz, 'storage/app/bateria-pedro', rotulo);
const pedidos = JSON.parse(readFileSync(join(pasta, 'pedidos.json'), 'utf8'));

// O mesmo binário que o runner acha (central-ia-runner.mjs, localizarAgentes): o da extensão mais nova.
function claude() {
    const base = join(homedir(), '.vscode-server/extensions');
    const achados = existsSync(base) ? readdirSync(base).filter(d => d.startsWith('anthropic.claude-code-'))
        .map(d => join(base, d, 'resources/native-binary/claude')).filter(existsSync) : [];
    achados.sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
    if (!achados.length) throw new Error('claude não encontrado');
    return achados.at(-1);
}
const bin = claude();

function perguntar(prompt) {
    const dir = mkdtempSync(join(tmpdir(), 'bateria-pedro-'));
    const args = ['-p', '--output-format', 'json', '--no-session-persistence', ...permissoes('claude', 'leitura', { semFerramentas: true }), ...modeloArgs('claude', modelo)];
    return new Promise((resolve) => {
        const filho = spawn(bin, args, { cwd: dir, stdio: ['pipe', 'pipe', 'pipe'] });
        let out = '';
        let err = '';
        const t = setTimeout(() => filho.kill('SIGKILL'), 240_000);
        filho.stdout.on('data', d => { out += d; });
        filho.stderr.on('data', d => { err += d; });
        filho.on('close', () => {
            clearTimeout(t);
            rmSync(dir, { recursive: true, force: true });
            try {
                const j = JSON.parse(out.trim().split('\n').at(-1));
                resolve({ bruto: String(j.result ?? ''), erro: j.is_error ? String(j.result || j.subtype) : null });
            } catch {
                resolve({ bruto: out, erro: 'resposta ilegível: ' + err.slice(0, 200) });
            }
        });
        filho.stdin.end(prompt);
    });
}

// O mesmo bloco que o Contexto::ler lê.
function bloco(texto) {
    const m = texto.match(/<<<PEDRO\s*(\{[\s\S]*\})\s*PEDRO>>>/);
    try { return m ? JSON.parse(m[1]) : null; } catch { return null; }
}

const respostas = [];
const fila = [...pedidos];
async function trabalhador() {
    for (let p = fila.shift(); p; p = fila.shift()) {
        const inicio = Date.now();
        const r = await perguntar(p.prompt);
        const b = bloco(r.bruto);
        respostas.push({ id: p.id, resposta: b?.resposta ?? null, acao: b?.acao ?? null, erro: r.erro ?? (b ? null : 'sem bloco PEDRO'), ms: Date.now() - inicio, bruto: b ? undefined : r.bruto.slice(0, 2000) });
        console.log(`${rotulo} #${p.id} ${b ? 'ok' : 'ERRO'} ${Math.round((Date.now() - inicio) / 1000)}s`);
    }
}
await Promise.all([trabalhador(), trabalhador(), trabalhador()]);
respostas.sort((a, b) => a.id - b.id);
writeFileSync(join(pasta, 'respostas.json'), JSON.stringify({ modelo, rodado_em: new Date().toISOString(), respostas }, null, 2));
console.log(`gravado ${join(pasta, 'respostas.json')}`);
