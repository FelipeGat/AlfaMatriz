# Implementation Plan: o Pedro acompanha a operação

**Feature**: `pedro-inteligencia-operacional` | **Date**: 31/10/2026 | **Spec**: `spec.md` (Clarified)

Plano pedido pelo Felipe em 31/10/2026, 20:34 ("Sim, tudo"), depois de aprovar as três decisões (FR-011, FR-012,
FR-016) e as quatro leituras delas. Nada aqui foi implementado nem publicado.

## Summary

O Pedro passa de consulta a acompanhamento sem integração nova: o **retrato** do Gestor, que a máquina do dono já
lê a cada 10 min, ganha o histórico das OS abertas, a última atividade e as marcas de parado/atrasado da própria
Central, os usuários internos com os perfis e os POPs publicados. A **gravação** usa o mesmo caminho das aberturas e
da agenda (fila no Matriz → máquina → script PHP na ponte do Gestor), com duas filas novas — andamentos e POPs —,
chave única nas duas pontas, autor real (o usuário de quem relatou) e a origem marcada no Gestor. Propostas
(andamento de técnico sem vínculo e POP) esperam validação em Suporte › Pedro, no padrão da Base do suporte. A
autorização passa a ser o **menor** entre o perfil da lista da casa e o teto dos perfis do usuário vinculado no Gestor,
ligada por uma chave de virada para não derrubar ninguém no meio. Detalhes e alternativas: `research.md`.

## Technical Context

**Language/Version**: PHP 8.2 / Laravel 12 nos dois sistemas (Matriz: este repositório; Gestor: `~/projetos/gestor`);
Node na máquina do dono (`deploy/central-ia/*.mjs`, instalado em `~/.local/share/alfa/`).
**Primary Dependencies**: as que existem — Sanctum (`central-ia-runner`), a ponte do Gestor (`CENTRAL_IA_GESTOR`,
script PHP no container `gestor_php` carregando o `bootstrap/app.php` dele), `claude -p` sem ferramentas para o Pedro.
Nenhuma dependência nova.
**Storage**: MariaDB do Matriz (tabelas `suporte_*`), MySQL do Gestor (`atendimento_andamentos`,
`treinamento_procedimentos`), disco `public` do Gestor para fotos.
**Testing**: Matriz — PHPUnit em `tests/Feature/Suporte/` + suíte isolada `tools/central-ia/run-tests.py`; testes da
máquina `tools/central-ia/test-suporte.mjs`; bateria de IA `tools/central-ia/bateria-pedro/` com cenários novos.
Gestor — suíte `Integracao` (`DatabaseTransactions`, banco `gestor_alfa_test`), `pint`, QA independente.
**Target Platform**: produção do Matriz (tag pelo `matriz-publicar.sh`), produção do Gestor
(`gestor-publicar-main.sh`), máquina do dono (instalador da produção).
**Project Type**: dois web-services Laravel + um runner Node.
**Performance Goals**: o retrato continua cabendo na volta de 10 min e no `maxBuffer` de 16 MiB da leitura
(`suporte.mjs`); a resposta do Pedro continua num pedido só à IA.
**Constraints**: só o "sim" grava; nenhuma nova tentativa automática de gravação; nada de mensagem por iniciativa do
Pedro (janela de 24 h); nenhum dado novo para quem não é da casa (FR-018); migrações aditivas.
**Scale/Scope**: retrato com teto por lista (atendimentos 5 andamentos e 5 etapas por OS aberta; 500 usuários; 500
POPs). Os volumes reais de produção não foram lidos para este plano: a primeira tarefa da Fase A mede o tamanho do
retrato novo contra a cópia local do Gestor antes de publicar.

## Constitution Check

*GATE antes do Phase 0 e re-checado depois do Phase 1 (seção no fim).*

### Matriz (`docs/constitution.md` 1.0.0)

