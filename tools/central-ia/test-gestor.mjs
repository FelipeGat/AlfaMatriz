import assert from 'node:assert/strict';
import { COPIA, hora, lerGestor, lerSaida } from '../../deploy/central-ia/gestor.mjs';

// Retrato do Gestor pela ponte (Secretário, Etapa 5): a saída é lida pela
// linha marcada, o formato é conferido, e a ponte NUNCA recebe --apply.
const ok = 'Conectando...\nSECRETARIO_GESTOR:{"orcamentos_aprovacao":{"quantidade":4,"valor":38200,"itens":[{"texto":"Orç. 191","data":"2026-10-10","valor":12000}]},"funil_parado":{"quantidade":0,"itens":[]}}\n';
assert.deepEqual(lerSaida(ok).orcamentos_aprovacao, { quantidade: 4, valor: 38200, itens: [{ texto: 'Orç. 191', data: '2026-10-10', valor: 12000 }] });
// Os campos novos da leitura (pedro-comunicacao): passam; lixo dentro deles, não.
const novo = lerSaida('SECRETARIO_GESTOR:' + JSON.stringify({ pagar_vencendo: { quantidade: 3, valor: 900, itens: [{ texto: 'Fornecedor X', data: '2026-10-30', valor: 500 }],
    vencidas: { quantidade: 2, valor: 700, apagar: true }, hoje: { quantidade: 'x' }, maiores: [{ texto: 'Fornecedor X', valor: 500 }, { texto: '' }] } }));
assert.deepEqual(novo.pagar_vencendo, { quantidade: 3, valor: 900, itens: [{ texto: 'Fornecedor X', data: '2026-10-30', valor: 500 }],
    maiores: [{ texto: 'Fornecedor X', valor: 500 }], vencidas: { quantidade: 2, valor: 700 } });
assert.equal(lerSaida('sem marca'), null);
assert.equal(lerSaida('SECRETARIO_GESTOR:{"apagar":{"quantidade":1}}'), null, 'bloco desconhecido');
assert.equal(lerSaida('SECRETARIO_GESTOR:{"funil_parado":{"quantidade":"x"}}'), null);

const chamadas = [];
const executar = async (p, args, opts) => { chamadas.push({ p, args, opts }); return { stdout: ok }; };
const r = await lerGestor('/mnt/c/Python314/python.exe|C:\\tmp\\run_prod.py|C:\\tmp\\secretario_gestor.php', { executar });
assert.equal(r.ok, true);
assert.deepEqual(chamadas[0].args, ['C:\\tmp\\run_prod.py', 'C:\\tmp\\secretario_gestor.php']);
assert.ok(!chamadas.some(c => c.args.includes('--apply')), 'Nunca --apply');
assert.equal(chamadas[0].opts.cwd, undefined, 'Sem pasta configurada, sem cwd');
// Com a pasta, a ponte roda a cópia do script INSTALADO, gravada agora ao lado do configurado
// (31/10/2026: o arquivo de C:\tmp era de 17/10 e a leitura nova nunca rodou).
const gravados = [];
const apagados = [];
const arquivos = { origem: '/instalado', ler: (f, enc) => { assert.equal(f, '/instalado/secretario_gestor.php'); return 'SCRIPT NOVO'; },
    escrever: (f, txt) => gravados.push([f, txt]), apagar: f => apagados.push(f) };
await lerGestor('py|C:\\tmp\\run_prod.py|C:\\tmp\\secretario_gestor.php|/mnt/c/tmp', { executar, ...arquivos });
assert.deepEqual(gravados, [['/mnt/c/tmp/' + COPIA, 'SCRIPT NOVO']], 'O script instalado é copiado para a pasta da ponte');
assert.deepEqual(chamadas[1].args, ['C:\\tmp\\run_prod.py', 'C:\\tmp\\' + COPIA], 'A ponte roda a cópia, não o arquivo configurado');
assert.equal(chamadas[1].opts.cwd, '/mnt/c/tmp', 'A pasta vem da configuração da máquina');
assert.deepEqual(apagados, ['/mnt/c/tmp/' + COPIA], 'A cópia sai depois da leitura');
await lerGestor('py|ponte.py|script.php|/pasta/da/ponte', { executar, ...arquivos });
assert.equal(chamadas[2].args[1], COPIA, 'Script sem pasta no caminho: a cópia, relativa ao cwd');
// A ponte falhou: a cópia sai do mesmo jeito.
await lerGestor('py|p|C:\\tmp\\s.php|/mnt/c/tmp', { ...arquivos, executar: async () => { throw new Error('x'); } });
assert.equal(apagados.length, 3, 'A cópia sai também quando a ponte falha');
// Sem o script instalado, nada roda: rodar o arquivo velho seria o defeito de volta.
const n = chamadas.length;
const semScript = await lerGestor('py|p|C:\\tmp\\s.php|/mnt/c/tmp', { executar, ...arquivos, ler: () => { throw new Error('ENOENT'); } });
assert.equal(semScript.ok, false);
assert.match(semScript.erro, /não deu para copiar/);
assert.equal(chamadas.length, n, 'Sem a cópia, a ponte não é chamada');
assert.deepEqual(await lerGestor('incompleto'), { ok: false, erro: 'CENTRAL_IA_GESTOR incompleto' });
assert.equal((await lerGestor('a|b|c', { executar: async () => { throw Object.assign(new Error('x'), { killed: true }); } })).erro, 'a ponte passou do tempo');

assert.equal(hora(null), true);
assert.equal(hora(new Date('2026-10-17T05:00:00'), new Date('2026-10-17T05:30:00')), false);
assert.equal(hora(new Date('2026-10-17T05:00:00'), new Date('2026-10-17T06:05:00')), true);
assert.equal(hora(new Date('2026-10-17T06:10:00'), new Date('2026-10-17T06:40:00')), true, 'janela antes do briefing');
console.log('PASSOU: retrato lido pela marca, formato conferido, ponte sem --apply, cópia do script instalado a cada leitura, pasta da configuração e ritmo de leitura.');
