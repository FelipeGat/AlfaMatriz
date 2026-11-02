# Implementation Plan: O Pedro cria orçamento no Gestor pela conversa

**Feature**: `pedro-orcamento` | **Spec**: `spec.md` | **Data**: 2026-11-02

## Resumo técnico

Mesmo caminho da agenda dos técnicos (`pedro-dia-do-tecnico`): a IA, sem ferramentas, devolve no
bloco `<<<PEDRO` a ação `previa_orcamento` com o orçamento entendido; o Matriz confere contra o
retrato do Gestor e MONTA o texto da prévia (os números da prévia são os que vão para o Gestor, e não
os que a IA escreveu); o "sim" vira `confirmar_orcamento`, que põe UMA linha na fila
`suporte_gestor_orcamentos` com chave única; a máquina do dono grava pela ponte, com `--apply`,
chamando um serviço do Gestor; o resultado volta e o Pedro responde com número, total e links.

No Gestor, a regra da tela (`OrcamentoController::store`) sai do controller para um serviço
(`CriacaoDeOrcamento`), chamado pela tela e pelo Pedro — o mesmo cálculo de itens, descontos, taxas e
NF, sem cópia (FR-010; constituição do Gestor, VI e VII). O caminho da API do app
(`CriarOrcamentoAction`) não serve: ele grava o `valor_total` que o app manda pronto e não carimba o
percentual de NF.

## Contexto técnico

- **Matriz**: Laravel 11, PHP 8.3, MariaDB; testes `tools/central-ia/run-tests.py` (suíte isolada).
- **Máquina**: Node 20 (`deploy/central-ia/suporte.mjs`, `runner.mjs`), ponte Python do Windows
  para o Gestor de produção (`CENTRAL_IA_GESTOR`).
- **Gestor**: Laravel, PHP no container `php-fpm`; testes Pest na suíte `Integracao`
  (`DatabaseTransactions`, banco `gestor_alfa_test`); `pint`.

## Constitution Check

### Matriz (`docs/constitution.md`)

| Princípio | Como este plano cumpre |
|---|---|
| I. Privacidade por conta | Nada novo visível a outra conta; a fila é da conta do Pedro. |
| III. Um sistema só | A regra de orçamento é a do Gestor; o Matriz só confere e enfileira. |
| IV. Segredos fora de código, log e prompt | Nenhum segredo novo; o e-mail do usuário do Gestor não vai ao prompt. |
| V. Prova executável | Testes de feature no Matriz, testes da máquina e Pest no Gestor. |
| VI. Acesso total com rastro | Autor real (usuário do vendedor) e chave da prévia no orçamento. |
| VII. Autorização explícita | Grava só depois do "sim"; deploy só com autorização do dono. |
| VIII. Zero fabricação | Prévia montada pelo sistema; valores só de quem pede; total final é o do Gestor. |

### Gestor (`docs/constitution.md`)

| Princípio | Como este plano cumpre |
|---|---|
| I. Veracidade | Nenhum valor default inventado; item sem valor não grava. |
| II. O banco é produção | Testes só na `Integracao`; a migração é aditiva (coluna nova, nula, única). |
| III. Quem escreve não aprova | QA independente (Copilot) antes do commit, com `.qa-diff` do escopo. |
| IV. Paridade desktop↔app | `App: não se aplica` — nada muda no `/api/v1`; o serviço é do desktop e do Pedro. |
| VI. Camadas | Regra da tela vai para um Service; o controller valida e chama. |
| VII. Multi-empresa | O percentual de NF continua carimbado na criação, pela empresa. |

## Componentes

### Gestor

1. `app/Services/Comercial/CriacaoDeOrcamento.php` — `criar(array $dados, User $autor): Orcamento`:
   o corpo transacional de `OrcamentoController::store` (taxas, proteção do atendimento de outro
   cliente, número, carimbo da NF, `cobranca_pendente`, equipe técnica e piso de mão de obra, itens,
   descontos e travas, total). Os auxiliares de equipe técnica vão junto e ficam públicos; o
   controller delega a eles também no `update`, sem mudar o `update`.
2. `OrcamentoController::store` — valida, confere "pelo menos um item" e o tipo de cliente, chama o
   serviço, salva as fotos, liga a reunião e responde, como hoje.
3. Migração `orcamentos.matriz_chave` (string 64, nula, única) — o padrão de
   `whatsapp_mensagens.matriz_chave`.
4. `app/Services/Comercial/OrcamentoPeloPedro.php` — `criar(array $pedido): array`: chave repetida
   devolve o existente; confere usuário (ativo, funcionário, admin/administrativo ou comercial),
   cliente ou pré-cliente, empresa, atendimento do mesmo cliente (recusa, em vez de soltar o
   vínculo calado como a tela), itens; chama `CriacaoDeOrcamento`; grava a chave; devolve número,
   total, percentual e os links `orcamentos.show` e `orcamentos.imprimir`.

### Máquina (repositório do Matriz, `deploy/central-ia/`)

5. `suporte_gestor_orcamento.php` — roda dentro do Gestor pela ponte; sem o serviço do Gestor,
   recusa ("publicar o Gestor antes"); sem `APPLY=1`, faz e desfaz. Saída `SUPORTE_ORCAMENTO:{json}`.