| Princípio | Status | Notas |
|---|---|---|
| I. Privacidade por conta | PASS | A fila e as propostas são do Pedro (conta dona, `exigirConta()` em toda rota do runner); a tela de propostas fica sob `permissao:suporte,editar` + a regra de quem valida (research.md, Decision 7). |
| II. Cada um com a sua máquina | PASS | Grava só a máquina do dono, pelo token `central-ia-runner` que já existe. |
| III. Um sistema só | PASS | Tabelas novas no banco do Matriz; nenhuma aplicação nova; nenhum agendador novo. |
| IV. Segredos | PASS | O retrato de usuários leva e-mail, nome e perfis, nunca senha, token ou hash. Sem credencial nova. |
| V. Prova executável | PASS | Cada regra com teste; migrações aditivas com `down()`, testadas em MariaDB (dev) e SQLite (isolada); a máquina só conta depois do instalador e de uma gravação observada. |
| VI. Acesso total com rastro | PASS | Filas não apagáveis com quem relatou, quem confirmou, chave e resultado (FR-020). |
| VII. Autorização | PASS | Commit, tag e publicação só com pedido explícito, em três publicações separadas (Gestor, Matriz, instalador). |
| VIII. Zero fabricação | PASS | Contratos existentes citados da fonte; os novos marcados `[PROPOSTA]`; a resposta do Pedro só com o que o retrato traz (FR-002). |

### Gestor (`~/projetos/gestor/docs/constitution.md`)

| Princípio | Status | Notas |
|---|---|---|
| I. Zero fabricação | PASS | Idem. |
| II. O banco é produção | PASS | Só colunas novas e nulas (`origem`, `matriz_chave`, `confirmado_por_user_id`, `corrige_andamento_id`; `treinamento_procedimentos.matriz_chave`); testes novos na suíte `Integracao`. |
| III. Quem escreve não aprova | PASS (com tarefa) | Cada fase do Gestor passa por QA independente (Copilot) até `APROVADO` antes do commit. |
| IV. Paridade desktop ↔ app | PASS (com tarefa) | `App:` — a lista de andamentos da API v1 passa a trazer `origem` e `confirmado_por`; campo desconhecido é ignorado pelo app (degrada com segurança). O `andamentoStore` do app passa pelo mesmo serviço. |
| V. Autorização para Git e ambiente | PASS | Idem Matriz VII. |
| VI. Camadas e fronteiras | PASS | Regra do andamento num serviço (`AndamentoDeAtendimentoService`) chamado pelo app e pelo script; parado/atrasado em escopos do model, usados pela Central e pelo leitor; validação em FormRequest no app. |
| VII. Multi-empresa | N/A | Nenhum documento a cliente; o POP mantém `empresa_id`. |

## Project Structure

### Documentation (this feature)

```
docs/specs/pedro-inteligencia-operacional/
├── spec.md
├── plan.md            # este arquivo
├── research.md        # decisões 1 a 11
├── data-model.md
├── quickstart.md      # cenários de prova
└── contracts/
    ├── retrato.md     # o que o leitor passa a mandar
    └── gravacoes.md   # filas, rotas do runner e scripts da ponte
```

### Source Code

```
# Matriz (este repositório)
app/Services/Suporte/
├── AcessoInterno.php          # perfil tecnico, vínculo, nível efetivo, dados por nível, oQueFaz
├── DiretorioGestor.php        # gravar/retrato com usuarios e pops; histórico, parados, repetição
├── Contexto.php               # blocos HISTÓRICO, PARADOS, POPs, REPETIÇÃO; regras e ações novas
├── Pedro.php                  # concluir: previa/confirmar andamento e POP
├── Andamentos.php             # [novo] prévia, vínculo com a OS, parecido em 24 h, fila e retorno
└── Pops.php                   # [novo] prévia, proposta, aprovação, fila e retorno
app/Models/SuporteGestorAndamento.php, SuportePopProposta.php     # [novos]
app/Http/Controllers/SuportePedroRunnerController.php             # rotas da máquina
app/Http/Controllers/SuporteConhecimentoController.php            # lista com vínculo, chave de virada
app/Http/Controllers/SuportePropostasController.php               # [novo] validar/recusar/aprovar
resources/views/suporte/pedro.blade.php (+ aba de propostas)
routes/central-ia.php, routes/web.php
database/migrations/                                              # filas, prévias, colunas do retrato
deploy/central-ia/
├── suporte_gestor_ler.php     # retrato ampliado
├── suporte_gestor_andamento.php   # [novo]
├── suporte_gestor_pop.php         # [novo]
├── suporte.mjs, runner.mjs        # gravarAndamentos, gravarPops
tests/Feature/Suporte/          # testes novos por fase
tools/central-ia/test-suporte.mjs, bateria-pedro/

# Gestor (~/projetos/gestor)
app/Models/Atendimento.php                     # scopeParados, scopeAtrasados
app/Models/AtendimentoAndamento.php            # fillable novos, relações confirmadoPor/corrige
app/Services/AndamentoDeAtendimentoService.php # [novo]
app/Http/Controllers/CentralController.php     # passa a usar os escopos
app/Http/Controllers/Api/V1/Admin/AtendimentoController.php   # andamentoStore pelo serviço; origem na lista
resources/views/atendimentos/edit.blade.php    # "pelo Pedro (WhatsApp)" e quem confirmou
database/migrations/                           # colunas novas
tests/Integracao/
```

