# Implementation Plan: Secretário, Etapa 3 (Briefing Executivo)

**Spec**: [spec.md](spec.md) · [research.md](research.md) · [data-model.md](data-model.md) · 17/10/2026

## Summary

`MontadorDeBriefing` junta as fontes das Etapas 1 e 2 num texto curto, por seções, com a sugestão por regra e um
rodapé com as fontes usadas e as que ainda faltam. `secretario:briefing` (a cada minuto) gera o automático no
horário de cada conta, uma vez por dia, e entrega no Telegram com tentativas. "Resume meu dia" gera sob demanda
pelo chat. O Meu Dia mostra o de hoje. Nada sai para produção antes do teste local (pedido do Felipe).

## Technical Context

| Campo | Valor |
|---|---|
| Stack | PHP 8.2, Laravel 12.64; nenhuma dependência nova |
| Storage | MariaDB 10.11 / SQLite nos testes |
| Testes | PHPUnit (suíte isolada) e teste local no `alfamatriz-dev` (8096): telas e comando |
| Restrições | sem IA no servidor, sem fila, migração aditiva, texto fora de log |

## Constitution Check

| Princípio | Status | Notas |
|---|---|---|
| I. Privacidade por conta | PASS | Briefing, preferências e fontes escopados por `user_id`; 404 para outra conta. |
| II. IA na máquina | PASS | Montado por regra no servidor. |
| III. Um sistema só | PASS | Mesmo banco e mesma aplicação; nenhum processo novo. |
| IV. Segredos | PASS | O texto passa por `ProtecaoDeSegredos` antes do Telegram e nunca vai a log. |
| V. Prova | PASS | Testes com relógio controlado, e teste local antes de qualquer publicação. |
| VI. Rastro | PASS | Geração e falha final de entrega viram evento (sem o texto). |
| VII. Ação externa | PASS | Só a própria pessoa recebe. Sem deploy até a liberação do Felipe. |
| VIII. Zero fabricação | PASS | Só itens das fontes; a limitação de cada fonte é dita. |

**Re-check pós-design**: PASS.

## Design

- **Novo**:
  - `app/Models/SecretarioBriefing.php`;
  - `app/Services/Secretario/MontadorDeBriefing.php` (texto e fontes);
  - `app/Services/Secretario/Briefings.php` (devido, gerar, entregar, pedir);
  - `app/Console/Commands/SecretarioBriefing.php` (`secretario:briefing`, a cada minuto).
- **Alterado**:
  - `SecretarioPreferencia`: briefing, hora e dias;
  - `Conversa`: frase "resume meu dia";
  - `SecretarioController`: o briefing de hoje no Meu Dia e as configurações;
  - `meu-dia.blade.php` e `configuracoes.blade.php`;
  - `routes/console.php`.

## Project Structure

```
docs/specs/secretario-briefing/{spec,research,data-model,plan,tasks}.md
app/Models/SecretarioBriefing.php  +
app/Services/Secretario/{MontadorDeBriefing,Briefings}.php  +
app/Console/Commands/SecretarioBriefing.php  +
database/migrations/2026_10_17_160000_secretario_briefing.php  +
tests/Feature/Secretario/BriefingTest.php  +
```

## Convenções de Borda

| Camada | Estilo | Fonte da verdade |
|---|---|---|
| Banco | snake_case | migração `2026_10_17_160000` |
| JSON (Meu Dia: `briefing`) | snake_case `{texto, gerado_em, telegram}` | `SecretarioController::itens` |
| Telegram | texto puro, sem `parse_mode` | `TelegramDoSecretario::responder` |

## Complexity Tracking

Nenhuma violação.
