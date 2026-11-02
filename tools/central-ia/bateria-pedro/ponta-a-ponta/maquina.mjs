// A máquina de verdade (gravarOrcamentos e gravarOrcamentoNoGestor do suporte.mjs), com a ponte trocada por
// "docker exec" no container do Gestor, banco gestor_alfa_test. Uso: node maquina.mjs <fila.json> <saida.json> [--falhar]
import { execFile } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';
const R = fileURLToPath(new URL('../../../../', import.meta.url));
const { gravarOrcamentos, gravarOrcamentoNoGestor } = await import(R + 'deploy/central-ia/suporte.mjs');
const exec = promisify(execFile);
// A pasta do Gestor que o container enxerga como /var/www/storage/app/e2e-pedro-orc.
const pasta = process.env.GESTOR_E2E_PASTA || `${R}../gestor/storage/app/e2e-pedro-orc`;
const falhar = process.argv.includes('--falhar');
const executar = async (programa, args, opts) => {
    if (falhar) throw new Error('ponte fora do ar (simulado)');
    const arquivo = String(args[1]).split('\\').pop();
    return exec('docker', ['exec', '-e', 'DB_DATABASE=gestor_alfa_test', ...(args.includes('--apply') ? ['-e', 'APPLY=1'] : []), 'gestor_php',
        'php', '/var/www/storage/app/e2e-pedro-orc/' + arquivo], { timeout: opts.timeout, maxBuffer: opts.maxBuffer });
};
const fila = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const enviados = [];
const api = async (rota, corpo) => {
    if (rota === 'suporte/gestor/orcamentos') return { status: 200, json: { orcamentos: fila } };
    enviados.push({ rota, corpo });
    return { status: 200, json: { ok: true } };
};
await gravarOrcamentos(`python.exe|ponte.py|C:\\tmp\\x\\secretario_gestor.php|${pasta}`, 'wsl',
    { api, gravar: (cfg, o) => gravarOrcamentoNoGestor(cfg, o, { executar }), log: m => console.log('[máquina]', m), presa: () => false });
writeFileSync(process.argv[3], JSON.stringify(enviados.at(-1)?.corpo ?? null, null, 2));
console.log(JSON.stringify(enviados.at(-1)?.corpo));
