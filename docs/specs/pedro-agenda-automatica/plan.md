# Implementation Plan: o Pedro comunica a agenda aos técnicos e aos gestores pelo WhatsApp

**Feature**: `pedro-agenda-automatica` | **Date**: 02/11/2026 | **Spec**: `spec.md` (Clarified)

Plano pedido pelo Felipe em 02/11/2026 ("Sim, seguir para o plano"), depois do clarify com as três decisões (FR-003,
FR-004, FR-021). Nada aqui foi implementado nem publicado. A spec deixou para o plano três pontos — o canal do aviso aos
gestores, quem é o administrador autorizado e a retenção do registro — e eles estão resolvidos em `research.md`
(Decisions 15, 16 e 20). Essas três e a recuperação da verificação perdida à noite (Decision 11) foram **confirmadas
pelo Felipe em 02/11/2026**; as tarefas estão em `tasks.md`.

## Summary

Tudo o que manda mensagem roda no **Gestor**, que já tem a agenda, o agendador, o token da Meta, o webhook de situação
e a decisão Pedro/menu. Um comando a cada minuto (`whatsapp:agenda-automatica`) faz as três verificações do dia nos
horários configurados (06:30, 16:30, 17:00), recupera a verificação perdida no mesmo dia e **compara a programação
atual de cada destinatário com a última comunicada** para avisar só a mudança, depois de 2 min estável e fora do
silêncio das 20:00 às 06:30. A comparação substitui o observer da visita, que não vê os técnicos adicionais, e é pareada
por técnico + atendimento + dia, porque o reagendamento da Agenda Técnica apaga e recria as visitas. Cada aviso nasce
como linha com chave única (destinatário, dia, tipo, versão, modo) antes de chamar a Meta: sai uma vez só, mesmo com
reexecução. Dentro da janela de 24 h vai texto livre; fora, um de quatro modelos UTILITY, ainda a criar. A resposta do
técnico é interceptada no `MenuBot`, antes do Pedro e do menu. O **AlfaMatriz** ganha a aba Suporte › Agenda
automática (destinatários, horários, modo, prévia e supervisão), que fala com o Gestor por portas novas da integração
que já existe. Nasce desligada; o envio real exige o modo `ligada` **e** produção.

## Technical Context

**Language/Version**: PHP 8.2+ / Laravel 12 nos dois sistemas (`composer.json` do Gestor: `"php": "^8.2"`,
`laravel/framework ^12.0`; o plano `whatsapp-pedro` do Gestor registra PHP 8.4 no container).
**Primary Dependencies**: as que existem — `WhatsAppCloudService` (Graph API da Meta, `config('services.meta.graph_version')`),
`spatie/laravel-activitylog` ^4.12, a integração Matriz ↔ Gestor (`AutenticaIntegracaoMatriz`, `MATRIZ_PEDRO_TOKEN` /
`SUPORTE_GESTOR_TOKEN`). **Nenhuma dependência nova.**
**Storage**: MySQL 8 do Gestor — quatro tabelas novas (`agenda_destinatarios`, `agenda_avisos`,
`agenda_verificacoes`, `agenda_observacoes`) e a chave `agenda_automatica` em `app_settings`. Matriz: nenhuma tabela.
**Testing**: Gestor — Pest 3, suíte `Integracao` (`DatabaseTransactions`, `gestor_alfa_test`), `Http::fake` para a
Meta, `pint`, QA independente (Copilot) por fase. Matriz — `tests/Feature/Suporte/` com `Http::fake` do Gestor e a
suíte isolada (`tools/central-ia/run-tests.py --central-root …/central-agentes`).
**Target Platform**: produção do Gestor (`gestor-publicar-main.sh`) e do Matriz (tag pelo `matriz-publicar.sh`). Sem
instalador: nada roda na máquina da Central (FR-027).
**Project Type**: dois web-services Laravel.
**Performance Goals**: o ciclo de minuto termina bem antes do minuto seguinte (`withoutOverlapping`); aviso de mudança
em até 10 min depois de gravado (SC-003: 2 min de estabilidade + 1 min de ciclo + tentativas).
**Constraints**: nenhuma mensagem real antes da autorização do dono (SC-006); envio real só em produção; modelo só
fora da janela; nenhuma variável de modelo com quebra de linha; corpo preenchido ≤ 1024 caracteres por parte;
migrações aditivas; nenhuma gravação em `funcionarios` (spec, Contexto).
**Scale/Scope**: agenda de 03/11 (lida em 02/11): 7 visitas, 6 técnicos responsáveis; 7 pessoas no documento (5
técnicos, 2 gestores). Dezenas de mensagens por dia.

