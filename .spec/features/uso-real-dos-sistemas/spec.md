# Spec: Uso real dos sistemas

> feature: uso-real-dos-sistemas
> status: rascunho

## Contexto

A Matriz fatura por "unidades ativas", mas só sabe contar clientes — serve para
o AlfaGym (academia ativa) e para o AlfaControl (condomínio ativo), e não serve
para sistema metrado: o AlfaJornada cobra por **funcionário ativo** (R$ 2,50 a
unidade) e esse número só existe dentro dele. O contrato `/api/matriz/v1` ganha
uma coleção `/uso` — um item por cliente, com `unidades_ativas` (a contagem na
unidade de cobrança do sistema) e `metricas` (contadores informativos: pessoas,
funcionários e alunos ativos, dispositivos, CNPJs) — e o sincronizador passa a
espelhar esse retrato no vínculo `cliente_sistema`, no mesmo ciclo horário que
já lê revendas, clientes, licenças e módulos.

## Histórias

### US-088 — O uso real de cada cliente chega à Matriz

Como admin da Matriz, quero que o ciclo de sincronização leia quantas unidades
cada cliente realmente usa em cada sistema integrado, para que a cobrança
metrada e o suporte enxerguem o número medido na origem em vez de estimativa.

#### AC-321 — O retrato de uso do cliente aparece no vínculo

- **Dado** um sistema integrável que declara a capacidade `sincroniza_uso` e
  responde `/uso` com um cliente ancorado
- **Quando** o ciclo de sincronização roda
- **Então** o vínculo do cliente com o sistema guarda as unidades ativas, as
  métricas informadas e a hora da medição (colunas `uso_unidades`,
  `uso_metricas` e `uso_medido_em` de `cliente_sistema`)

#### AC-322 — Sistema sem a capacidade não é perguntado sobre uso

- **Dado** um sistema integrável com `sincroniza` mas sem `sincroniza_uso`
- **Quando** o ciclo de sincronização roda
- **Então** nenhuma chamada a `/uso` é feita e o restante (revendas, clientes)
  sincroniza normalmente — consultar um endereço que a origem não serve daria
  404 a cada ciclo, como já acontecia com módulos

#### AC-323 — Desligar a capacidade apaga o retrato que sobrou

- **Dado** um cliente com uso gravado no vínculo e um sistema que perdeu a
  capacidade `sincroniza_uso`
- **Quando** o ciclo de sincronização roda
- **Então** o retrato de uso é limpo — um número congelado que a Matriz não
  consegue mais confirmar não fica na tela, mesmo padrão do retrato de licença

#### AC-324 — Uso de cliente desconhecido não derruba o ciclo

- **Dado** uma resposta de `/uso` em que um item aponta `cliente_id_externo`
  que a Matriz não tem ancorado
- **Quando** o ciclo de sincronização roda
- **Então** esse item é pulado e os demais itens da resposta gravam o uso
  normalmente

#### AC-325 — O relatório do comando conta o uso aplicado

- **Dado** um sistema com `sincroniza_uso` respondendo uso para clientes
  ancorados
- **Quando** `alfa:sincronizar-sistemas` roda
- **Então** o relatório informa quantos clientes tiveram o uso medido, e o
  ciclo com movimento deixa o número na auditoria

### US-089 — O AlfaJornada entra no ciclo como os demais

Como dono do produto, quero o AlfaJornada integrável pelo mesmo contrato e pelo
mesmo comando dos outros sistemas, para que configurar endereço e chave na tela
de Sistemas seja o suficiente para os clientes e o uso dele aparecerem na
Matriz — sem código novo por sistema.

#### AC-326 — Configurar o AlfaJornada basta para ele sincronizar

- **Dado** o AlfaJornada cadastrado com as capacidades `sincroniza` e
  `sincroniza_uso`, endereço e chave preenchidos
- **Quando** o ciclo de sincronização roda sem `--sistema`
- **Então** as revendas e os clientes dele são espelhados e o uso de cada
  cliente é gravado no vínculo, com a chave dele no header `X-Matriz-Key`

## Fora de escopo

- Mudar o fechamento mensal: o `FaturamentoService` continua contando clientes
  ativos até o dono decidir a virada da cobrança metrada (ver Q-040).
- Telas: nenhuma view passa a exibir o uso nesta feature; o retrato fica
  disponível no vínculo para a tela que vier.
- Expor `/uso` no AlfaGym (a unidade dele é a academia, que o `/clientes` já
  cobre; os contadores agregados dele continuam em `/contadores`).
- A implementação dos endpoints `/uso` no AlfaControl e do contrato completo no
  AlfaJornada mora nos repositórios deles, fora do alcance desta spec — aqui
  fica só o lado consumidor.

## Suposições

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-081 | `unidades_ativas` por cliente é a contagem na unidade de cobrança do próprio sistema: o AlfaControl manda 1 por condomínio (coerente com o `unidades_ativas` que o `/clientes` dele já manda), o AlfaJornada manda os funcionários com status `ativo` — excluindo `inativo` (soft delete) e `desligado` (demitido). | aberta | — |
| ASM-082 | O retrato de uso mora no vínculo `cliente_sistema` (três colunas), sem tabela nova e sem histórico mensal. Se a cobrança metrada precisar do uso por competência, o histórico vira feature própria. | aberta | — |
| ASM-083 | A hora da medição gravada é a do ciclo da Matriz (`uso_medido_em = now()`), não a da origem — o transporte descarta o envelope, e o retrato é sempre da última leitura bem-sucedida. | aberta | — |
| ASM-084 | As `metricas` são um mapa livre de contadores inteiros definido por cada sistema (`pessoas_ativas`, `funcionarios_ativos`, `alunos_ativos`, `dispositivos_ativos`, `cnpjs_ativos`...); a Matriz guarda o mapa como JSON sem validar as chaves, para um sistema novo acrescentar contador sem mexer aqui. | aberta | — |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-040 | O fechamento mensal do AlfaJornada deve passar a cobrar por `uso_unidades` (funcionários ativos medidos) em vez de clientes ativos? Hoje o tier metrado dele multiplicaria R$ 2,50 pelo nº de clientes, que não é a unidade anunciada. | aberta | — |
| Q-041 | A linha `alfajornada` existe na tabela `sistemas` de produção? A migração de capacidade só atualiza linha existente (mesmo padrão das capacidades anteriores); se não existir, é preciso cadastrá-lo pela tela de Sistemas antes de configurar endereço e chave. | respondida | Existia. A migração aplicou as capacidades em 15/08/2026; endereço e chave foram configurados no mesmo dia e a primeira carga entrou (2 revendas, 2 clientes, uso de 2 clientes medido). O AlfaJornada está no ciclo horário. |
