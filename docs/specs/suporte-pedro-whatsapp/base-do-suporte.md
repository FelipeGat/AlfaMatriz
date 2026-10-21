# Pedro — base do suporte (T-476)

**Feature**: `suporte-pedro-whatsapp` (etapa 5)
**Created**: 2026-10-21
**Pedido**: Rossini, 21/10/2026 (T-476): uma base de conhecimento do suporte que alimenta o Pedro e que os
próprios atendimentos alimentam, sem parar: cada atendimento vira registro, e a memória do cliente, os
procedimentos e o que se aprendeu ficam guardados para a próxima conversa.

## Decisões do dono (21/10/2026)

1. **A base mora no Matriz, sem OneDrive.** A primeira versão desta etapa (commit `bbdeab3`) lia uma pasta
   "Contexto do Suporte" espelhada do OneDrive do Felipe. O dono mudou no mesmo dia: quem mais escreve na base
   são os atendimentos, e cada registro confirmado precisa valer na hora, sem depender da máquina que espelha a
   pasta e sem conflito entre duas cópias. A leitura da pasta saiu inteira.
2. O registro do atendimento que o Pedro propõe é **confirmado por quem atendeu** a conversa.
3. Atendimento que o Pedro resolve sozinho também vira registro: "resolvido" se o cliente disse que resolveu,
   "encerrado sem confirmação" se ele sumiu. Ninguém da equipe atendeu, então qualquer pessoa do suporte confirma,
   na fila "Registros a confirmar".
4. **Quem valida** procedimento, conhecimento e empresa: o Felipe (editores do Pedro) ou qualquer administrador.
5. O código do atendimento é `AT-<AAAAMMDD>-<nº da conversa>` (`-2` se houver outro no mesmo dia e na mesma conversa).

## O que há na base (`suporte_notas`)

| Tipo | O que é | Vai ao Pedro |
|---|---|---|
| Empresa | O que a equipe sabe e o cliente não precisa ouvir | Sempre |
| Cliente | A memória de um cliente (uma por cliente, pelo nº do Gestor e/ou o cadastro do Matriz) | Só na conversa do cliente confirmado |
| Atendimento | Relato, verificado, solução, situação | Do cliente confirmado e da própria conversa |
| Procedimento | Passos para uma situação, com "quando usar" | Os que combinam com a conversa |
| Conhecimento | Um fato aprendido (muitas vezes saído de um atendimento) | Os que combinam com a conversa |

A apresentação ao cliente e os roteiros curtos continuam em Suporte › Pedro: conhecimento.

## Requisitos

- **FR-B1, um recorte com teto.** Vão, nesta ordem: as notas de Empresa; a memória do cliente confirmado; até 3
  atendimentos em curso dele (ou desta conversa), inteiros; dos 3 últimos encerrados, só a solução; e até 3
  procedimentos ou conhecimentos que combinam com as 3 últimas mensagens. Cada nota tem um teto e o recorte
  inteiro, 12 mil caracteres; o que não coube é dito. Base sem nada que sirva: o pedido sai como antes.
- **FR-B2, só o cliente da conversa.** Sem cliente confirmado, nenhuma memória de cliente entra; atendimentos,
  só os da própria conversa. Nunca nota de outro cliente.
- **FR-B3, a busca cresce com a base.** O banco filtra por palavra inteira (`termos`, as palavras da nota sem
  acento, entre espaços: vale igual no MySQL e no SQLite); a nota é pontuada no Matriz — título 6, "quando usar" 2,
  texto 1 (até 4) — e precisa de 4 pontos. Validada passa na frente da rascunho com a mesma pontuação.
- **FR-B4, rastreável.** Cada trecho leva `[nota N]`. O Pedro devolve em `fontes` os números que usou; o Matriz
  guarda na mensagem enviada só os de notas que existem e valem, e a conversa os mostra com link para a nota.
- **FR-B5, o Pedro propõe o registro.** O pedido sempre pede o campo `registro` (título, relato, verificado,
  solução, situação) ao abrir atendimento, ao encaminhar ao setor ou quando o cliente diz que resolveu. Uma
  conversa tem uma proposta pendente só: as respostas seguintes a completam. Sem IA (passou ao especialista,
  a IA não respondeu), a proposta sai do resumo que o Pedro já tinha anotado. Consulta interna não propõe.
- **FR-B6, quem confirma.** O último que respondeu a conversa pelo Matriz, ou quem a assumiu; ninguém da
  equipe atendeu, qualquer um com `suporte` de edição. Quem valida também confirma, para o registro não ficar
  preso quando quem atendeu se ausenta. Proposta não vai ao Pedro; descartada fica registrada, fora da base.
- **FR-B7, relato não é fato.** O prompt diz que o "Relato do cliente" não é fato e só "Verificado" é. "Resolvido"
  exige a solução escrita: sem ela, a proposta fica "em curso" e a tela recusa.
- **FR-B8, validado é validado por quem pode.** Procedimento, conhecimento e empresa nascem rascunho; só o
  "Validar" (editor do Pedro ou administrador) os faz VALIDADO, com quem e quando. Mudança de texto feita por
  outra pessoa volta a nota a rascunho; feita por quem valida, mantém e assina de novo.
- **FR-B9, o cliente que sumiu.** A cada hora (`suporte:registros`), a conversa que o Pedro atendeu sozinho, sem
  atendimento aberto, com a última mensagem dele e o cliente calado além da janela de 24 h (até 7 dias), vira
  proposta "encerrado sem confirmação", uma vez só.
- **FR-B10, virar conhecimento.** Do atendimento com solução, "Virar conhecimento" cria um rascunho de
  conhecimento ligado a ele, sem o cliente: quem cria tira o que é só daquele cliente, e quem valida decide.

## Tela

Suporte › Base do suporte: os registros a confirmar no topo (com a contagem na aba), a lista com busca e filtro
por tipo e validação, e cada nota numa página própria (modelo de texto ao criar, Confirmar/Descartar na
proposta, Validar, Virar conhecimento, Apagar). Na conversa, a faixa "O Pedro propôs o registro" leva à proposta.