## Constitution Check

*GATE antes do Phase 0 e re-checado depois do Phase 1 (seção no fim).*

### Gestor (`~/projetos/gestor/docs/constitution.md` 1.0.0) — onde está quase todo o código

| Princípio | Status | Notas |
|---|---|---|
| I. Zero fabricação | PASS | Fatos do código com arquivo:linha e da Meta com a URL oficial (`research.md`); portas, tabelas e textos novos marcados **[PROPOSTA]**; padrões nossos marcados "(padrão)"; o volume de produção não foi medido além da leitura de 02/11. |
| II. O banco é produção | PASS | Só tabelas novas; testes na `Integracao`; nenhum `migrate:fresh`/`TRUNCATE`; envio real travado fora de `APP_ENV=production`, porque o banco local é espelho com os celulares reais (Decision 13). |
| III. Quem escreve não aprova | PASS (com tarefa) | Cada fase do Gestor tem `.qa-diff-agenda-automatica.md` com o escopo e passa pelo Copilot até `APROVADO` antes do commit. |
| IV. Paridade desktop ↔ app | PASS | `App:` não se aplica — nenhuma tela nem API v1 nova ou mudada no Gestor; a comparação lê as visitas gravadas por qualquer caminho, inclusive o app, e o `MenuBot` é o mesmo para tela e app. Registrado no commit. |
| V. Autorização para Git e ambiente | PASS | Commit, publicação, criação dos modelos na Meta e ativação: cada um com autorização pontual. |
| VI. Camadas e fronteiras | PASS | Regra em Services (`app/Services/WhatsApp/AgendaAutomatica/*`); validação das portas em FormRequest (`app/Http/Requests/IntegracaoMatriz/Agenda*`); autenticação no middleware que já existe; comentários com o porquê; `pint`. Não há Policy no projeto: a autorização de quem administra é a do Matriz, e a porta só aceita o segredo da integração, como as outras. |
| VII. Multi-empresa | N/A | Nenhum documento a cliente; a mensagem é interna, à equipe do grupo. |
| Segurança e segredos | PASS | Nenhum segredo novo; o número sai inteiro só para a Meta; ao Matriz e ao log, só o final; retenção de 365 dias (Decision 20). |

### Matriz (`docs/constitution.md` 1.0.0)

| Princípio | Status | Notas |
|---|---|---|
| I. Privacidade por conta | PASS | A aba é do Pedro: mudar é só de quem administra o Pedro (a conta dona e `services.suporte_pedro.administradores`, Decision 16 revista em 06/11/2026), ver é `permissao:suporte,ler`, como o resto do Suporte. Nada da Central. |
| II. Cada um com a sua máquina | N/A | Nada roda no runner. |
| III. Um sistema só | PASS | Nenhuma aplicação nova; nenhum agendador no Matriz (o relógio é o do Gestor). |
| IV. Segredos | PASS | Usa o segredo da integração que já existe; nada novo em código, log ou prompt. |
| V. Prova executável | PASS | Testes da aba com o Gestor falsificado; suíte isolada verde antes do commit; sem migração. |
| VI. Acesso total com rastro | PASS | Toda mudança vai ao Gestor com o autor e fica no `activity_log`; os envios ficam em `agenda_avisos`. |
| VII. Autorização | PASS | Publicação e ativação só com pedido explícito. |
| VIII. Zero fabricação | PASS | Idem Gestor I; a aba mostra "sem dado" quando o Gestor não responde, sem estimar. |

## Project Structure

### Documentation (this feature)

```text
docs/specs/pedro-agenda-automatica/
├── spec.md
├── plan.md                      # este arquivo
├── research.md                  # fatos lidos e Decisions 1 a 20
├── data-model.md                # tabelas do Gestor, configuração, transições
├── quickstart.md                # cenários 1 a 10
└── contracts/
    ├── integracao-agenda.md     # portas Matriz → Gestor
    └── mensagens.md             # textos livres e os quatro modelos
```

### Source Code

