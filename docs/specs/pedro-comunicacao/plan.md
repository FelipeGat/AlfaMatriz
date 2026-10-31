# Plano técnico: Comunicação do Pedro (primeira parte)

**Spec**: `docs/specs/pedro-comunicacao/spec.md` · **Data**: 31/10/2026

## Clarificações

Sem `[NEEDS CLARIFICATION]` na spec: o escopo e as decisões vieram da proposta de 31/10/2026, 19:11, aprovada pelo
Felipe na mesma noite ("pode seguir suas recomendações"). O limite de 30 min para o retrato do Gestor ficar
desatualizado é escolha deste plano: o retrato é relido a cada 10 min, então 30 min são três leituras perdidas.

## Contexto técnico

- Laravel 12 / PHP 8.2 no Matriz; runner em Node na máquina do Felipe (`deploy/central-ia/`, instalado em
  `~/.local/share/alfa/`); IA do Pedro = `claude -p` sem ferramentas (`suporte.mjs`).
- **Não há arquitetura nova** (premissa do Felipe, 31/10/2026). A camada de apresentação já existe em três peças, e
  cada uma é melhorada no lugar:
  1. **Dados por permissão:** `AcessoInterno::dados` (e `Contexto` para o cliente).
  2. **Regras:** `Contexto` (os três pedidos: `promptDeIdentificacao`, `prompt`, `promptInterno`).
  3. **Entrega:** `Pedro::concluir` (corte e envio).
- A leitura financeira é o `deploy/central-ia/secretario_gestor.php` (Gestor de produção, pela ponte, só leitura),
  validado em `SecretarioRunnerController::gestor` e guardado em `secretario_gestor_retratos` (`dados.blocos`).

## Constitution Check

| Princípio | Como o plano cumpre |
|---|---|
| I. Privacidade por conta | Nada muda em quem vê o quê: o financeiro continua só nos perfis gestor/diretor (`AcessoInterno::ve`), e o pedido do cliente não recebe nenhum bloco financeiro (FR-014). |
| III. Um sistema só | Sem tabela nem aplicação nova; o retrato ganha campos dentro dos blocos que já existem. |
| IV. Segredos | Nenhum. A bateria não lê `.env` nem token: chama o `claude` da máquina como o runner chama. |
| V. Prova executável | Cada regra com teste em `tests/Feature/Suporte/` e `tests/Feature/Secretario/`; a bateria é prova adicional, não substitui. Runner só conta depois de reinstalado e observado. |
| VII. Autorização | Sem commit, push nem publicação sem pedido; publicação na ordem Matriz → instalador da máquina. |
| VIII. Zero fabricação | Bateria com dados fictícios rotulados; o "antes" real é o das conversas do Felipe (spec, Contexto); nenhum número inventado em artefato. |

## Desenho

### 1. Regras de comunicação (FR-001 a FR-006) — `Contexto`

- `Contexto::COMUNICACAO`: uma regra só, nos três pedidos. Responder primeiro; nome só na saudação da primeira
  resposta do atendimento; hora da leitura só com dado marcado DESATUALIZADO; sem termos de bastidor (lista da
  FR-003); oferecer só o que está nos dados, e quando a lista é parcial, dizer que o resto está no Gestor.
- Sai do `promptInterno`: "Trate-o pelo primeiro nome", "Diga a hora em que cada dado foi lido quando ela importar"
  e "e diga a hora da leitura" (agenda). "Lista longa: mande os 5 primeiros" vira: os principais e a oferta do resto
  que está nos dados; com pedido de lista completa, tudo o que os dados trazem (o envio divide).
- `Contexto::JEITO_POR_PERFIL` (diretor, gestor, suporte), escolhido pelo perfil do `AcessoInterno`.
- No cliente: o "use o primeiro nome da pessoa quando souber" do `HUMANO` e o "Trate-a pelo primeiro nome" da
  IDENTIFICAÇÃO passam a "na saudação".

### 2. Dados marcados (FR-002 a FR-004) — `AcessoInterno`

- Cabeçalho de ATENDIMENTOS e AGENDA: `; DESATUALIZADO, avise` quando `lido_em` passa de 30 min
  (`AcessoInterno::RETRATO_VELHO_MINUTOS`). O financeiro já tinha (`SecretarioGestorRetrato::velho`, 3 h).
- A nota da lista parcial passa de "(os itens acima são os N mais antigos; o total é Q)" a uma instrução explícita:
  só estes N podem ser oferecidos; o resto, no Gestor.
- Linha da visita sem "(sem cadastro)".

### 3. Financeiro (FR-007 a FR-011) — sem chave nova em `BLOCOS`

Mudar as chaves de `SecretarioGestorRetrato::BLOCOS` obrigaria a publicar o Matriz antes da máquina (a validação
usa `array:` e recusa a leitura INTEIRA com chave desconhecida) e mexeria no resumo do Telegram, na Central de
Gestão e na memória do Secretário. Em vez disso, os campos novos ficam **dentro** dos blocos que já existem:

