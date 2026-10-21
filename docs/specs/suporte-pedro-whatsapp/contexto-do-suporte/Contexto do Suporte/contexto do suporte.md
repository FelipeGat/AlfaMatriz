---
titulo: Contexto do Suporte — mapa
tipo: mapa
validacao: validado
atualizado: 2026-10-21
fontes: ["T-476 no AlfaMatriz"]
---

# Contexto do Suporte

Base de conhecimento do suporte. O Pedro (WhatsApp) a consulta a cada mensagem: lê este mapa, a pasta do cliente
confirmado na conversa e as notas ligadas a ela. Ele nunca lê a pasta de outro cliente.

## As pastas
- **01 - Empresa:** contexto interno da empresa (quem faz o quê, horários, políticas). O que vai ao cliente
  (apresentação, sites, contatos, folders) continua na tela Suporte › Pedro.
- **02 - Procedimentos:** como resolver cada tipo de problema, passo a passo. Valem para todos os clientes.
  Os roteiros curtos continuam na tela Suporte › Pedro; aqui ficam os procedimentos longos.
- **03 - Clientes:** uma pasta por cliente, com o nome `<nome curto> · <código>` (ex.: `Castanheiras · gestor-13`).
  Dentro dela, o `contexto.md` e a pasta `atendimentos/`, um arquivo por atendimento.
- **04 - Conhecimento:** o que se aprendeu nos atendimentos e pode valer para outros clientes.

## Regras de escrita
- Toda nota tem cabeçalho com `titulo`, `tipo`, `validacao`, `atualizado` e `fontes`. Os modelos estão em `_modelos`.
- `validacao`: `rascunho` (ninguém conferiu), `validado` (o Felipe ou um administrador conferiu e escreveu o
  nome em `validado_por`) ou `obsoleto`. O Pedro só segue como instrução o que está `validado`.
- Separe sempre: **relato do cliente**, **verificado** (alguém viu ou testou; diga quem), **hipótese** e
  **pendente de confirmação**.
- Nunca marque um atendimento como `resolvido` sem a confirmação do cliente ou da equipe, escrita na nota.
- Solução de um cliente fica no atendimento dele. Se parecer valer para outros, vira nota em 04 - Conhecimento
  como `rascunho`. Só vira procedimento depois de validada.
- O `contexto.md` do cliente só muda quando algo duradouro muda (equipamento trocado, pessoa nova, combinado novo).
- Antes de criar uma nota, procure se a solução já existe. O nome de procedimento e de conhecimento é único na base.
- Arquivos e pastas que começam com `_` (modelos, índices) não vão ao Pedro.

## Índices
- [[_índice dos procedimentos]]
- [[_índice dos clientes]]
- [[_índice do conhecimento]]
