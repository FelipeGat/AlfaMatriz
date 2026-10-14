# Project Briefing: AlfaMatriz — foco na Central de IA

**Data**: 12/10/2026
**Status**: Draft (derivado dos arquivos; validação do dono pendente)
**Versao**: 2.0 — substitui o briefing curto de 11/10, cujo registro foi
preservado no fim, em "Histórico de autorizações".

Fontes: `README.md`, `CLAUDE.md`, `.spec/constituicao.md`, `.spec/features/`,
`docs/central-ia/README.md` e os documentos de 11/10 em `docs/central-ia/`,
e as decisões ditas pelo dono no chat de 10 a 12/10/2026. Onde não há fonte,
o item foi para "Itens a Definir"; nada foi inventado.

---

## 1. Visao e Proposito

**O que e**: o AlfaMatriz é o painel interno da Alfa Tecnologia: revendas, clientes
finais, sistemas licenciados, faturamento das revendas, financeiro da própria Alfa,
quadro de tarefas de desenvolvimento, Agenda, Manutenção e servidor MCP. Não é
vendido; é a matriz que enxerga e cobra as revendas. `[inferido do README]`

A **Central de IA** é um módulo novo dentro dele (branch `Felipe`, desde 10/10/2026).
Nela cada pessoa conversa, pela tela ou pelo Telegram, com as suas IAs (Claude,
GPT/Codex, Copilot), que trabalham nos projetos dela pela máquina dela.

**Problema que resolve**: o dono trabalha em vários projetos ao mesmo tempo, com três
IAs, e perdia contexto ao trocar de IA, de projeto ou de aparelho. Precisava continuar
do celular (Telegram) o que começou no VS Code. Também não conseguia ver o consumo e
os limites de cada IA. `[fornecido]`

**Proposta de valor**: "a ideia é usar o matriz para tudo" `[fornecido]`. É um cockpit
único, com conversa, tarefas com dossiê, IA escolhida automaticamente, consumo e
limites, e um log de quem fez o quê.

## 2. Usuarios e Stakeholders

| Ator | Papel | Acoes Principais |
|------|-------|-----------------|
| Felipe | Dono do produto e primeiro usuário da Central | Pede, aprova, decide; único com perfil Central de IA hoje |
| Rossini | Admin do AlfaMatriz, dono do `.spec` (onp-spec) | Não vê a Central do Felipe; terá a própria Central, com os próprios logins de IA e a própria WSL |
| Alexandre | Admin do AlfaMatriz | Igual ao Rossini; não habilitar com o HOME nem as credenciais do Felipe |
| Agentes (Claude, Codex, Copilot) | Executores no runner da pessoa | Analisam e implementam nos projetos dela, devolvendo o dossiê |
| Runner (WSL da pessoa) | Processo `central-ia-runner` | Busca pedidos, executa os agentes, mede o git e o consumo |
| Bot do Telegram | Consumidor único (`alfamatriz-dev-telegram`) | Pareamento, mensagens nas duas mãos, comandos |

**Stakeholders de decisao**: Felipe para a Central. O restante do AlfaMatriz é
compartilhado com o Rossini, dono do processo `.spec`.

## 3. Escopo

### MVP (Essencial) — homologação local

1. Conversa privada por conta: tela e Telegram, histórico único, envio idempotente.
2. Agentes pelo runner de cada pessoa: analisar e implementar com acesso total.
3. Cockpit multiprojetos: tarefas com dossiê, troca de agente com handoff, IA
   automática, 2 execuções em paralelo.
4. Continuar sessões do VS Code (Claude por fork; Codex e Copilot pela própria sessão).
5. Consumo e limites por IA, com gráficos.
6. Busca e grupos de projetos; tela sem rolagem, em abas.
7. Log de eventos e ponto salvo, para o contexto não crescer.
8. Anexos (imagem, PDF, vídeo), avisos de status a cada 90 s e canal ativo
   tela ↔ Telegram (feitos pelo Codex em 11/10).
9. Ajustes individuais (roteamento, habilitação, fallback, limites) e arquivamento.

### Pos-MVP (Desejavel)

1. Deploy em produção, começando limpo, após a homologação de uma semana.
2. Central de outras pessoas (Rossini, Alexandre), cada uma com o próprio runner.
3. Isolamento de executores e concessões por pasta ou ferramenta, que hoje não
   são controles comprovados (`ajustes-individuais.md`).
4. Coordenação entre agentes (papéis no `papeis-felipe.md`; preparada, sem execução ativa).

### Fora de Escopo

- Segunda aplicação, PostgreSQL ou tickets entre aplicações. A `central-agentes`
  separada foi aposentada pela decisão "um sistema só".
- Credencial de IA no banco, no navegador ou no Git.
- Compra de créditos ou adaptador de API de IA: usam-se as assinaturas da pessoa.
- Importar dados da `central-agentes` antiga.

## 4. Prioridades e Trade-offs

**Ordem de prioridade** `[inferido das decisões]`: privacidade por conta > não
tocar produção > continuidade de contexto > velocidade de entrega.

