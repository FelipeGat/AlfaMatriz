#!/usr/bin/env node
// O relatório da bateria (docs/specs/pedro-comunicacao, FR-015): as 20 situações com a resposta de
// antes e a de depois lado a lado, e as checagens automáticas das SC-001 a SC-006. Página única,
// sem nada de fora, para abrir no celular.
//
//   node tools/central-ia/bateria-pedro/relatorio.mjs [antes] [depois] [saida.html]   (padrão: antes depois)
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const raiz = join(dirname(fileURLToPath(import.meta.url)), '../../..');
const pasta = join(raiz, 'storage/app/bateria-pedro');
const ler = (r, a) => JSON.parse(readFileSync(join(pasta, r, a), 'utf8'));
const [rotuloAntes = 'antes', rotuloDepois = 'depois'] = process.argv.slice(2, 4);
const pedidos = ler(rotuloDepois, 'pedidos.json');
const antes = ler(rotuloAntes, 'respostas.json');
const depois = ler(rotuloDepois, 'respostas.json');
const saida = process.argv[4] || join(pasta, rotuloDepois === 'depois' ? 'relatorio.html' : `relatorio-${rotuloDepois}.html`);

const NOMES = { diretor: 'Carlos', gestor: 'Bruna', suporte: 'Diego', 'cliente do cadastro': 'Marta', 'cliente pelo nome': 'Marta' };
const CLIENTE = p => p.perfil.startsWith('cliente') || p.perfil === 'desconhecido';

// As checagens da spec, por resposta. Cada uma: [rótulo, problema?].
function checar(p, texto) {
    if (!texto) return [['sem resposta', true]];
    const r = [];
    const nome = NOMES[p.perfil];
    const continuacao = p.conversa.some(m => m.de === 'pedro');
    if (nome && continuacao) r.push([`nome na 2ª resposta`, new RegExp(`^\\W*${nome}\\b`).test(texto)]);
    if (!/há 2 h/.test(p.situacao)) r.push(['hora da leitura com dado recente', /\blid[oa]s? às\b|\(lido/i.test(texto)]);
    // Só a OFERTA conta ("quer ver as outras 19?"); dizer que os outros 14 estão no Gestor é o certo.
    const ofertas = [...texto.matchAll(/(quer|posso|mando|envio|te passo)[^.\n?]{0,30}outr[ao]s (\d+)/gi)].map(m => Number(m[2]));
    if (p.situacao.startsWith('lista parcial') || ofertas.length) r.push([ofertas.length ? `oferece "outros ${ofertas.join(', ')}"` : 'não oferece além da lista', ofertas.some(n => n > 5)]);
    if (CLIENTE(p)) r.push(['valor em R$ para cliente', /R\$\s?\d/.test(texto)]);
    if (/bastidor|retrato|\bsem cadastro\b|\bprompt\b/i.test(texto)) r.push(['termo de bastidor', true]);
    if (/…$/.test(texto.trim())) r.push(['cortada com …', true]);
    // 2ª rodada (pedro-comunicacao, FR-016 a FR-021).
    // Prazo prometido, e não serviço descrito ("monitoramento 24 horas").
    if (CLIENTE(p)) r.push(['promete prazo', /até \d+\s*(h\b|hora|minuto|dia)|em (até )?\d+\s*(h\b|hora|minuto|dia)|dentro de \d+|prazo de \d+/i.test(texto)]);
    const oferta = texto.match(/quer que eu (cobre|ligue|mande mensagem|envie (uma )?mensagem|avise o (cliente|fornecedor)|veja os andamentos|conclua|pague|mande o boleto)/i);
    if (oferta) r.push([`oferece o que não faz ("${oferta[0]}")`, true]);
    const c = p.checar || {};
    for (const [rotulo, re] of c.proibido || []) {
        if (new RegExp(re, 'i').test(texto)) r.push([rotulo, true]);
    }
    for (const [rotulo, re] of c.exige || []) r.push([rotulo, !new RegExp(re, 'i').test(texto)]);
    if (c.periodo) {
        const [rotulo, re] = c.periodo;
        const fora = new RegExp(re, 'i').test(texto);
        const avisou = /fora desse per[ií]odo|fora (do|deste) (per[ií]odo|dia)|além do (que|per[ií]odo)|informaç(ão|ões) adiciona/i.test(texto);
        r.push([fora ? (avisou ? `${rotulo}: à parte, avisado` : `mistura ${rotulo}`) : `só o período pedido`, fora && !avisou]);
    }
    if (c.max_frases) {
        // A apresentação (obrigatória na primeira resposta) não conta: a regra é sobre o conteúdo comercial.
        const apresentacao = f => /^(olá|oi|boa (noite|tarde)|bom dia)[,!.]?$/i.test(f.trim()) || /^((olá|oi)[,!]?\s*)?((eu )?sou o pedro|aqui é o pedro)/i.test(f.trim());
        const frases = texto.split(/(?<=[.!?])\s+|\n+/).filter(f => /\p{L}/u.test(f) && !apresentacao(f)).length;
        r.push([`${frases} frases além da apresentação (máx. ${c.max_frases})`, frases > c.max_frases]);
    }
    return r;
}

const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const zap = s => esc(s).replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/\n/g, '<br>');
const chips = cs => cs.map(([t, ruim]) => `<span class="chip ${ruim ? 'ruim' : 'ok'}">${ruim ? '✗' : '✓'} ${esc(t)}</span>`).join(' ');

