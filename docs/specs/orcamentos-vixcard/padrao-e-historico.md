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
- **FR-H5 revisto (20/10/2026):** "não precisa ter o original, apenas o do sistema". A tela não oferece mais o PDF
  enviado. Os arquivos importados continuam guardados, sem nada apagado, mas não aparecem.
- **FR-H9, numeração e revisão (20/10/2026):** o número é **001, 002…**, e a revisão é **001.Rev2**. O ano fica
  no banco, porque a numeração recomeça a cada ano, mas não aparece no código. **Revisar** vale para qualquer
  situação, rascunho inclusive (como no Gestor): nasce a revisão seguinte e a anterior fica guardada como
  substituída, acessível pela linha da vigente.
- **FR-H10, capa elaborada (20/10/2026):** "com imagem de fundo e clientes que atendemos e informações do cliente".
  - A capa tem **foto de fundo** com degradê. A foto é a do orçamento, enviada no editor; na falta dela, a capa
    padrão dos ajustes; na falta das duas, o degradê azul.
  - Tem um bloco **"Preparado para"** com cliente, razão social, A/C, CNPJ, cidade, telefone, e-mail e
    consultor, e uma faixa **"Clientes que atendemos"** por setor.
  - Os dados vêm da tela **Ajustes dos orçamentos**: emissor, clientes e capa padrão. A lista nasce da
    apresentação Radier v2, já enviada. Os dados do emissor que nenhuma proposta trazia (CNPJ, razão social,
    endereço) começam vazios e não saem no PDF até serem preenchidos.
- **FR-H11, painel (20/10/2026):** "nossa tela está sem vida, sem KPI", com o modelo de tela que o Felipe mandou.
  - **Período:** 7, 30 ou 90 dias, este mês ou tudo. Vale para os indicadores e os gráficos, sempre sobre as
    versões vigentes. A data de cada orçamento é a do envio; a do rascunho, a da criação.
  - **Indicadores:** total no período (comparado ao período anterior), em aberto, aprovados, em negociação e
    recusados, cada um com quantidade, valor, % do total e barra.
  - **Gráficos:** evolução por dia (por semana acima de 31 dias), em barras empilhadas por situação; rosca por
    situação; top clientes por valor.
  - **Lista:** filtros de busca, situação, projeto e responsável. Colunas: número com a data e as revisões
    anteriores, cliente com a proposta, projeto, valor (material + implantação e mensal), situação, validade com
    os dias que faltam, responsável, atualizado e ações (abrir, PDF e menu com Revisar, Duplicar e Funil).
  - **Nova situação, "Em negociação":** entre o envio e a resposta, o cliente respondeu e se negocia. Conta como
    em aberto.
- **Pasta no OneDrive:** desde 20/10/2026, o PDF enviado vai para `Novos_Projetos/Orçamentos/Clientes`, o lugar
  novo da pasta.
- **FR-H12, páginas no desenho da FRID (20/10/2026):** "o PDF está cheio de texto, cansativo de ler [...] replique
  [a proposta do Igor], com páginas bem definidas e com imagens que já existem nos PDFs importados".
  - **Uma página por assunto**, com cabeçalho (tipo · projeto · VixSoftHouse) e rodapé fixos: capa; Cliente e
    desafio (a solução divide a folha só se couber); uma página por seção livre com imagem ou texto longo (as
    curtas se juntam na mesma folha, cada uma com o seu rótulo); Na prática (duas imagens por página); Escopo do
    fornecimento (tabela, decisões e, embaixo, a imagem ao lado do que está e do que não está incluso);
    Perguntas; Investimento e execução (execução e aceite abrem página própria quando não cabem).
  - **Listas legíveis de relance:** o começo de cada linha, até o primeiro ponto, dois-pontos ou travessão, sai em
    negrito; etapas numeradas 01, 02…; linha curta em maiúsculas vira subtítulo.
  - **Capa:** azul da FRID (ou a foto com véu), tipo e revisão, título em duas linhas, e embaixo cliente,
    proponente (com o CNPJ do emissor, quando houver) e data, mais a faixa "Clientes que atendemos".
  - **Investimento:** material, implantação e recorrente em grupos na mesma tabela; opcionais à parte, fora do
    total; a faixa do total traz a divisão material + implantação, e o recorrente vem numa faixa própria.
  - **Imagens do corpo** (`orcamentos.imagens`, JSON: arquivo, legenda, onde): enviadas na aba Conteúdo, cada uma
    com a página onde entra (galeria, desafio, solução, escopo ou uma seção livre). A revisão e a cópia levam a
    lista, sem duplicar arquivo; tirar da lista não apaga o arquivo. O caminho só se aceita se já era do
    orçamento. Em pé (celular, folheto) vão lado a lado; deitadas ocupam a largura.
  - **Histórico:** as imagens foram tiradas dos PDFs originais e postas de volta pelo comando
    `orcamentos:importar-imagens`. O PDF de um orçamento importado acompanha o modelo (o que foi ao cliente é o
    original); o de um enviado pelo Matriz continua congelado. Rascunho e importado se regeram quando o modelo
    muda (`Pdf::versaoDoModelo`).