**Structure Decision**: nenhuma pasta nova além de `contracts/`; dois serviços novos no Matriz (`Andamentos`, `Pops`)
para não inchar `Pedro.php` e `AcessoInterno.php`, no mesmo lugar dos que já existem (`AgendaDoTecnico`,
`Atendimento`); um serviço novo no Gestor, porque o princípio VI dele exige regra em Service.

## Convenções de Borda

| Camada | Estilo | Validação | Fonte da verdade |
|---|---|---|---|
| Colunas (MySQL/MariaDB) | snake_case | migração | `database/migrations/*` de cada sistema |
| Retrato (JSON do leitor) | snake_case | regras item a item em `SuportePedroRunnerController::gestor` | `contracts/retrato.md` |
| Rotas do runner | `suporte/gestor/<recurso>` em kebab/minúsculas; nomes `suporte.runner.*` | `maquina()`, `exigirConta()`, `validate` | `routes/central-ia.php` |
| Carga e saída dos scripts da ponte | snake_case; uma linha `SUPORTE_<COISA>:{json}` | conferida no script e no `suporte.mjs` | `contracts/gravacoes.md` |
| Bloco da IA (`<<<PEDRO … PEDRO>>>`) | snake_case | `Contexto::ler` (ações aceitas) | `Contexto.php` |

**Mapeamento**: sem ORM entre sistemas; o leitor monta arrays, o Matriz os guarda como JSON. Ids do Gestor nunca são
confiados vindos da IA: toda OS, usuário e POP é conferido contra o retrato antes da prévia e de novo no Gestor antes
de gravar.

## Fases de entrega

Cada fase termina com testes verdes nos dois lados, sem publicar; publicar é pedido à parte (Matriz VII, Gestor V).

| Fase | Entrega | Stories / FR | Sistemas |
|---|---|---|---|
| A | Retrato ampliado: histórico, última atividade, parados/atrasados (escopos da Central), técnicos de visita, usuários, POPs. Respostas de histórico e pendências (só leitura). | US1, US2; FR-001–004, FR-019 (o histórico só entra no pedido da casa; o cliente segue a trava de preço da `pedro-comunicacao`, FR-020) | Gestor (escopos) → Matriz → máquina |
| B | Vínculo e níveis: 4º campo da lista, perfil tecnico, nível efetivo, chave de virada, tela com linhas sem vínculo, dados do técnico. | US4; FR-012–013, FR-018 | Matriz → máquina (usuários vêm na A) |
| C | Andamento: colunas e serviço no Gestor, origem na tela e no app; fila, prévia, "sim", parecido em 24 h, correção. | US3; FR-005–011c, FR-020 | Gestor → Matriz → máquina |
| D | Propostas de andamento: aba em Suporte › Pedro, validar/recusar. | FR-011a | Matriz |
| E | POPs: consulta pelos publicados, proposta, aprovação, publicação com conferência de versão. | US5; FR-014–016b | Gestor (coluna) → Matriz → máquina |
| F | Repetição. | US6; FR-017 | Matriz |

A bateria de IA ganha, por fase, os cenários do quickstart com dados fictícios; a mudança de comunicação é aprovada
pela bateria antes e depois, como na `pedro-comunicacao`.

## Riscos

- **Tamanho do retrato:** sem volume de produção medido. Mitigação: tetos por lista e a medição da Fase A antes de
  publicar.
- **Vínculo derrubando acesso:** mitigado pela chave de virada (o dono liga depois de vincular as linhas).
- **"Gestor autorizado" mais largo que a tela web:** a web deixa qualquer usuário interno registrar andamento; o Pedro
  segue a regra mais estrita da API do app (admin, administrativo, coordenador). Se o Felipe quiser outra, muda
  só o mapa de níveis.
- **Divergência dos cancelados na Central:** o Pedro os tira; a Central continua contando até a frente própria.

## Complexity Tracking

Sem violação de princípio a justificar.

## Re-check da constitution (pós-design)

Revisto depois do data-model e dos contratos: nenhuma coluna existente muda; nenhum segredo trafega (o leitor de
usuários foi limitado a e-mail, nome, ativo, perfis e pastas); nenhuma aplicação, agendador ou credencial nova;
dois serviços novos no Matriz e um no Gestor, todos dentro de pastas que já existem. Continua PASS.
