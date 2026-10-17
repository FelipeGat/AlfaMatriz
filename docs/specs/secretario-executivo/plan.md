# Implementation Plan: Secretário Executivo, Etapa 1 (núcleo)

**Spec**: [spec.md](spec.md) · **Pesquisa**: [research.md](research.md) · **Dados**: [data-model.md](data-model.md)
· **Contratos**: [contracts/](contracts/) · **Cenários**: [quickstart.md](quickstart.md) · 17/10/2026

## Summary

Itens pessoais (tarefa, lembrete, compromisso, acompanhamento) ficam em tabelas próprias por conta, com o
recurso `secretario` fora do administrador.

**Como os itens são registrados**
- Um interpretador determinístico em português transforma a frase em proposta.
- A pessoa confirma pelo Telegram (botões), pelo chat da Central ("sim") ou pelo Meu Dia.
- Só frase que as regras não entendem vai à IA dela, pelo runner, e mesmo assim volta como proposta a confirmar.

**Como os avisos chegam**
- Um comando agendado por minuto despacha os avisos no sino e no Telegram, exatamente uma vez, com tentativas.
- Isso funciona com a máquina desligada.

**O que muda no Telegram**: o bot é o mesmo e passa a receber toques em botões (`callback_query`).

## Technical Context

| Campo | Valor |
|---|---|
| Linguagem/Versão | PHP 8.2, Laravel 12.64 (`composer.lock`) |
| Dependências novas | nenhuma |
| Storage | MariaDB 10.11 em produção, SQLite `:memory:` nos testes |
| Testing | PHPUnit 11 (`tests/Feature/Secretario/`), suíte isolada `tools/central-ia/run-tests.py` |
| Target | servidor do AlfaMatriz (LXC), cron `schedule:run` por minuto e unit `alfamatriz-central-ia-telegram` |
| Frontend | Blade + Alpine + Tailwind com os tokens do `design/README.md`; ícones do `x-nav-icon` (`sun`, `bell`, `clock`, `check`) |
| Performance | aviso em até 60 s depois da hora; despacho de até 100 avisos por rodada |
| Restrições | sem worker de fila, sem IA no servidor, sem dependência nova, migração aditiva |
| Escala | 1 conta agora; desenho por `user_id` |

## Constitution Check

| Princípio | Status | Notas |
|---|---|---|
| I. Privacidade por conta | PASS | Toda consulta por `user_id` da sessão ou do vínculo; 404 para outra conta; perfil fora do admin (seeder exclui `secretario`). Teste de isolamento obrigatório. |
| II. IA na máquina da pessoa | PASS | O servidor não chama LLM; o fallback é uma execução da Central no runner. |
| III. Um sistema, um consumidor | PASS | Mesmo banco e mesma aplicação. Envio pelo bot não é consumo; `getUpdates` continua só no `central-ia:telegram`. As tabelas têm prefixo `secretario_` porque o módulo tem permissão própria. Não há segunda aplicação, que é o que o princípio proíbe. |
| IV. Segredos | PASS | Nenhum segredo novo. O texto do item não vai a log nem a evento. A resposta ao Telegram passa por `ProtecaoDeSegredos::texto`. |
| V. Prova executável | PASS | Testes por história; migração com `down()` em MariaDB e SQLite; bot reiniciado e observado depois de publicar. |
| VI. Rastro | PASS | Ações e falhas viram `Eventos::registrar`, sem título. |
| VII. Autorização externa | PASS | Nada sai para terceiros: só a própria pessoa recebe. Commit, push e deploy só com ordem dela. |
| VIII. Zero fabricação | PASS | A proposta nunca inventa data, pessoa ou cliente; sem "quando", pergunta. Os contratos estão marcados como PROPOSTA. |

**Re-check pós-design**: PASS. O design não criou processo, fila nem dependência. As duas mudanças em código
existente são aditivas e cobertas por testes atuais:
- `registrarDoAgente(entregar)`;
- `allowed_updates` com `callback_query`.

## Design

### Servidor (novo)

- `app/Models/Secretario{Item,Aviso,Proposta,Preferencia,Toque}.php`. Cada modelo tem `scopeDe(User)`, como
  `CentralIaTarefa::scopeDe`.
- `app/Services/Secretario/`:
  - `Interpretador`: puro, com relógio injetado. Devolve proposta, "falta quando" ou "não é do Secretário"
    (research D7).
  - `Recorrencia`: próxima ocorrência diária, semanal ou mensal, caindo no último dia do mês quando preciso.
  - `Propostas`: criar, confirmar (idempotente), descartar, corrigir, expirar, confirmação automática (D8).
  - `Itens`: concluir, adiar, amanhã, reagendar, cancelar (só esta / todas), com trava de linha (FR-027).
    Reprograma os avisos.
  - `Despachante`: `programar(item)` e `despachar(limite)` (D3–D6).
  - `TelegramDoSecretario`: proposta e lembrete com teclado, toque (`callback_query`) com as regras do contrato.
  - `Conversa`: a frase na Central (web ou Telegram), sim/não/correção, registro no histórico (D10–D11).
  - `PropostaDaIa`: fallback pelo runner (D9), com o bloco `<<<SECRETARIO … SECRETARIO>>>`.
