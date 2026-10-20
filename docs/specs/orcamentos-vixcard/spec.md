# Feature Specification: Orçamentos VixCard

**Feature**: `orcamentos-vixcard`
**Created**: 2026-10-19
**Status**: Implementado em 19/10/2026
**Pedido**: Felipe, 19/10/2026: "Em Comercial tem que ter uma tela chamada Orçamentos, e essa tela será responsável
para criarmos e imprimirmos os orçamentos nos padrões tipo Proposta que estamos fazendo. Quero que analise a tela
de orçamentos do Gestor do Grupo Soluções e desenhe a tela que possamos fazer funcional e prática. Ao criar um
novo orçamento sempre será impressa um PDF padrão VixCard mudando somente os campos necessários."
**Protótipo**: https://claude.ai/artifact/H4Djq761zSgEBV35REbHHk

## Base da análise

**Gestor** (`~/projetos/gestor`):
- `Orcamento` com cerca de 40 campos no formulário e 12 situações, que misturam venda com execução.
- Itens com quantidade inteira.
- PDF pelo dompdf, que só aceita layout de tabela.
- Proposta caprichada = um Blade por orçamento (`proposta_orc_{id}`), ou seja, código por cliente.
- Vale aproveitar: duplicar, revisar com substituição, prévia em HTML e a frase de pagamento pronta.

**Propostas VixCard**: Marmoraria, Jumper Four, Clube das Madames e Gestor/Gustavo.
- Foram geradas de HTML pelo Chromium, em A4, com capa navy (`#0b2655` → `#123a7a`) e destaque `#8fc0f5`.
- Ordem das seções: Capa → Contexto → Especificação → Escopo → Investimento → Próximos passos → Aceite/contracapa.
- Variam por proposta: cliente, título e destaque da capa, frase da capa, itens, condições de pagamento, inclui e
  não inclui, e passos.

## Escopo proposto

1. **Lista**:
   - número `NNN/AAAA` com revisão, cliente, produto, total (com a mensalidade à parte), validade e situação;
   - ações PDF, Revisar e Duplicar.
2. **Editor** com prévia ao vivo do PDF:
   - Produto: preenche capa, escopo, passos e itens-modelo;
   - Cliente: nome, contato, cidade/UF e validade;
   - Capa: título, destaque e frase;
   - Itens: descrição, quantidade decimal, unidade, unitário, valor único ou mensal;
   - Pagamento: entrada, parcelas e desconto à vista; a frase é gerada;
   - Escopo: inclui, não inclui e passos;
   - Aceite: liga ou desliga.
3. **Situações**: Rascunho, Enviado, Aprovado, Recusado e Substituído.
4. **PDF padrão VixCard**: um único template com blocos opcionais (Contexto, Especificação com fotos, Planos
   recorrentes). Nunca um layout por cliente.

## Decisões pendentes (Felipe)

1. Quem usa: Felipe e Victor (recomendado).
2. Cliente: cadastro simples dentro do orçamento, sem passar pelas revendas da Alfa (recomendado).
3. Motor do PDF:
   - Chromium no servidor do Matriz (recomendado; o servidor tem cerca de 2 GB de RAM);
   - ou geração pela máquina do Felipe, que só funciona com ela ligada.
4. Guardar o PDF também em Documentos › VixCard › Comercial › Orçamentos › Clientes › <cliente> (mão dupla com o
   OneDrive).
5. Textos-padrão por produto: Vix-ID, VixLink, Cartões NFC, Vix-Beer e Gestor VixCard, com uma tela de modelos.
6. Os blocos opcionais que entram na primeira entrega.

## Decisões (19/10/2026)

- **Uso:** Felipe e Victor, com perfil próprio fora do administrador.
- **Cliente:** cadastro simples dentro do próprio orçamento.
- **PDF:** gerado no servidor pelo Chromium (pacote `chromium`, `/usr/bin/chromium`).
- **Documentos:** o PDF da revisão enviada é guardado em Documentos › VixCard › Comercial › Orçamentos › Clientes.
- **Marca:** toda proposta sai como **VixSoftHouse**.
- **Valores:** tratados caso a caso; os modelos nascem com R$ 0. Modelos por sistema em `modelos-por-sistema.md`.
