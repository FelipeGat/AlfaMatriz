# Implementation Plan: Central de IA — correções de 12/10 e FR-022 a FR-029

**Spec**: [spec.md](spec.md) · **Revisão**: [revisao-2026-10-12.md](revisao-2026-10-12.md) ·
**Research**: [research.md](research.md) · **Contrato**: [contracts/confirmacao.md](contracts/confirmacao.md)

## Summary

Seis frentes, cada uma fechada com a suíte verde e um commit local na branch `Felipe`:

- **A. Segurança da conta**: admin não altera conta com Central; governança só do dono; tirar o admin do Alexandre no banco local.
- **B. Runner inviolável**: teto local, pastas validadas, análise só leitura de verdade, confirmação fora de banda para implementar.
- **C. Bugs da revisão**: os ALTO e MÉDIO de corretude, mais o trabalho não commitado do Codex.
- **D. Custo**: níveis sem IA / leve / médio / forte, com o modelo escolhido por nível.
- **E. UI e UX**: testar na tela, corrigir a navegação, a leitura das respostas, escolher e delegar.
- **F. Ativação local**: runner reinstalado, nginx corrigido, teste real ponta a ponta.

## Technical Context

| Campo | Valor |
|---|---|
| Language | PHP 8.2 (Laravel 12); Node 18+ no runner, sem dependências |
| Storage | MariaDB 10.11 (dev), SQLite em memória (testes) |
| Testing | `tools/central-ia/run-tests.py` (PHPUnit isolado, sem rede) + `tools/central-ia/test-*.mjs` + Playwright em Docker para tela |
| Platform | Docker Desktop/WSL2 (`alfamatriz-dev`, 8096); runner `systemctl --user` |
| Constraints | sem produção; sem push; um consumidor do Telegram; banco local é cópia de produção |

## Constitution Check

| Princípio | Status | Notas |
|---|---|---|
| I Privacidade sem exceção de admin | PASS após A | Hoje FAIL (revisão 1, 8); a frente A corrige |
| II Cada pessoa com sua máquina | PASS após B | Hoje o runner obedece ao servidor (revisão 2) |
| III Um sistema, um consumidor | PASS | O runner só chama `sendMessage`, nunca `getUpdates` |
| IV Segredos | PASS | Segredo de confirmação e token do bot só no env local 0600; nada no banco |
| V Prova executável | PASS | Cada frente com teste; hoje há 1 falha herdada, a corrigir em C |
| VI Acesso total com rastro | PASS | Confirmação e recusa em `central_ia_eventos` e no log local do runner |
| VII Autorização explícita | PASS | Commits locais na Felipe, sem push nem produção |
| VIII Veracidade | PASS | O custo do Copilot por modelo fica "a confirmar", não é presumido |

## Project Structure (arquivos reais tocados)

```
app/Http/Controllers/UsuarioController.php          A — proteção de conta com Central
app/Http/Controllers/CentralIaController.php        A, B (confirmar), D (nível), E
app/Http/Controllers/CentralIaRunnerController.php  B (confirmacao)
app/Services/CentralIa/Governanca.php               A — dono da Central
app/Services/CentralIa/AgentesService.php           B, C (sessão, expiração), D
app/Services/CentralIa/Roteador.php                 C (verbos), D (nível)
app/Services/CentralIa/FontesDeDados.php            C (exigirPeriodo), D (sem IA)
app/Services/CentralIa/TelegramCanal.php            B (/confirmar), C (/status, /salvar, entrega, encaminhada)
app/Services/CentralIa/ProgressoService.php         C (pendente sem fim)
app/Services/CentralIa/ProtecaoDeSegredos.php       C (ordem, padrões)
app/Services/CentralIa/RespostaVisual.php           C, E
app/Console/Commands/CentralIaTelegram.php          C (update que trava)
config/central_ia.php                               A (dono), D (níveis)
database/migrations/2026_10_12_*                     B (confirmacao na execução)
deploy/central-ia/runner.mjs + confirmacao.mjs       B, C, D
deploy/central-ia/uso-local.mjs                      C (dedupe entre arquivos)
resources/views/central-ia/index.blade.php           E
resources/js/central-ia.js                           B, D, E
tests/Feature/CentralIa/*                            todos
tools/central-ia/test-*.mjs                          B, C, D
docker/local/nginx.conf (fora do Git)                F
```

## Data Model (mudanças)

- `central_ia_execucoes.confirmacao` (json, nulo): `estado`, `tentativas`, `expira_em`, `resumo`,
  `codigo_digitado` (apagado ao entregar ao runner), `atualizado_em`. A migração é aditiva.