| Bloco | Hoje | Depois |
|---|---|---|
| `pagar_vencendo` | `quantidade`, `itens: []` | + `valor`, `itens` (10 mais antigas: fornecedor ou descrição, vencimento, valor), `vencidas {quantidade, valor}`, `hoje {quantidade, valor}`, `maiores` (10 vencidas por valor) |
| `cobrancas_hoje` | `quantidade`, `itens` | + `valor` |
| `cobrancas_vencidas` | `quantidade`, `valor`, `itens` | + `maiores` (10 por valor) |

- `SecretarioRunnerController::gestor` ganha as regras de `blocos.*.maiores`, `blocos.*.vencidas` e `blocos.*.hoje`
  (todas `sometimes`). O Matriz antigo descarta os campos novos sem recusar (o `validated()` só guarda o que tem
  regra); o novo aceita a leitura antiga. Publicação em qualquer ordem (FR-010).
- `deploy/central-ia/gestor.mjs` (`lerSaida`), na máquina, limpa a leitura antes de mandá-la ao Matriz e só
  deixava passar `quantidade`, `valor` e `itens`: passa a deixar `maiores` (até 10, no formato dos itens) e
  `vencidas`/`hoje` (quantidade inteira e valor). Achado na implementação: a bateria não passa pela máquina.
- `AcessoInterno::gestor` mostra a separação e os maiores. O resumo do Telegram, a Central de Gestão e a memória
  leem só `quantidade`, `valor` e `itens`: passam a mostrar o valor das contas a pagar e das cobranças de hoje sem
  mudança de código, e não veem os maiores (FR-011).

### 4. Envio em partes (FR-012) — `Contexto::partes` + `Pedro::concluir`

- `Contexto::partes(string $texto, int $tamanho = 3900, int $teto = 3): list<string>` corta entre parágrafos
  (`\n\n`), senão entre linhas, senão entre palavras; passou do teto, a última parte termina com o aviso de que o
  resto está no Gestor. 3.900 deixa folga sob os 4.096 do WhatsApp.
- `Pedro::concluir`: o corte da casa passa de 3.500 para o teto das partes; o envio da casa sai parte por parte (a
  primeira com `em_resposta_a`, as outras sem, para o Gestor não recusar como "resposta superada"). Uma parte
  recusada para as seguintes. O cliente continua com 1.500 e uma mensagem.

### 5. Agenda por dia da semana (FR-013) — `AcessoInterno::agenda`

- Dias pedidos: dia da semana (o desta semana se ainda não passou ou é hoje; se já passou, o desta semana e o da
  próxima), "próxima <dia>" (o primeiro depois de hoje), "esta semana" e "semana que vem"/"próxima semana" (os dias
  úteis). Somam-se às datas e às palavras de hoje. Ontem, hoje e amanhã entram só se couberem: os pedidos vêm
  primeiro, até 7 dias.

### 6. Bateria (FR-015) — `tools/central-ia/bateria-pedro/`

- `CenariosTest.php`: PHPUnit rodado à parte (fora das suítes do `phpunit.xml`). Semeia dados fictícios no banco de
  teste, monta os 20 pedidos com o `Contexto` de verdade e grava `storage/app/bateria-pedro/<rótulo>/pedidos.json`.
  O rótulo vem de `BATERIA_ROTULO` (`antes`/`depois`); no `antes`, o retrato financeiro tem o formato que a leitura
  antiga produz.
- `rodar.mjs`: manda cada pedido ao `claude` com os mesmos argumentos do runner (`permissoes(..., {semFerramentas})`,
  `modeloArgs`), lê o bloco `<<<PEDRO … PEDRO>>>` e grava `respostas.json`.
- `relatorio.mjs`: página lado a lado (antes × depois), com as checagens automáticas da SC-001 a SC-006.

## Cenários de teste (automáticos)

- `PedroComunicacaoTest`: regra comum nos três pedidos; frases antigas fora; DESATUALIZADO depois de 30 min; nota da
  lista parcial; jeito por perfil; "(sem cadastro)" fora; agenda por dia da semana (segunda→quarta; quarta→quarta e
  próxima; sexta→quarta passada e próxima; semana que vem; teto de 7); partes (6.000 caracteres → 2 partes;
  parágrafo gigante; teto com aviso; cliente numa só).
- `SecretarioGestorRetratoTest` (ou o teste do runner que já existe): leitura nova aceita e guardada com os campos
  novos; leitura antiga aceita; o pedido do diretor mostra vencidas/hoje com valor e os maiores; suporte não vê.

## Ordem de publicação

1. Matriz (tag) — aceita os dois formatos.
2. Instalador da máquina (`instalar.sh` da produção) — leva o `secretario_gestor.php` novo.
3. Sem mudança no Gestor.
