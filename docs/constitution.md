<!--
Sync Impact Report
- Version: (sem versão, 6 linhas de 11/10) → 1.0.0
- Princípios modificados: as 6 regras do Codex viraram os princípios I–VI, com
  obrigações rotuladas (MUST) e verificação; nenhuma foi enfraquecida
- Princípios adicionados: VII Autorização explícita para ações externas,
  VIII Veracidade de Dados (princípio-base do cstk)
- Seções adicionadas: Restrições de Ambiente, Fluxo de Desenvolvimento, Governance
- Seções removidas: nenhuma
- Artefatos que precisam atualização:
  - CLAUDE.md (raiz): não cita a Central; ok por ora (a constitution prevalece)
  - docs/specs/central-ia-interacao-anexos/{plan,tasks}.md: não referenciam princípios — pendente
  - docs/specs/central-ia/ (retroativa): a criar no /specify
- Convivência: `.spec/constituicao.md` (onp-spec, Rossini) segue valendo para o
  processo `.spec`; P-001 (prova executável) e P-002 (segredos) estão espelhados em V e IV.
- TODOs pendentes: TODO(CONFIRMACAO_DESTRUTIVA) em VI
-->

# AlfaMatriz Constitution

Vale para todo o repositório; os princípios I–III e VI foram escritos a partir da
Central de IA, mas valem para qualquer módulo que guarde dado de pessoa ou acione
máquina.

## Core Principles

### I. Privacidade por conta, sem exceção de administrador

Conversa, tarefa, anexo, execução, preferência, consumo e vínculo do Telegram são
de uma conta só. Administrador do AlfaMatriz não é dono da Central de ninguém
(Rossini e Alexandre são admins e não veem a do Felipe). Mesma regra das Notas (§18).

**MUST:**

- Toda consulta de dado da Central é escopada pelo dono (`user_id` da sessão, do token do runner ou do vínculo do Telegram); id vindo do cliente nunca dispensa o escopo.
- Recurso de outra conta responde 404, nunca 403 com detalhe.
- Rota da Central não recebe id de usuário nem de conversa como parâmetro de quem ela é.

**MUST NOT:**

- Conceder o perfil "Central de IA" de fábrica a administrador.

Verificação: testes de isolamento em `tests/Feature/CentralIa/*` (conta B recebe 404).

### II. Cada pessoa com as suas IAs e a sua máquina

O AlfaMatriz guarda a conversa e coordena; quem executa é o runner da pessoa, com
as credenciais de IA dela.

**MUST:**

- Executar agentes somente no runner da própria conta, autenticado por token Sanctum de capacidade `central-ia-runner`.
- Habilitar outra pessoa com HOME e credenciais próprios.

**MUST NOT:**

- Guardar credencial de IA no banco, no navegador ou no Git.

### III. Um sistema só, um consumidor só

**MUST:**

- A Central mora no banco do AlfaMatriz, em tabelas `central_ia_*`, sem segunda aplicação.
- Haver um único consumidor do Telegram por bot, com trava; um segundo consumidor recua sem ler.

**MUST NOT:**

- Rodar `schedule:run`, `schedule:work` ou `queue:work` genérico contra o banco local, que é cópia de produção com integrações reais.

### IV. Segredos fora de código, log e prompt

**MUST:**

- Tokens (Telegram, runner, Sanctum), senhas e `APP_KEY` vêm de ambiente ou arquivo 0600 fora do Git.
- O token do bot nunca aparece em log, exceção ou mensagem; token de runner aparece uma vez, na emissão, e no banco fica só o hash.
- Conteúdo mandado a um agente ou ao Telegram passa por proteção de segredos quando puder conter `.env` ou credencial.

Verificação: `.spec` P-002, padrão proibido `(api[_-]?key|senha|password)\s*(=>|=)\s*['"][^'"]{8,}` em `app/**/*.php`.

### V. Prova executável antes de "pronto"

**MUST:**

- Toda mudança de regra traz teste; a suíte isolada (`tools/central-ia/run-tests.py`: SQLite, sem rede, `.env` mascarado) roda verde antes do commit.
- Migração é aditiva, roda em MariaDB e em SQLite, e tem `down()`.
- Comportamento de processo longo (bot, runner) só conta como entregue depois de reiniciado e observado.

**MUST NOT:**

- Commitar com teste vermelho, nem marcar tarefa concluída sem a prova.

### VI. Acesso total com rastro

Decisão do dono (10/10/2026): "permita tudo". Em troca, tudo fica registrado.

**MUST:**

- Toda execução e toda falha geram evento em `central_ia_eventos` (quem, agente, projeto, origem, erro), só por acréscimo.
- Commit, push e deploy só quando o pedido disser; o prompt do agente leva essa regra.
- Pausar em "aguardando você" apenas para deploy ou dúvida do agente.

TODO(CONFIRMACAO_DESTRUTIVA): ação destrutiva pedida pelo Telegram (apagar, `push --force`, produção) ainda não exige confirmação extra. Decisão do dono pendente.

### VII. Autorização explícita para ações externas

**MUST:**

- Commit, branch nova, push, PR, deploy e qualquer escrita em produção exigem autorização explícita e pontual do dono; a autorização não se estende à ação seguinte.
- Todo o trabalho da Central fica na branch `Felipe`.

**MUST NOT:**

- Rodar `db:seed` em produção; dado que precisa valer lá vai em migração.

### VIII. Veracidade de Dados — Zero Fabricação (NON-NEGOTIABLE)

**MUST:**

- Nenhum artefato pode conter dado factual inventado. Assinaturas de request/response, URLs/endpoints/querystrings e valores concretos (financeiros, quantidades, status, IDs, datas, resultados de API, consumo e limites de IA) só podem ser escritos se vierem de fonte rastreável (código, doc oficial, resposta real observada, registro local da IA). Esgotadas as fontes, a ação correta é bloqueio humano, nunca suposição plausível. Plausibilidade não é veracidade.
- Respostas sobre agenda e dados do negócio usam a fonte oficial entregue no prompt (`FontesDeDados`), e a tela mostra "sem dado" em vez de estimar.

## Restrições de Ambiente

- Desenvolvimento local em `alfamatriz-dev` (porta 8096) montando este worktree; banco MariaDB na 3311.
- O container `app` bloqueia os hosts de produção e o `api.telegram.org`; só o container do bot fala com o Telegram.
- Restaurar a cópia de produção apaga as tabelas `central_ia_*` e a conta local id 5: salvá-las antes.
- UI: Blade + Alpine + Tailwind com os tokens existentes; nada de hex solto, `<table>` cru ou SVG fora do `x-nav-icon` (testes do redesign cobram).

## Fluxo de Desenvolvimento

- Frente nova: `/cstk:specify → clarify → plan → create-tasks → execute-task`; correção pontual: `/cstk:bugfix`.
- Dois agentes (Claude Code e Codex) trabalham neste worktree: nenhum mexe em arquivo não commitado do outro.
- Processos longos (bot, runner) recebem código novo só por reinício em ociosidade; o runner instalado fica em `~/.local/share/alfa/`.

## Governance

Esta constitution prevalece sobre o `CLAUDE.md`; onde divergirem, o `CLAUDE.md` é
corrigido. Emendas: MAJOR remove ou enfraquece princípio, MINOR acrescenta, PATCH
esclarece; toda emenda atualiza o Sync Impact Report. Exceção a um MUST exige
decisão escrita do dono, registrada no doc da feature com data.

**Version**: 1.0.0 | **Ratified**: 2026-10-11 | **Last Amended**: 2026-10-12
