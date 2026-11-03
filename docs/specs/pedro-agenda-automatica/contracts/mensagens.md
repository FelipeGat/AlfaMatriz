# Contrato: textos das mensagens e modelos da Meta

**[PROPOSTA — textos a aprovar pelo dono antes da criação na Meta]**. Nenhum modelo existe hoje: em 02/11/2026 a
conta tinha aprovados só `cobranca_vencimento_dia` e `hello_world` (spec, Contexto). Criar e submeter os modelos é
ação externa, feita só com autorização expressa do dono.

Regras da Meta seguidas (documentação oficial lida em 02/11/2026, ver `research.md`): corpo até 1024 caracteres;
o corpo não começa nem termina com variável; exemplo de cada variável na criação; categoria UTILITY sujeita a
recategorização. Regra nossa: **nenhuma variável leva quebra de linha** (a doc oficial não trata; fontes secundárias
relatam recusa).

Dados dos exemplos: os fictícios da spec (US1 a US3).

## Texto livre (janela aberta)

### Programação (16:30)

```text
Olá, Cristiano! Sua programação de amanhã, terça, 03/11:

• 09:00–10:00 — Praia Branca (nº 837)
• 10:00–18:00 — Castanheiras (nº 776)

Detalhes no Portal do Funcionário: https://gestor.alfa.solucoesgrupo.com/portal-funcionario

Pedro — Grupo Soluções
```

### Mudança

```text
Olá, Vitor! Sua agenda de amanhã, terça, 03/11, foi atualizada:

• Nova visita: 08:00–18:00 — Leroy Merlin (nº 31)

Detalhes no Portal do Funcionário: https://gestor.alfa.solucoesgrupo.com/portal-funcionario

Pedro — Grupo Soluções
```

Frases por tipo de mudança (Decision 3):

| Tipo | Frase |
|---|---|
| nova | `Nova visita: 08:00–18:00 — Leroy Merlin (nº 31)` |
| horário | `Horário mudou: Castanheiras (nº 776), de 10:00–18:00 para 13:00–18:00` |
| remarcada | `Remarcada: Praia Branca (nº 837), de 03/11 para 05/11, 09:00–10:00` |
| cancelada | `Cancelada: Praia Branca (nº 837), 09:00–10:00. Não deve mais ser executada.` |
| saiu | `Saiu da sua agenda: Praia Branca (nº 837), 09:00–10:00. Não vá a ela.` |

O dia é "hoje" ou "amanhã" mais o dia da semana e a data.

### Relatório dos gestores (17:00)

```text
Agenda de amanhã, terça, 03/11:

• Cristiano: 09:00–10:00 Praia Branca (nº 837); 10:00–18:00 Castanheiras (nº 776)
• Alefe e Vitor: 08:00–18:00 VD Cariacica (nº 832)
• Cleyson: 08:00–18:00 Corais de Parati (nº 829)

4 visitas | 4 técnicos | 0 conflitos | 1 pendência
Pendências:
• Alefe: sem aviso (sem número)

Pedro — Grupo Soluções
```

Dia sem visita: `Amanhã, terça, 03/11, não há visita na agenda. Pedro — Grupo Soluções` (FR-020).

### Resposta fixa ao técnico (FR-021) e "parar" (FR-006)

- `Esta é uma mensagem automática da agenda. Para falar sobre ela, fale com o seu gestor.`
- `Pronto, você não recebe mais os avisos automáticos da agenda.`

Essas duas só saem dentro da janela (o técnico acabou de escrever): não precisam de modelo.

## Modelos (janela fechada)

Todos: categoria **UTILITY**, idioma `pt_BR`, só corpo. Variável de lista: itens separados por ` • `, sem quebra de
linha. Corpo preenchido acima de 1024 → partes: a lista é repartida entre mensagens iguais, e o dia ganha "(1/2)",
"(2/2)".

### `agenda_programacao` — 3 variáveis

```text
Olá, {{1}}! Sua programação de {{2}}: {{3}}. Detalhes no Portal do Funcionário: https://gestor.alfa.solucoesgrupo.com/portal-funcionario. Para não receber mais, responda PARAR. Pedro — Grupo Soluções
```

| Variável | Exemplo |
|---|---|
| {{1}} | `Cristiano` |
| {{2}} | `amanhã, terça, 03/11` |
| {{3}} | `09:00–10:00 Praia Branca (nº 837) • 10:00–18:00 Castanheiras (nº 776)` |

### `agenda_mudanca` — 3 variáveis

```text
Olá, {{1}}! Sua agenda de {{2}} foi atualizada: {{3}}. Detalhes no Portal do Funcionário: https://gestor.alfa.solucoesgrupo.com/portal-funcionario. Para não receber mais, responda PARAR. Pedro — Grupo Soluções
```

| Variável | Exemplo |
|---|---|
| {{1}} | `Vitor` |
| {{2}} | `amanhã, terça, 03/11` |
| {{3}} | `Nova visita: 08:00–18:00 Leroy Merlin (nº 31)` |

### `agenda_relatorio` — 3 variáveis

```text
Agenda de {{1}}: {{2}}. Resumo: {{3}}. Pedro — Grupo Soluções
```

| Variável | Exemplo |
|---|---|
| {{1}} | `amanhã, terça, 03/11` |
| {{2}} | `Cristiano: 09:00–10:00 Praia Branca (nº 837); 10:00–18:00 Castanheiras (nº 776) • Alefe e Vitor: 08:00–18:00 VD Cariacica (nº 832)` |
| {{3}} | `4 visitas, 4 técnicos, 0 conflitos, 1 pendência (Alefe: sem número)` |

Acima de 1024, as linhas ({{2}}) e os conflitos e pendências (em {{3}}, depois das contagens) são repartidos juntos
pelas partes, cada um uma vez; as contagens vão em todas, e nada é cortado (FR-019). A parte que só leva conflitos e
pendências diz em {{2}}: `as visitas estão nas outras partes` **[PROPOSTA]**. Há visita, mas nenhuma com técnico (a
sem responsável, que vai nas pendências): `nenhum técnico na agenda` **[PROPOSTA]**, no modelo e no livre. Nenhuma
visita lida, mas com conflito ou pendência a nomear — a falha de leitura da agenda: `nenhuma visita encontrada`
**[PROPOSTA]**, também nas duas formas. O dia só sai como "não há visita na agenda" sem visita, linha, conflito nem
pendência: com a falha de leitura, dizer que o dia está livre seria afirmar o que a agenda pode não ter mostrado.

Risco: a Meta pode achar a proporção de variáveis alta (erro 2388293, sem número na doc). Se recusar, a saída é mais
texto fixo, sem mudar o código (o código só preenche as variáveis).

### `agenda_alerta` — 3 variáveis

```text
Aviso da agenda automática: {{1}} escreveu ao número do Pedro: "{{2}}". {{3}} Pedro — Grupo Soluções
```

| Variável | Exemplo |
|---|---|
| {{1}} | `Cristiano (técnico)` |
| {{2}} | `vou chegar às 10h` |
| {{3}} | `Ele recebeu a resposta automática; fale com ele se precisar.` / `Ele pediu para parar e não recebe mais os avisos.` |

O texto do técnico vai sem quebra de linha (as quebras viram espaço) e cortado em 300 caracteres **(padrão)**.

## O que nunca entra (FR-010, FR-011)

Valor, observação da visita, telefone ou endereço de cliente, compromisso de outro técnico (na mensagem do técnico),
token ou credencial no link.
