# Implementation Plan: Secretário, Etapa 7 (Acompanhamento Proativo)

**Spec**: [spec.md](spec.md) · [research.md](research.md) · 17/10/2026

## Summary

O motor (`Acompanhamento\Motor`) roda as regras ligadas de cada conta e grava os acompanhamentos com chave única.
O envio (`Acompanhamento\Envio`) decide, pela política da pessoa, se sai um resumo e o entrega no sino e no
Telegram, com registro. O comando `secretario:acompanhar` roda a cada 5 min. A etapa traz também a tela
Secretário → Acompanhamentos, uma linha no Meu Dia, uma seção no briefing, a pergunta no chat e o botão de adiar
no Telegram. A publicação só acontece depois do teste local.

## Technical Context

PHP 8.2 / Laravel 12.64; sem dependência nova. Uma migração cria duas tabelas e uma coluna JSON. Testes PHPUnit
(SQLite) e teste local no 8096 com os dados das Etapas 4 e 5.

## Constitution Check

| Princípio | Status | Notas |
|---|---|---|
| I. Privacidade | PASS | Tudo por `user_id`; ações com `findOrFail` escopado; admin sem o perfil. |
| II. IA na máquina | PASS | Regras determinísticas; nenhuma chamada de IA. |
| III. Um sistema | PASS | Mesmas tabelas `secretario_*`, sino, bot e `schedule:run`; sem fila. |
| IV. Segredos | PASS | Texto do Telegram por `ProtecaoDeSegredos`; rastro de eventos sem o texto. |
| V. Prova | PASS | Testes de regra, política, isolamento e toque; teste local. |
| VI. Rastro | PASS | Cada envio registrado; falha vira evento. |
| VII. Externa | PASS | Só o Telegram da própria pessoa; nada a terceiros. |
| VIII. Zero fabricação | PASS | Cada linha vem de um registro, com link. |

## Design

- Migração `2026_10_17_220000_secretario_acompanhamento.php`:
  - `secretario_acompanhamentos`: user, regra, chave, prioridade, texto, link, referencia_em, situacao
    (ativo/resolvido/dispensado), adiado_ate, avisos, avisado_em, resolvido_em; único `(user_id, chave)`;
  - `secretario_acompanhamento_envios`: user, canal, status, tentativas, proxima_tentativa_em, ids (JSON),
    quantos, telegram_message_id, erro, entregue_em;
  - coluna `acompanhamento` (JSON) em `secretario_preferencias`.
- `app/Services/Secretario/Acompanhamento/`:
  - `Regras` (definição das 7, padrões, faixas e validação);
  - `Achados` (cada regra devolve as situações);
  - `Motor` (upsert e resolução);
  - `Politica` (silêncio, intervalo, teto e repetição);
  - `Envio` (montar, registrar e entregar o resumo).
- O comando `secretario:acompanhar` roda a cada 5 min (`withoutOverlapping()->onOneServer()`).
- `SecretarioAcompanhamentosController` e a view `secretario/acompanhamentos.blade.php`, com item "Acompanhamentos"
  no menu.
- Ganchos:
  - `TelegramDoSecretario::toque` (prefixo `ac:`);
  - `Conversa::interpretar` (`ConsultaDeAcompanhamentos`);
  - `MontadorDeBriefing` (seção "🔔 Acompanhar");
  - `meu-dia` (uma linha).

## Convenções de Borda

N/A: single-layer (serviço e Blade). A tela usa formulário POST com redirect, como Configurações.

## Complexity Tracking

Nenhuma violação.
