# Implementation Plan: Secretário, Etapa 5 (e-mails e Gestor)

**Spec**: [spec.md](spec.md) · [research.md](research.md) · [data-model.md](data-model.md) ·
[contracts/gestor-retrato.md](contracts/gestor-retrato.md) · 17/10/2026

## Summary

**E-mail.** Um cliente IMAP próprio, só de leitura (`EXAMINE` + `BODY.PEEK`), lê a cada 10 min as caixas que a
pessoa cadastrou. Guarda metadados e a classificação por regra; o corpo nunca é guardado. O chat responde
"importantes hoje" e "aguardando resposta" sem IA. Resumo e rascunho vão à IA da pessoa com os trechos lidos na
hora. Não existe envio.

**Gestor.** O runner roda o script de leitura pela ponte de produção (sem `--apply`) e manda o retrato.

**Junção.** "O que estou devendo aos clientes?" junta tudo, e o briefing ganha as duas seções. A publicação só
acontece depois do teste local e da liberação.

## Technical Context

| Campo | Valor |
|---|---|
| Servidor | PHP 8.2, Laravel 12.64; nenhuma dependência nova (IMAP por socket, `stream_socket_client` com TLS) |
| Runner | `deploy/central-ia/gestor.mjs` (roda a ponte), mais o script PHP para o `C:\tmp` |
| Testes | PHPUnit com transporte IMAP simulado; teste local com GreenMail (IMAP real) e a ponte real em DRY |

## Constitution Check

| Princípio | Status | Notas |
|---|---|---|
| I. Privacidade por conta | PASS | Caixas, mensagens e retrato por `user_id`; 404 para outra conta. |
| II. IA na máquina | PASS | Classificação por regra; resumo e rascunho pelo runner. |
| III. Um sistema só | PASS | Mesmo banco; a leitura de e-mail roda pelo agendador que já existe. |
| IV. Segredos | PASS | Senha `encrypted` + `hidden`; erro guardado como código; nada de corpo ou senha em log. |
| V. Prova | PASS | IMAP real (GreenMail) e ponte real em DRY no teste local. |
| VI. Rastro | PASS | Caixa criada/removida, falha de leitura e retrato recebido viram evento, sem conteúdo. |
| VII. Ação externa | PASS | Nada é enviado (não há SMTP); o Gestor é só lido, sem `--apply`. |
| VIII. Zero fabricação | PASS | Consultas do Gestor espelham o código dele (citado); a ausência de leitura é dita. |

## Design

- **Novo**:
  - `app/Services/Secretario/Email/ClienteImap.php` (+ `TransporteImap` para o teste);
  - `Email/LeitorDeEmails.php` (sincronizar, classificar, respondidos, limpar);
  - `Email/Classificador.php`;
  - `ConsultaDeEmails.php` (importantes, aguardando);
  - `ConsultaDePendencias.php` ("o que estou devendo");
  - `PedidoDeEmailIa.php` (resumo e rascunho);
  - os modelos, a migração e `secretario:emails`;
  - `SecretarioEmailsController` (caixas, mensagens, rascunho, lembrar);
  - a rota do runner `gestor`;
  - `deploy/central-ia/gestor.mjs` e `deploy/central-ia/secretario_gestor.php`.
- **Alterado**:
  - `Conversa::interpretar` (três perguntas novas);
  - `MontadorDeBriefing` (duas seções e o rodapé);
  - `runner.mjs` (envio do retrato de hora em hora);
  - `Maquinas::ARQUIVOS`;
  - Configurações (bloco E-mail) e o menu ("Mensagens").

## Convenções de Borda

| Camada | Estilo | Fonte |
|---|---|---|
| Banco | snake_case | migração `2026_10_17_200000` |
| Runner → servidor | JSON snake_case | `contracts/gestor-retrato.md` |
| IMAP | RFC 3501 | `ClienteImap` |

## Complexity Tracking

Nenhuma violação.