```text
# Gestor (~/projetos/gestor)
app/Services/WhatsApp/AgendaAutomatica/                  # [novo]
├── Configuracao.php            # app_settings 'agenda_automatica', modo, horários, silêncio, envio real
├── Destinatarios.php           # destinatários, número do cadastro, pendências, nunca_enviar, quem é técnico ativo
├── Programacao.php             # compromissos do dia por técnico (responsável + pivô), forma canônica e versão
├── Comparacao.php              # pareamento e classificação das mudanças (Decision 3)
├── Mensagens.php               # textos livres, variáveis dos modelos, partes ≤ 1024
├── Envio.php                   # janela ou modelo, conferência do modelo, linha antes da chamada, tentativas
├── Relatorio.php               # contagens, conflitos, pendências
├── Ciclo.php                   # o minuto: verificações devidas, mudanças estáveis, tentativas
└── RespostaDoTecnico.php       # FR-021 e "parar"
app/Services/WhatsApp/WhatsAppCloudService.php           # + envio que devolve wamid ou o erro da Meta
app/Services/WhatsApp/MenuBot.php                        # + o desvio da resposta do técnico (Decision 14)
app/Http/Controllers/WhatsappWebhookController.php       # processarStatus: + situação do aviso
app/Http/Controllers/Api/IntegracaoMatrizAgendaController.php   # [novo] as 7 portas
app/Http/Requests/IntegracaoMatriz/Agenda*.php           # [novo] validação das portas
app/Models/AgendaDestinatario.php, AgendaAviso.php, AgendaVerificacao.php, AgendaObservacao.php   # [novos]
app/Console/Commands/WhatsappAgendaAutomatica.php        # [novo] whatsapp:agenda-automatica
routes/console.php                                       # everyMinute()->withoutOverlapping()->onOneServer()
routes/api.php                                           # integracao/matriz/agenda/*
config/whatsapp.php                                      # portal_url, nunca_enviar, estável, margem, tentativas
database/migrations/                                     # as quatro tabelas
tests/Integracao/AgendaAutomatica*Test.php               # [novos] por fase
docs/specs/whatsapp-pedro/contracts/integracao-matriz.md # + seção 8, apontando para o contrato desta feature

# Matriz (este repositório)
app/Services/Suporte/AgendaAutomatica.php                # [novo] cliente das portas (Http, timeout, motivo)
app/Http/Controllers/SuporteAgendaAutomaticaController.php   # [novo] aba: ver, mudar (só quem administra o Pedro), prévia
resources/views/suporte/agenda-automatica.blade.php      # [novo] Blade + Alpine + tokens; ícone `calendar`
resources/views/suporte/_abas.blade.php                  # + aba "Agenda automática"
routes/web.php                                           # suporte/agenda-automatica (permissao:suporte,ler|editar)
tests/Feature/Suporte/AgendaAutomaticaTest.php           # [novo]
docs/specs/suporte-pedro-whatsapp/validacao-em-producao.md   # + bloco da agenda automática (Fase G)
```

**Structure Decision**: o Gestor ganha uma pasta dentro de `app/Services/WhatsApp/`, ao lado do `MenuBot` e da
`IntegracaoMatriz`, porque o assunto é o canal do Pedro e as peças se chamam entre si; um controller novo para as
portas, em vez de inchar o `IntegracaoMatrizWhatsappController`, que trata de conversa. No Matriz, um serviço e um
controller, no padrão de `SuporteConhecimentoController` e `CanalWhatsApp`.

## Convenções de Borda

| Camada | Estilo | Validação | Fonte da verdade |
|---|---|---|---|
| Colunas (MySQL) | snake_case | migração | `database/migrations/*` do Gestor; `data-model.md` |
| Portas Matriz → Gestor (JSON) | snake_case; datas `AAAA-MM-DD`, horas `HH:MM`, instantes ISO 8601 | FormRequest no Gestor; `validate` e checagem de chaves no cliente do Matriz | `contracts/integracao-agenda.md` |
| URL | `api/integracao/matriz/agenda/<recurso>` em minúsculas; nomes `integracao.matriz.agenda.*` | `routes/api.php` | `routes/api.php` do Gestor |
| Tela do Matriz | rotas `suporte/agenda-automatica[/...]`, nomes `suporte.agenda-automatica.*` | `permissao:` + `AcessoInterno::administra` | `routes/web.php` deste repositório |
| Modelos da Meta | nomes snake_case; variáveis posicionais `{{n}}` | conferidos em `templatesAprovados` antes de cada uso | `contracts/mensagens.md` |

**Mapeamento**: sem ORM entre os sistemas. O Gestor monta arrays nas portas; o Matriz não guarda nada e mostra o que
recebe. Ids de funcionário vindos do Matriz são sempre conferidos contra `funcionarios` (ativo, não apagado). Nenhum
número completo atravessa a borda.

## Fases de entrega

