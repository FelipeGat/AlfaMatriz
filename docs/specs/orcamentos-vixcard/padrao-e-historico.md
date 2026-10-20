# Orçamentos — padrão de proposta, pré-cliente e histórico

**Feature**: `orcamentos-vixcard` (etapa 2) · **Created**: 2026-10-20

**Pedido do Felipe (20/10/2026):** "analise a pasta Orçamentos [...] passe um olhar clínico, analise todas as
propostas que fizemos por cliente, vincule o projeto se achar, caso não ache me pergunte e padronize as propostas.
A ideia é que possamos ter uma capa e conteúdo editável para enviar ao cliente, que possamos editar os dados
variáveis e o PDF se montar enquanto digitamos. Organize esses orçamentos por clientes e mostre eles na tela."

## Base da análise

O inventário de 20/10 está em `inventario-propostas.md`, ao lado desta spec. Foram analisados 19 PDFs de 12
clientes da pasta OneDrive VixCard › Comercial › Orçamentos › Clientes.

- **Três famílias visuais:**
  - template azul VixCard (14 PDFs), em duas gerações: a narrativa de setembro e a contratual de outubro;
  - o mesmo template como VixSoftHouse (2 PDFs);
  - identidade visual do cliente, nos cartões NFC (3 PDFs).
- **O que falta em todas:** nenhuma traz o CNPJ do emissor, e só Las Palmas traz validade.
- **Rodapé da capa:** a 3ª coluna muda de rótulo (Sistema, A/C, Escopo, Local, Evento, Proponente).

## Decisões (20/10/2026)

1. **Cliente:** quem recebe proposta é um **pré-cliente do Funil de Vendas** (`leads`), com os dados básicos quando
   houver. Os 12 foram criados em 20/10 (L41 a L52), com o projeto (`sistema_id`) e a fonte de cada dado nas
   observações.
2. **Emissor:** toda proposta sai como **VixSoftHouse**. Isso confirma a decisão de 19/10, mesmo que 17 dos 19 PDFs
   antigos tenham saído como VixCard.
3. **Histórico:** as propostas antigas são **importadas e remontadas** no padrão novo:
   - cada uma vira um orçamento ligado ao pré-cliente, com o **PDF original anexado**;
   - as versões ficam encadeadas como revisões.
4. **Conteúdo:** a remontagem **mantém o texto como foi enviado**, inclusive o que o produto não tem (Gestão Têxtil e
   importação de marketplace nas propostas da FRID e de Minas). O alerta fica nas observações internas, e o PDF
   original não muda.

## Requisitos

- **FR-H1, pré-cliente:** o orçamento tem `lead_id`. No editor, escolher o pré-cliente preenche nome, contato,
  telefone, e-mail e CNPJ. O projeto vem do pré-cliente, e na falta dele, do produto.
- **FR-H2, lista por cliente:** a tela agrupa os orçamentos por cliente (pré-cliente; sem vínculo, pelo nome).
  - Cada grupo mostra o projeto e o link para o Funil.
  - Por padrão, os substituídos ficam escondidos.
- **FR-H3, padrão da proposta.** A capa e o conteúdo são editáveis, com o PDF ao vivo:
  - **Capa:** tipo (Proposta comercial, Locação, Projeto piloto, Apresentação), revisão, título com destaque,
    subtítulo e a 3ª coluna do rodapé com rótulo livre.
  - **Cliente e objeto:** razão social, CNPJ, IE, endereço, contato e cargo, telefone, e-mail, atividade, objeto
    (lista) e natureza (venda, locação, comodato, assinatura).
  - **Seções opcionais**, ligadas uma a uma: O desafio, A solução, Especificação, Decisões técnicas, Perguntas e
    respostas, Execução e prazo, Contracapa e seções livres.
  - **Investimento:** os itens de hoje, mais planos alternativos e texto livre de condições.
  - **Escopo** (inclui e não inclui), aceite e validade.
- **FR-H4, validade:** pode ficar vazia no histórico, porque o original não tinha. Orçamento novo continua com
  7, 15 ou 30 dias.
- **FR-H5, original:** o orçamento importado guarda o PDF que foi enviado ao cliente. A tela oferece "PDF
  original" ao lado do PDF no padrão novo.
- **FR-H6, importação:** um comando lê o levantamento estruturado (`propostas-import.json`) e cria os
  orçamentos. Ele não duplica se rodar de novo (chave: o arquivo de origem).
- **FR-H7, material, implantação e recorrente (20/10/2026):** "é um calcanhar de Aquiles que preciso que comece
  certo". Cada item tem uma **categoria**:
  - **Material:** equipamentos, etiquetas, cartões;
  - **Implantação:** instalação, configuração, treinamento, serviços;
  - **Recorrente:** mensalidade, licença.

  "Opcional" é uma marca à parte: o item aparece com o preço e fica fora do total, em qualquer categoria. O
  orçamento guarda o total de material, o de implantação, o do projeto (a soma dos dois) e o recorrente. O PDF
  mostra cada bloco com subtotal, e o editor e a lista mostram os três valores. Item antigo e de modelo recebe a
  categoria provável pela descrição (`OrcamentoItem::categoriaProvavel`).
- **FR-H8, lista:** uma tabela só, com cabeçalho único e uma faixa por cliente que se recolhe. A linha inteira abre
  o orçamento; as ações são só PDF e Original.
