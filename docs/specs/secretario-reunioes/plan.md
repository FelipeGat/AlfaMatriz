# Implementation Plan: Secretário, Etapa 4 (Matriz Meetings)

**Spec**: [spec.md](spec.md) · [research.md](research.md) · [data-model.md](data-model.md) ·
[contracts/runner-transcricao.md](contracts/runner-transcricao.md) · 17/10/2026

## Summary

O áudio chega pelo Telegram, por upload ou por gravação no navegador e vai para o disco privado. O runner da
própria pessoa reserva a reunião, transcreve num container sem rede (faster-whisper) e devolve os trechos com
horário. O servidor guarda, apaga o áudio e pede a análise à IA da pessoa, como execução da Central. O bloco
`<<<REUNIAO>>>` volta validado e vira resumo, contexto, decisões, tarefas e pendências com evidências.

A revisão aprova as tarefas, que viram itens do Secretário. Voz curta no Telegram é ditado. A publicação só
acontece depois do teste local e da liberação do Felipe.

## Technical Context

| Campo | Valor |
|---|---|
| Servidor | PHP 8.2, Laravel 12.64; nenhuma dependência nova |
| Runner | Node (`deploy/central-ia/*.mjs`), mais a imagem Docker nova `alfamatriz-central-whisper:local` (Python 3.11, faster-whisper, modelo `small` int8) |
| Storage | disco `central_ia` (privado), banco MariaDB / SQLite nos testes |
| Testes | PHPUnit, Node (`tools/central-ia/test-transcricao.mjs` com áudio sintetizado), teste local 8096 com o runner apontando para o local |
| Limites | upload de 12 MB (`php-alfamatriz.ini`); instrução até 60.000 bytes |

## Constitution Check

| Princípio | Status | Notas |
|---|---|---|
| I. Privacidade por conta | PASS | Reunião, trechos, propostas e áudio escopados por `user_id`; o runner só pega reunião da própria conta; 404 para outra. |
| II. IA na máquina | PASS | Transcrição e análise acontecem no runner da pessoa; o servidor não chama IA nem serviço de voz. |
| III. Um sistema só | PASS | Mesmo banco; o bot do Telegram é o mesmo (só passa a ler `voice`/`audio`). |
| IV. Segredos | PASS | Transcrição e análise fora de log; o texto que vai ao Telegram passa por `ProtecaoDeSegredos`. |
| V. Prova | PASS | Testes PHP e Node, mais o teste local com o runner real antes de qualquer publicação. |
| VI. Rastro | PASS | Recebida, transcrita, analisada, falha e aprovação viram evento, sem conteúdo. |
| VII. Ação externa | PASS | Nada vai a terceiros; tarefas só com aprovação. |
| VIII. Zero fabricação | PASS | Análise validada; evidência obrigatória; "não identificado" quando faltar. |

**Re-check pós-design**: PASS. A única peça nova de infraestrutura é a imagem Docker do runner, construída pelo
instalador como as de mídia e de documentos.

## Design

**Servidor**:
- modelos `SecretarioReuniao`, `SecretarioReuniaoTrecho` e `SecretarioReuniaoProposta`;
- `Services/Secretario/Reunioes.php`: receber (upload/Telegram), reservar, resultado, liberar as presas;
- `AnaliseDeReuniao.php`: pedir, validar, gravar, avisar;
- `RevisaoDeReuniao.php`: aprovar, aprovar em lote, descartar, editar;
- `SecretarioReunioesController.php` (tela, lista, pesquisa, detalhe, ata, upload, ações);
- rotas do runner em `routes/central-ia.php`, em `CentralIaRunnerController` ou num controller próprio
  `SecretarioRunnerController`.

**Alterações**:
- `TelegramCanal::receberNoBot` passa a ler `voice` e `audio` (e documento de áudio) antes do caminho de anexo;
- `AgentesService::concluir` ganha o gancho da análise;
- comando `secretario:reunioes` (liberar presas, a cada 5 min).

**Runner**:
- `deploy/central-ia/transcricao.mjs`;
- o laço do `runner.mjs` pede `transcricoes/proxima` quando há vaga;
- `deploy/central-ia/whisper/{Dockerfile,transcrever_reuniao.py}`;
- `instalar.sh` constrói a imagem.

**Telas**: `secretario/reunioes/{index,mostrar,ata}.blade.php` e o menu "Reuniões".

## Convenções de Borda

| Camada | Estilo | Fonte da verdade |
|---|---|---|
| Banco | snake_case | migração `2026_10_17_180000` |
| Runner ↔ servidor | snake_case JSON | `contracts/runner-transcricao.md` |
| Bloco da IA | JSON com chaves snake_case em português | `AnaliseDeReuniao::validar` |
| Saída do container | `{duracao, trechos[{inicio, fim, texto}]}` | `whisper/transcrever_reuniao.py` |

## Complexity Tracking

Nenhuma violação.