6. `suporte.mjs` — `gravarOrcamentoNoGestor` e `gravarOrcamentos` (ponte presa não reserva).
7. `runner.mjs` — chama `gravarOrcamentos` junto da agenda.
8. `suporte_gestor_ler.php` — retrato ganha `empresas` (id, nome, percentual de NF vigente).

### Matriz

9. Migração: `suporte_conversas.previa_orcamento` (json); tabela `suporte_gestor_orcamentos`;
   `suporte_gestor_retrato.empresas` (json).
10. `AcessoInterno` — a linha da lista aceita a 4ª coluna, o e-mail do usuário no Gestor;
    `criaOrcamento($acesso)`; `oQueFaz` com a capacidade só para quem pode (FR-021).
11. `app/Services/Suporte/OrcamentoDoGestor.php` — `previa`, `confirmar`, `concluir`, `conferir`,
    `textoDaPrevia`.
12. `Contexto` — trecho CRIAR ORÇAMENTO no pedido interno (só para quem pode), ações e o bloco
    `orcamento` no `ler`, EMPRESAS com o percentual, e os orçamentos em "O QUE FOI ENVIADO".
13. `Pedro` — ações no `concluir` (interno), prazos no `vencidos`/`semRetornoDoGestor`, e a ação
    neutralizada para quem não é da casa (FR-019).
14. `SuportePedroRunnerController` + `routes/central-ia.php` — `suporte/gestor/orcamentos` e
    `suporte/gestor/orcamentos/{id}`; o retrato aceita `empresas`.

### Ajustes de 02/11/2026, sessão 2 (FR-022 a FR-027)

Só Matriz e máquina; o Gestor não muda (a regra de NF e a chave já estão lá).

15. `OrcamentoDoGestor::resolver` — a forma do orçamento que vai ao Gestor, calculada a cada prévia
    a partir do que ele disse (guardado como disse): valor com NF convertido pela regra do Gestor
    (`round(subtotal × pct / 100, 2)`), com o centavo que faz o total bater (ajuste de ±1 centavo
    num item de quantidade 1); quantidade 1 sugerida no único item de serviço. Guardar o que ele
    disse, e não o convertido, deixa a correção de empresa ou de NF refazer a conta.
16. `OrcamentoDoGestor::corrigir` + ação `corrigir_orcamento` (bloco `correcao`, só o que mudou;
    itens pelo número da prévia) aplicada sobre a última prévia não confirmada.
17. `OrcamentoDoGestor::temas` — compatibilidade de assunto por famílias de palavras (portão,
    câmera, alarme, interfone, acesso, ar-condicionado, elétrica, hidráulica, TI, obra), lidas da
    descrição e do NOME do assunto do atendimento (a categoria do assunto é larga demais:
    "SEGURANÇA ELETRÔNICA" junta câmera, alarme e acesso). Sem família reconhecida no orçamento,
    vale palavra significativa em comum.
18. `confirmar` com prévia já confirmada e a fila em falha (ou sem retorno há mais de 10 min): a
    MESMA linha volta a `pendente`, com a mesma chave — o Gestor devolve o que já existe
    (`matriz_chave`, `ja_estava`). Falha "incerta" (a ponte não respondeu) trava prévia nova do
    mesmo cliente até a repetição.
19. `runner.mjs` — depois de uma resposta do Pedro, a gravação no Gestor roda no ciclo seguinte, sem
    esperar os 20 s (o número chega mais cedo depois do "sim").

## Contratos

Ver `contracts/runner-orcamento.md`.

## Ordem de publicação

1. **Gestor** (migração + serviços + controller), depois do QA independente.
2. **Matriz** (tag) — antes do Gestor ter o serviço, a fila espera: a máquina não roda sem o
   instalador novo, e o script recusa sem o serviço.
3. **Instalador da máquina** — leva `suporte.mjs`, `runner.mjs`, o script novo e o retrato com
   `empresas`.
4. **Lista da casa** — o dono acrescenta o e-mail do Gestor nas linhas dele e do Wallas.

Qualquer ordem é segura: o retrato com `empresas` é ignorado por Matriz velho; prévia sem
`empresas` sai sem o percentual; o script recusa num Gestor sem o serviço.

## Riscos

- **Refatoração do `store` da tela** (o caminho mais usado do comercial): coberto por testes de
  caracterização da tela, escritos e passando ANTES da extração e de novo depois.
- **Total da prévia ≠ total do Gestor**: a prévia usa o percentual do retrato (até 10 min); o Gestor
  recalcula na hora e a mensagem final aponta a diferença.
- **Quem é "proprietário"**: hoje é perfil diretor + vínculo; se alguém mais virar diretor com
  vínculo, também cria. É a "autorização explícita" do dono, que é quem edita a lista.
- **Retrato sem o histórico do atendimento**: só a descrição (300 caracteres) vai à prévia;
  andamentos ficam para a `pedro-inteligencia-operacional` (Fase A).
- **PDF**: o link exige login; a rota pública assinada do app vale 5 min e não serve para WhatsApp.