- `app/Console/Commands/SecretarioDespachar.php`: `secretario:despachar`, `everyMinute()`, `withoutOverlapping()`,
  `onOneServer()`.
- `app/Http/Controllers/SecretarioController.php` e as rotas em `routes/web.php` com `permissao:secretario`
  (contrato `rotas-web.md`).
- `database/migrations/2026_10_17_100000_criar_secretario.php`.

### Servidor (alterado, aditivo)

| Arquivo | Mudança |
|---|---|
| `app/Console/Commands/CentralIaTelegram.php:152` | `allowed_updates` + `callback_query` |
| `app/Services/CentralIa/TelegramCanal.php` (`receber`/`receberNoBot`) | encaminha `callback_query`; o Secretário entra antes do canal ativo |
| `app/Http/Controllers/CentralIaController.php` (`enviar`) | Secretário depois do código de 6 dígitos |
| `app/Services/CentralIa/ConversaService.php` (`registrarDoAgente`) | parâmetro `entregar = true` |
| `app/Services/CentralIa/AgentesService.php` (`concluir`) | extrai o bloco SECRETARIO quando a execução foi pedida pelo Secretário (`roteamento.secretario`) |
| `app/Console/Commands/CentralIaLimpar` (ou equivalente de `central-ia:limpar`) | apaga `secretario_toques` com mais de 7 dias e propostas expiradas com mais de 30 dias |
| `database/seeders/PerfilPermissaoSeeder.php:107` | exclui `secretario` do admin |
| `routes/console.php` | agenda `secretario:despachar` |
| `resources/views/layouts/navigation.blade.php` | grupo "Secretário" com "Meu Dia" (`recurso => secretario`, `matriz => true`, ícone `sun`) |

### Tela

- `resources/views/secretario/meu-dia.blade.php`: Alpine; campo "Registrar…", proposta inline com
  Confirmar/Corrigir, seções Atrasados, Hoje, Próximos e Sem data, e ações em um toque.
- `resources/views/secretario/configuracoes.blade.php`: três controles.
- Mobile first: nada com largura fixa. Sem `<table>` cru, sem hex, sem SVG solto.

## Project Structure

### Documentation (this feature)

```
docs/specs/secretario-executivo/
├── auditoria.md      # Fase 0 e decisões do Felipe
├── spec.md
├── research.md
├── data-model.md
├── contracts/rotas-web.md
├── contracts/telegram.md
├── quickstart.md
├── plan.md           # este arquivo
└── tasks.md          # /cstk:create-tasks
```

### Source Code (caminhos reais; novos marcados com +)

```
app/Console/Commands/CentralIaTelegram.php
app/Console/Commands/SecretarioDespachar.php        +
app/Http/Controllers/CentralIaController.php
app/Http/Controllers/SecretarioController.php       +
app/Models/Secretario*.php                          + (5)
app/Services/CentralIa/{TelegramCanal,ConversaService,AgentesService}.php
app/Services/Secretario/*.php                       + (8)
database/migrations/2026_10_17_100000_criar_secretario.php  +
database/seeders/PerfilPermissaoSeeder.php
resources/views/layouts/navigation.blade.php
resources/views/secretario/{meu-dia,configuracoes}.blade.php  +
routes/{web,console}.php
tests/Unit/Secretario/{InterpretadorTest,RecorrenciaTest}.php  +
tests/Feature/Secretario/*.php                      +
```

## Convenções de Borda

| Camada | Estilo | Validação | Fonte da verdade |
|---|---|---|---|
| Colunas do banco | snake_case | migração | `database/migrations/2026_10_17_100000_criar_secretario.php` |
| Modelos Eloquent | atributos snake_case (padrão Laravel) | `$fillable` e `casts` | `app/Models/Secretario*.php` |
| JSON web (request/response) | snake_case, como o resto do AlfaMatriz (`central-ia.js` lê `progresso_em`, `criada_em`) | `$request->validate` | `contracts/rotas-web.md` |
| `callback_data` do Telegram | `s:<ação>:<id>` | lista fechada + dono | `contracts/telegram.md` |
| Datas na API | ISO 8601 local + texto por extenso | Carbon | `SecretarioItem::paraTela()` |

Não há camada de mapeamento: o Eloquent serializa direto.

## Ordem de entrega (fatias verticais)

1. **Fundação**: migração, modelos, permissão, seeder, menu, rotas vazias, isolamento (404).
2. **US1 (P1)**: `Interpretador` + `Propostas` + interceptação no chat web e no Telegram com teclado de proposta.
3. **US2 (P1)**: `Despachante` + `secretario:despachar` + `callback_query` + ações rápidas. **Ponto de
   publicação mínimo**: critério de aceite 1.
4. **US3 (P2)**: Meu Dia.
5. **US4 (P3)**: recorrência.
6. **US5 (P3)**: configurações e confirmação automática.
7. **Fallback IA** (US1, cenário 7): `PropostaDaIa`.

## Complexity Tracking

Nenhuma violação de princípio.
