# Pedro — base "Contexto do Suporte" (T-476)

**Feature**: `suporte-pedro-whatsapp` (etapa 5)
**Created**: 2026-10-21
**Pedido**: Rossini, 21/10/2026 (T-476): estruturar no OneDrive do Felipe a pasta "Contexto do Suporte", base de
conhecimento em Markdown (estilo Obsidian, notas ligadas por links) que alimenta o Pedro. Cada atendimento vira um
arquivo; a memória do cliente, os procedimentos e o aprendizado ficam versionados na pasta.

## Decisões do dono (21/10/2026)

1. A pasta fica na **raiz do OneDrive do Felipe**, como pasta espelhada própria em Documentos (e não dentro da
   VixCard). O runner da máquina HOME a espelha (`CENTRAL_IA_DOCUMENTOS`).
2. A pasta é **fonte do Pedro** e convive com a tela Suporte › Pedro: a apresentação e os roteiros curtos continuam
   lá; a pasta traz a memória do cliente e os procedimentos longos.
3. O registro do atendimento que o Pedro propõe é **confirmado por quem atendeu** a conversa. O que o Pedro resolveu
   sozinho também vira nota, confirmada por alguém do suporte numa fila "Registros a confirmar" (etapa seguinte).
4. **Quem valida** procedimento e conhecimento: o Felipe ou qualquer administrador.
5. A pasta de cada cliente é `03 - Clientes/<nome curto> · <código>`, com `gestor-<nº>` e/ou `matriz-<nº>`.

A estrutura, as convenções e os exemplos estão em `contexto-do-suporte/Contexto do Suporte/` (o conteúdo inicial,
que o Felipe copia para o OneDrive), com o mapa em `contexto do suporte.md` e os modelos em `_modelos/`.

## Requisitos (etapa 1 e 2: conteúdo inicial e leitura)

- **FR-C1, a base é escolhida na tela.** Em Suporte › Pedro, o editor do Pedro escolhe, entre as pastas espelhadas
  que ele vê em Documentos, qual é a base (`suporte_ajustes.base_pasta`). Sem escolha, o pedido ao Pedro é o mesmo
  de antes, sem uma linha a mais.
- **FR-C2, o Pedro lê a cópia espelhada.** As notas `.md` vêm do disco do Matriz (a cópia do espelho), nunca do
  OneDrive: com a máquina desligada, vale a última leitura, e a tela avisa quando ela passa de 2 h.
- **FR-C3, só o cliente da conversa.** Entra a pasta do cliente CONFIRMADO na conversa, achada pelo código no nome
  da pasta. Sem cliente confirmado, nenhuma pasta de cliente entra. Links para notas de outro cliente não são
  seguidos: só se resolvem links para Empresa, Procedimentos e Conhecimento.
- **FR-C4, um recorte com teto.** Vão, nesta ordem: o mapa; o `contexto.md` do cliente; até 3 atendimentos em
  curso, inteiros; dos 3 últimos encerrados, só o cabeçalho e a "Solução e resultado"; as notas gerais ligadas a
  eles; e até 3 notas gerais que combinam com as últimas mensagens (título e `quando_usar`, a régua do Showroom).
  Cada nota tem um teto próprio e o recorte inteiro, 12 mil caracteres; o que não coube é dito.
- **FR-C5, rastreável.** Cada trecho leva `[arquivo: <caminho>]`. O Pedro devolve em `fontes` os arquivos que usou;
  o Matriz guarda na mensagem enviada só os que existem na base, e a conversa os mostra.
- **FR-C6, validado é validado por quem pode.** Procedimento, conhecimento e nota de Empresa só vão como VALIDADO
  com `validacao: validado` e `validado_por` igual ao nome ou e-mail de um editor do Pedro ou de um administrador
  ativo. O resto vai como RASCUNHO, que o Pedro só cita como possibilidade a confirmar.
- **FR-C7, relato não é fato.** O prompt diz ao Pedro que "Relato do cliente" e "Hipóteses" não são fatos, que só
  "Verificado" é, e que não diga que algo foi resolvido sem a confirmação escrita na nota.
- **FR-C8, modelos e índices ficam fora.** Arquivo ou pasta que começa com `_` não vai ao Pedro.

## Ainda não feito (etapas seguintes)

- **Registro:** o Pedro propõe o atendimento, quem atendeu confirma, e o Matriz grava o `.md` em
  `atendimentos/` pela mão dupla (que já cria arquivo novo). A fila "Registros a confirmar" e o botão "Validar"
  (grava `validado_por`/`validado_em` e deixa o registro de quem foi).
- **Atualizar nota existente:** a mão dupla não sobrescreve (FR-004 de `documentos-mao-dupla`). Para o
  `contexto.md`, a situação do atendimento e os índices, falta a operação "substituir", que só grava se o sha256
  do arquivo no OneDrive ainda for o que o Matriz leu. Muda o runner.

## Para ativar

1. O Felipe cria `Contexto do Suporte` na raiz do OneDrive e copia para dentro o conteúdo inicial.
2. Na máquina HOME, acrescenta a pasta ao `CENTRAL_IA_DOCUMENTOS` do `.env` do runner
   (`VixCard|<caminho>;Contexto do Suporte|<caminho>`) e reinicia o runner.
3. Confere em Documentos que a pasta apareceu, visível só para quem atende o suporte.
4. Depois da publicação, escolhe a pasta em Suporte › Pedro › Base de conhecimento.
