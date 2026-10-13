// Offline demonstration using the SAME Blade report fragment. No model, DB or network.
import fs from 'node:fs';
const view = fs.readFileSync('resources/views/central-ia/index.blade.php','utf8');
const start = view.indexOf('<template x-if="m.visual?.relatorio">');
const end = view.indexOf('<div class="central-resposta',start);
const fragment = view.slice(start,end);
const fixture = JSON.parse(fs.readFileSync('tests/Fixtures/central-ia/relatorio-historico.json','utf8'));
const alpine = fs.readFileSync('node_modules/alpinejs/dist/cdn.min.js','utf8');
const css = fs.readFileSync('public/build/'+JSON.parse(fs.readFileSync('public/build/manifest.json'))['resources/css/app.css'].file,'utf8');
const data = JSON.stringify({m:{visual:{relatorio:fixture}}}).replaceAll('&','&amp;').replaceAll("'",'&#39;').replaceAll('<','&lt;');
fs.writeFileSync('/tmp/central-ia-demo.html',`<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Exemplo histórico da Central</title><style>${css}</style><body class="bg-panel text-ink"><main class="max-w-5xl mx-auto p-4" x-data='${data}'><p class="mb-4">Demonstração offline; dados históricos fornecidos pelo usuário, não valores atuais.</p>${fragment}</main><script>${alpine}</script></body></html>`);
console.log('Demonstração criada: /tmp/central-ia-demo.html');