Cada fase termina com testes verdes nos dois lados, `pint` e QA (Gestor), sem publicar; publicar é pedido à parte. O
Gestor publica antes do Matriz: a aba chama portas que precisam existir.

| Fase | Entrega | FR / Stories | Sistema |
|---|---|---|---|
| A | Base: tabelas, configuração (`desligada`), destinatários e pendências, programação e versão, textos, ciclo de minuto com as verificações devidas e a prova de execução, ensaio e prévia, as 7 portas, teste de contrato. Nenhuma chamada à Meta ainda. | FR-001–003, 005–011, 024–025, 028-INFRA-SCHED, 029, 030; US1 em ensaio; US4 (lado Gestor) | Gestor |
| B | Aba Suporte › Agenda automática: estado, destinatários com nome e final, consentimento, horários, modo, prévia, envios; só quem administra o Pedro muda. | FR-002, 005, 025, 028; US4 | Matriz |
| C | Envio real: janela ou modelo, conferência do modelo e da categoria, erro da Meta, linha antes da chamada, tentativas, situação pelo webhook, `nunca_enviar`, trava de produção. | FR-004, 022, 024, 026, 027; SC-002, SC-006 | Gestor |
| D | Mudanças: comparação pareada, estabilidade, silêncio, verificação das 06:30. | FR-012–016; US2; SC-003 | Gestor |
| E | Relatório das 17:00: contagens, conflitos, pendências, dia vazio, partes. | FR-017–020; US3; SC-004 | Gestor |
| F | Resposta do técnico e "parar" no `MenuBot`; alerta aos gestores. **Precisa estar no ar antes de qualquer mensagem real a técnico.** | FR-006, 021, 023; SC-007 | Gestor |
| G | Fora do código, cada passo com autorização do dono: textos dos modelos aprovados → criação na Meta → cadastros de Cristiano, Vitor, Alefe e Wallas corrigidos no Gestor → destinatários e consentimentos na aba → ensaio em produção → `ligada` com números controlados → validação (quickstart, cenário 10). | SC-001–007 | Dono + Gestor + Matriz |

As fases A, C, D, E e F do Gestor podem sair em publicações separadas sem risco: com o modo `desligada`, nada sai, e a
F só muda o que acontece quando um técnico **destinatário ativo** escreve — e não há nenhum até a Fase G.

## Riscos

- **O agendador do host.** O `schedule:run` do Gestor não está no repositório. Mitigação: a Fase A só termina quando a
  linha da verificação aparece em produção com o modo `desligada` (quickstart, cenário 7).
- **Modelos recusados ou recategorizados.** A proporção de variáveis (2388293) não tem número na doc, e a Meta pode
  tornar MARKETING. Mitigação: o código só preenche variáveis (texto fixo muda sem código); conferência da categoria
  antes de cada uso; sem modelo, nada sai fora da janela e a falta aparece.
- **Custo.** Desde 01/10/2026 o modelo UTILITY é cobrado também dentro da janela; por isso o livre dentro dela. O valor
  por mensagem não foi lido: a Fase G mede na supervisão quantos modelos saem por dia antes de ampliar.
- **Número do plantão.** Se o 6889 entrar como celular do Alefe, quem estiver com o aparelho do plantão recebe a
  programação dele e, ao responder, ouve a resposta fixa em vez do atendimento de cliente (spec, Edge Cases). Decisão
  do dono na correção do cadastro.
- **Celular compartilhado ou trocado.** Tratado como pendência nomeada (Decision 17); o "parar" fica preso ao
  destinatário, não ao número.
- **Resposta do técnico que é, de fato, assunto de cliente.** Ele recebe a frase fixa e o gestor é avisado com o texto;
  atender o técnico de verdade fica para o perfil técnico de `pedro-inteligencia-operacional`.

## Complexity Tracking

Sem violação de princípio a justificar.

## Re-check da constitution (pós-design)

Revisto depois do `data-model.md` e dos contratos. Gestor: quatro tabelas novas e nenhuma coluna existente alterada
(II); toda regra numa pasta de Services, porta com FormRequest e middleware existente (VI); nenhuma API v1 nem tela
do app tocada, e a leitura das visitas cobre o que o app grava (IV); o `WhatsAppCloudService` ganha um método sem mudar
os que o menu, a cobrança e a integração usam; QA por fase (III). Matriz: nenhuma tabela, nenhum agendador, nenhuma
credencial nova; a aba segue a regra de quem já administra o Pedro (I, III, IV). A comparação a cada minuto substituiu o
observer — uma peça a menos, não a mais. Continua **PASS** nas duas constituições.