let problemasAntes = 0;
let problemasDepois = 0;
const linhas = pedidos.map(p => {
    const a = antes.respostas.find(x => x.id === p.id);
    const d = depois.respostas.find(x => x.id === p.id);
    const ca = checar(p, a?.resposta);
    const cd = checar(p, d?.resposta);
    problemasAntes += ca.filter(c => c[1]).length;
    problemasDepois += cd.filter(c => c[1]).length;
    const conversa = p.conversa.map(m => `<div class="msg ${m.de}"><span>${m.de === 'pedro' ? 'Pedro' : 'Pessoa'}</span>${zap(m.texto)}</div>`).join('');
    const bloco = (r, cs) => `<div class="resp"><div class="meta">${esc(r?.acao || r?.erro || '')} · ${(r?.resposta || '').length} caracteres</div>${zap(r?.resposta || r?.erro)}<div class="chips">${chips(cs)}</div></div>`;
    return `<section><h2>${p.id}. ${esc(p.perfil)} — ${esc(p.situacao)}</h2><div class="quando">${esc(p.agora)}</div>${conversa}
<div class="lado"><div><h3>Antes</h3>${bloco(a, ca)}</div><div><h3>Depois</h3>${bloco(d, cd)}</div></div></section>`;
}).join('\n');

const html = `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pedro — bateria de comunicação (${esc(rotuloAntes)} × ${esc(rotuloDepois)})</title><style>
body{font:15px/1.45 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;margin:0;background:#f4f5f7;color:#1d2330}
header{background:#1d2330;color:#fff;padding:18px 20px}header h1{margin:0 0 6px;font-size:20px}header p{margin:2px 0;opacity:.85}
main{max-width:1100px;margin:0 auto;padding:12px}section{background:#fff;border-radius:10px;padding:14px 16px;margin:12px 0;box-shadow:0 1px 2px rgba(0,0,0,.06)}
h2{font-size:16px;margin:0 0 2px}h3{font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:#5b6475;margin:10px 0 6px}
.quando{font-size:12px;color:#5b6475;margin-bottom:8px}.msg{background:#eef1f5;border-radius:8px;padding:6px 10px;margin:4px 0;font-size:14px}
.msg.pedro{background:#e7f3ec}.msg span{display:block;font-size:11px;color:#5b6475}
.lado{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:760px){.lado{grid-template-columns:1fr}}
.resp{border:1px solid #dde1e8;border-radius:8px;padding:10px;font-size:14px}.meta{font-size:11px;color:#5b6475;margin-bottom:6px}
.chips{margin-top:8px}.chip{display:inline-block;font-size:11px;border-radius:10px;padding:2px 8px;margin:2px 2px 0 0}
.chip.ok{background:#e3f4e8;color:#1f6b3a}.chip.ruim{background:#fde7e7;color:#a32020}
</style></head><body><header><h1>Pedro — bateria de comunicação (dados fictícios)</h1>
<p>${pedidos.length} situações, a mesma pergunta antes (${esc(rotuloAntes)}) e depois (${esc(rotuloDepois)}) da mudança, respondidas pela IA de verdade (${esc(depois.modelo)}).</p>
<p>Checagens automáticas com problema: <b>antes ${problemasAntes}</b> · <b>depois ${problemasDepois}</b>. Nenhum nome, telefone ou valor é de cliente real.</p>
<p>Antes: ${esc(antes.rodado_em)} · Depois: ${esc(depois.rodado_em)}</p></header><main>${linhas}</main></body></html>`;

mkdirSync(dirname(saida), { recursive: true });
writeFileSync(saida, html);
console.log(`relatório: ${saida} (problemas antes ${problemasAntes}, depois ${problemasDepois})`);