**Decisoes explicitas** (dono):
- Acesso total aos agentes ("permita tudo"), compensado pelo log de eventos.
- "Aguardando aprovação" só para deploy ou quando o agente tiver dúvida.
- Roteador por regras fixas, sem consultar modelo: perguntar ao GPT custaria cota e 10–15 s.
- O cstk entra depois da validação ("vamos ignorar por enquanto o cstk, depois da
  validação rodamos ele"). Em 12/10 o dono pediu a passagem pelo cstk.
- Tudo na branch `Felipe`: "não quero outra branch".

## 5. Restricoes

| Restricao | Valor | Notas |
|-----------|-------|-------|
| Prazo | Homologação local de ~1 semana a partir de 10/10/2026 | Deploy só depois e com pedido explícito |
| Equipe | Felipe + agentes (Claude Code, Codex) | Rossini mantém o resto do AlfaMatriz |
| Budget | Assinaturas existentes de Claude, ChatGPT e Copilot | Limites de 5 h/semana; franquia premium do Copilot |
| Tecnica | Produção intocada: "nao podemos mexer la hoje" (11/10) | Sem push, deploy ou migração em produção |
| Tecnica | Banco local é cópia de produção com integrações reais | Não rodar scheduler nem `queue:work` genérico; hosts de produção bloqueados no container |
| Tecnica | Um consumidor do Telegram por bot | Dois ouvintes = 409 e mensagens perdidas |

## 6. Stack Tecnica

| Camada | Tecnologia | Justificativa |
|--------|-----------|---------------|
| Backend | Laravel 12 / PHP 8.2 | Stack do AlfaMatriz |
| Frontend | Blade + Alpine + Tailwind (tokens em `tailwind.config.js` e `app.css`) | Sem SPA; redesign com tokens próprios |
| Banco de dados | MariaDB 10.11 (testes em SQLite em memória) | Mesmo banco do AlfaMatriz (`central_ia_*`) |
| Infraestrutura | Docker Desktop na WSL2 (`alfamatriz-dev`, porta 8096); runner como serviço systemd do usuário | Cada pessoa executa na própria máquina |
| Integracoes | Telegram (long polling), CLIs Claude/Codex/Copilot, Agenda do AlfaMatriz (ou agenda pessoal configurada na máquina), Sanctum para o runner | — |

## 7. Qualidade e Padroes

**Padroes adotados**:
- Suíte isolada (`tools/central-ia/run-tests.py`): SQLite, sem rede, `.env` mascarado.
  Commit só com a suíte verde.
- Comentário explica o porquê, não o quê (`CLAUDE.md`).
- Regra compartilhada fica em serviço, não em controller nem em ferramenta MCP.
- Processo `.spec` do Rossini (onp-spec, P-001 prova executável) coexiste com o cstk.

**Compliance**: LGPD de fato, porque o banco tem dados de clientes. Sem norma formal
declarada. `[inferido]`

## 8. Visao de Futuro

**6 meses** `[inferido]`: a Central em produção para os sócios, cada um com o seu
runner, substituindo a ponte do Mac mini (`deploy/agente/`).

**12 meses**: A Definir.

**Riscos conhecidos**:
- Acesso total + Telegram: quem controla o chat pareado age na máquina do dono.
- Restaurar a cópia de produção apaga as tabelas `central_ia_*` e a conta local id 5.
- A branch `Felipe` tem centenas de commits à frente de `origin/Felipe`, sem push,
  e o merge com a `main` do Rossini não foi ensaiado.
- Dois agentes (Claude e Codex) escrevendo no mesmo worktree.

---

## Itens a Definir

| Item | Dimensao | Impacto |
|------|----------|---------|
| Data e forma do deploy da Central (produção começa limpa) | Restricoes | Alto |
| Quando e como Rossini e Alexandre recebem a Central | Usuarios | Medio |
| Confirmação humana para ações destrutivas vindas do Telegram | Qualidade | Alto |
| Push da branch `Felipe` e estratégia de merge com a `main` | Restricoes | Alto |
| Visão de 12 meses | Futuro | Baixo |

---

## Histórico de autorizações (registrado pelo Codex em 11/10/2026)

- **Tarefa 9 (anexos e atividade)**: branch Felipe, ambiente local, sem
  commit/push/merge/deploy no início. Depois vieram commits locais autorizados.
- **Tarefa 10**: exclusividade do Telegram e continuidade entre provedores; teto
  interno opcional. Timeout, checkpoint, sessões e legado ficaram pendentes por
  determinação do dono. Ver `central-ia/tarefa-10-continuidade-provedores.md`.
- **Canal ativo**: troca explícita AlfaMatriz ↔ Telegram com histórico único.
  Migração 160000 aplicada só no local, após backup cifrado e ensaio de restauração.
  88 mensagens e 1 pareamento preservados por hash. Ver `central-ia/canal-ativo.md`.
- **Ajustes individuais e arquivamento**: migração 180000 aplicada só no dev local,
  após backup AES-GCM. Nenhum serviço reiniciado nem IA executada nessa etapa. Ver
  `central-ia/ajustes-individuais.md`.
- Autorização mais recente registrada: acesso amplo inicial às IAs do Felipe. Não
  habilitar Rossini/Alexandre com o mesmo HOME/credenciais. Isolamento de executores,
  concessões por recurso e limites exatos de tokens continuam pendentes; não afirmar
  que a tela os garante.

---

**Proximo passo recomendado**: `/constitution`