- O `roteamento` (json existente) ganha `nivel` (`sem_ia|leve|medio|forte`), `modelo` e `esforco`.
- Sem tabela nova. A governança continua no JSON de `central_ia_preferencias`.

## Convenções de Borda

| Camada | Case | Fonte da verdade |
|---|---|---|
| Colunas e JSON do banco | snake_case | migrações |
| API do runner (request/response) | snake_case | `CentralIaRunnerController` + `contracts/confirmacao.md` |
| Payload web (Alpine) | snake_case | `CentralIaController::painel` |
| Env do runner | UPPER_SNAKE | `deploy/central-ia/README.md` |

Roundtrip obrigatório: o teste Node do runner chama o controller real numa instância isolada
(`tools/central-ia/test-confirmacao.mjs` contra `php artisan serve` no container de testes), e não um mock.

## Fases

### A. Segurança da conta (FR-026)
1. `config('central_ia.dono')`, de `CENTRAL_IA_DONO`. `User::temCentral()` e `User::ehDonoDaCentral()`.
2. `UsuarioController`: `update`, `redefinirSenha`, `alternarAtivo`, `destroy` e a sincronização de perfis recusam quando o alvo tem Central e quem age não é o próprio alvo nem o dono (e o dono só por ele mesmo). Recusa auditada e aviso no sino do alvo.
3. `Governanca::administra` = é o dono da Central. A governança só lista quem não é o dono. O relatório de outro usuário cai no mesmo filtro.
4. Banco local: tirar o perfil `admin` do Alexandre (DRY antes, depois aplicar), sem produção.

### B. Runner inviolável (FR-022, FR-024, FR-025)
1. `confirmacao.mjs` (novo, ao lado do runner): segredo, código, envio ao Telegram, conferência e log local de auditoria.
2. `runner.mjs`: teto local; `realpath` de toda pasta; analisar só leitura por CLI (research D2); implementar só depois de confirmado; recusa auditada.
3. Servidor: migração `confirmacao`; `POST runner/execucoes/{id}/confirmacao`; `POST central-ia/execucoes/{id}/confirmar`; `/confirmar` no Telegram; eventos; `expirarPresas` conta a espera.
4. Telegram: recusar mensagem encaminhada e desligar a prévia de link.

### C. Bugs (revisão 5–7, 9–14, 17 + não commitado)
- Sessão retomada: na falha, o `cwd` vai junto; o servidor usa o `cwd` da tarefa quando o checkpoint não tem.
- `exigirPeriodo`: só para pedido de DADOS no modo analisar sem projeto de código; "agenda" sem data é hoje.
- `/status` e consultas: entrega garantida no canal ativo (projeto e tarefa do canal).
- Pendente expira (teto + 30 min, ou máquina offline por mais de 30 min); progresso só para `executando`.
- Conclusão: o runner tenta por até 30 min com espera crescente; `expirarPresas` com trava e nova checagem.
- Entrega do Telegram fora da transação (research D6).
- Consumo do Claude: deduplicação global por `requestId`/`message.id`.
- Roteador: "adicione, altere, remova, ajuste, inclua" com objeto de código implementam.
- `/salvar` usa a tarefa do canal ativo.
- Bot: depois de 3 falhas no mesmo `update_id`, avança o cursor e registra o evento.
- Não commitado: segredos mascarados DEPOIS de extrair o dossiê; fixture sintética no lugar da com dados reais; Telegram mantém a análise; tabelas pelo `<x-tabela>`; padrões de segredo ampliados.

### D. Níveis de custo (FR-027)
- `config('central_ia.niveis')` e o override por conta em Ajustes.
- O roteador devolve `nivel`, e a tela deixa trocar o nível antes de enviar.
- `sem_ia`: listagem pura de agenda, com o runner devolvendo os dados formatados.
- O runner aplica o modelo e o esforço por CLI.
- O Consumo mostra quanto cada nível gastou.

### E. UI e UX (FR-028, FR-029)
- Screenshots (Playwright em Docker) do estado atual em desktop e celular, e a lista de atritos.
- Faixa fixa "Esperando você" (confirmação, dúvida) e localização clara (projeto › tarefa › canal).
- Balão de resposta: essencial primeiro, detalhes recolhidos, opções da dúvida como botões, "Aprovar / Pedir ajuste / Delegar".
- Compositor: IA, nível e modo legíveis, com o motivo.
- Revisar cada aba contra o design (tokens, sem hex nem tabela crua) e repetir os screenshots.

### F. Ativação local
- nginx com resolver; reinstalar o runner (cópia + restart em ociosidade); configurar o segredo e o Telegram no env local.
- Ponta a ponta: agenda sem IA, análise leve, implementação com código, código errado recusado, execução forjada no banco recusada.

## Complexity Tracking

Nenhuma violação de princípio MUST a justificar.
