# Research: Secretário, Etapa 6 (Memória)

## Decision 1: Busca direta nas fontes, sem índice novo

**Decision**: `BuscaNaMemoria` consulta as tabelas que já existem (só da conta), com `LIKE` por termo, e pontua em
PHP: termos achados, peso da fonte (decisão > tarefa > item > e-mail > conversa) e data.

**Rationale**:
- O volume é de uma pessoa: dezenas de reuniões, centenas de itens e e-mails por mês.
- Uma tabela-índice nova precisaria ser mantida em sincronia em cinco lugares.
- O `utf8mb4_unicode_ci` do MariaDB já ignora acento e caixa no `LIKE`. No SQLite dos testes, os termos são
  normalizados antes.

**Alternatives considered**:
- FULLTEXT do MariaDB: não roda no SQLite dos testes e quebra o português curto ("NF", "RFID" com menos de 4
  letras, conforme o `ft_min_word_len`).
- Vetores e embeddings: exigiriam um serviço de IA no servidor, o que a constitution II proíbe.

## Decision 2: Intenções por regra

Como as Etapas 1 e 2, um reconhecedor de frases em português leva cada pergunta à consulta certa:
- `decidido` + reunião + período ou tipo → reunião;
- `combinamos|pendente|ficou com` + nome → pessoa;
- `reuniões tive` + período → lista;
- `compromissos assumi` → compromissos;
- `propostas acompanhar` → propostas;
- `o que eu sei sobre`/`histórico` → busca.

Pergunta fora desses formatos segue para a Central.

## Decision 3: Fato x extraído x inferência (FR-004)

- **Fato**: decisão ou tarefa de reunião com o status `revisada`, itens do Secretário, e-mails e Gestor.
- **Extraído pela IA**: reunião com o status `pronta` (ainda não revisada).
- **Inferência**: `participantes`, os nomes citados na fala.

O rótulo vai na linha.

## Decision 4: Síntese opcional

"Resuma o histórico com X" chama `AgentesService::pedir` com até 30 resultados como dado, no mesmo padrão das Etapas
4 e 5.
