# Implementation Plan: Secretário, Etapa 6 (Memória Executiva)

**Spec**: [spec.md](spec.md) · [research.md](research.md) · 17/10/2026

## Summary

`BuscaNaMemoria` (fontes + pontuação) e `ConsultaDeMemoria` (intenções por regra) respondem pelo `Conversa` (chat e
Telegram). A tela Secretário → Memória faz a busca. A síntese pela IA é opcional. Não há tabela nova: a etapa só
lê. A publicação só acontece depois do teste local.

## Technical Context

PHP 8.2 / Laravel 12.64; sem dependência nova; sem migração. Testes PHPUnit (SQLite) e teste local no 8096 com
os dados das Etapas 4 e 5.

## Constitution Check

| Princípio | Status | Notas |
|---|---|---|
| I. Privacidade | PASS | Toda consulta por `user_id`; mensagens da Central por `CentralIaMensagem::de`. |
| II. IA na máquina | PASS | Busca por regra; síntese pelo runner. |
| III. Um sistema | PASS | Sem tabela nem processo novo. |
| IV. Segredos | PASS | Só mostra o que já está guardado; e-mail sem corpo. |
| V. Prova | PASS | Testes e teste local. |
| VI. Rastro | PASS | Síntese vira execução da Central (já registrada). |
| VII. Externa | PASS | Nada sai. |
| VIII. Zero fabricação | PASS | Fonte, data e link em toda linha; ausência dita. |

## Design

- `app/Services/Secretario/Memoria/BuscaNaMemoria.php`: `buscar(user, termos, ?abertos)` devolve resultados
  `{fonte, rotulo, texto, data, link, natureza, pontos}`.
- `app/Services/Secretario/Memoria/ConsultaDeMemoria.php`: as seis intenções e a síntese.
- `Conversa::interpretar` recebe a consulta antes das demais.
- `SecretarioMemoriaController` e `resources/views/secretario/memoria.blade.php`; item "Memória" no menu.

## Convenções de Borda

N/A: só leitura, single-layer (serviço e Blade). A busca usa `?q=` na URL.

## Complexity Tracking

Nenhuma violação.
