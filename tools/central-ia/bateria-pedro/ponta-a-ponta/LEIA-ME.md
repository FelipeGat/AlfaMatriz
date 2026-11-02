# Ponta a ponta do orçamento pelo Pedro (pedro-orcamento, tarefa 5.8)

Dados fictícios. A gravação é de verdade, mas só no banco de testes do Gestor: o `gestor_e2e.php` recusa
qualquer banco que não seja o `gestor_alfa_test` e, no `limpar`, apaga toda linha criada depois do `preparar`.

1. `mkdir -p ../gestor/storage/app/e2e-pedro-orc && cp gestor_e2e.php ../gestor/storage/app/e2e-pedro-orc/`
2. `docker exec -e DB_DATABASE=gestor_alfa_test gestor_php php /var/www/storage/app/e2e-pedro-orc/gestor_e2e.php preparar /var/www/storage/app/e2e-pedro-orc > <pasta>/gestor.json`
3. O áudio: sintetizar um pedido falado e transcrever com `transcreverAudioCurto` (`deploy/central-ia/transcricao.mjs`).
4. `python3 rodar-php.py prompt1 <pasta> "<transcrição>" tools/central-ia/bateria-pedro/ponta-a-ponta/OrcamentoPontaAPontaTest.php`,
   `node rodar-ia.mjs <raiz> <pasta>/e2e-prompt1.json <pasta>/e2e-ia1.json` e grave o `bruto` em `e2e-ia1.txt`;
   o mesmo para `prompt2` → `e2e-ia2.txt`; depois a fase `fila` → `e2e-fila.json`.
5. `node maquina.mjs <pasta>/e2e-fila.json <pasta>/e2e-resultado.json` (o `gravarOrcamentos` do runner, com a ponte
   trocada por `docker exec` no container do Gestor; `--falhar` simula a ponte fora do ar).
6. Fase `resultado` → `e2e-mensagens.json`. No Gestor: `orcamento <id>`, `pdf <id> <email> <saida.pdf>`, `contar <chave>`,
   `desativar <email>`.
7. `gestor_e2e.php limpar /var/www/storage/app/e2e-pedro-orc` e apague a pasta.

A bateria da conversa (sem Gestor) é o `../OrcamentoCenariosTest.php`, com as fases `pedidos` e `envios`.
