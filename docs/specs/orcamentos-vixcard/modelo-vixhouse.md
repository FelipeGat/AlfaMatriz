# Orçamentos — modelo VixHouse do PDF

**Feature**: `orcamentos-vixcard` (etapa 3) · **Created**: 2026-10-25 · **Status**: implementado no local (8096), sem publicação

**Pedido do Felipe (25/10/2026, Central de IA, chat "Proposta Comercial VixCard")**, depois de aprovar a proposta
conceito v4 do Gestor de Eventos para a Inove Audiovisual (Gustavo):

> "Ficou excelente, quero que registre em orçamento aqui no matriz com empresa vixcard. Vendedor Felipe. E gere na
> sua memória sempre usar esse mesmo modelo de proposta para TODOS os clientes que for sistema. Faça esse mesmo
> modelo e deixe ele disponível para geração do pdf quando eu for imprimir por exemplo um orçamento da vixtrace, ou
> vix-id, etc."

O desenho de referência é o da v4 (`PROPOSTA_CONCEITO_GESTOR_EVENTOS_INOVE_v4.pdf`, fonte em
`C:\tmp\gustavo_conceito`): A4 em pé, capa marinho com o logo VixHouse, uma página por assunto, a dor antes da
solução, telas com o selo de tela ilustrativa, investimento com a forma de pagamento ao lado de cada valor e o parecer
do cliente no fim, assinado só como Diretor Comercial.

## Decisões (25/10/2026)

1. **Dois modelos, um conteúdo.** O orçamento ganha o campo `modelo`: `vixhouse` (sistemas) ou `classico` (o azul
   VixCard de até 24/10). Os dois leem os MESMOS campos; trocar de modelo nunca perde dado.
2. **Quem nasce em qual:** todo produto é sistema e nasce VixHouse, menos Cartões NFC (peça impressa com a arte do
   cliente), que nasce clássico (`Modelos::FORA_DO_MODELO_VIXHOUSE`). A escolha fica editável na aba Capa.
3. **O que já existia fica como estava:** a migração marca todos os orçamentos existentes como `classico`, para o PDF de
   um rascunho ou de um histórico importado não mudar de cara sem ninguém pedir. O importador do histórico também
   cria no clássico, salvo se o levantamento pedir `"modelo": "vixhouse"`.
4. **Marca:** o modelo VixHouse usa o logo oficial (`Comercial/marketing/Logo corporativo VIXHouse.png`, recortado
   sem fundo em `public/marcas/vixhouse-wordmark*.png`) e as fontes do site (Plus Jakarta Sans e Inter). O contato
   e o CNPJ do emissor continuam vindo dos Ajustes. O clássico segue como VixSoftHouse.
5. **Assinatura:** "VixHouse · Diretor Comercial", sem o nome de quem fez o orçamento (regra das propostas VixCard).
6. **"Empresa VixCard":** no Matriz não há campo de empresa no orçamento. A empresa vem do projeto do pré-cliente
   (natureza `vixcard`, por exemplo "Gestor VixCard"); o vendedor é o `vendedor_id` do pré-cliente e o responsável
   do orçamento (`user_id`).

## Requisitos

- **FR-V1, escolha do modelo:** `orcamentos.modelo` (string, padrão `vixhouse`); select "Modelo do PDF" na aba
  Capa; escolher o produto troca o modelo para o do produto; formulário sem o campo não apaga o que estava; revisão e
  cópia levam o modelo.
- **FR-V2, páginas do modelo VixHouse** (`resources/views/orcamentos/pdf/vixhouse.blade.php`), na ordem:
  1. **Capa:** logo, tipo e revisão, título com o destaque em azul (na mesma frase quando o título termina em
     artigo ou preposição), a frase, no meio a foto da capa ou a primeira tela (sem nenhuma, os clientes que
     atendemos), e embaixo cliente, 3ª coluna, documento (nº de páginas) e data/validade.
  2. **Cliente e diagnóstico:** dados do cadastro, objeto e a dor. Linhas do desafio com `|` viram a tabela da dor
     ("ponto | o que vimos | o que causa | como resolve"; uma 1ª linha `# A | B | …` troca os títulos).
  3. **A proposta:** os passos da solução em cartões numerados (`Título: descrição`) e, havendo três telas ou mais, o
     índice "As telas desta proposta" com a página de cada uma. Divide a folha com o diagnóstico quando as duas
     cabem.
  4. **Seções livres**, como no clássico.
  5. **Telas** (lugar de imagem novo, `telas`): duas por página, numeradas, com o selo "Tela ilustrativa · dados
     fictícios"; **Na prática** para fotos (galeria, escopo e, com a tabela da dor, as do desafio).
  6. **Escopo:** incluso (com check), especificação, decisões técnicas (página própria quando não cabem) e não
     incluso.
  7. **Investimento:** planos em cartões, valores por grupo com a **forma de pagamento de cada item**, opcionais fora
     do total, totais e a condição. Sem parcelamento cadastrado e com a condição só no texto, a coluna diz "Conforme a
     condição abaixo" em vez de um "à vista" falso.
  8. **Próximos passos e parecer:** passos (sem repetir a numeração do texto), prazo, observações e o parecer
     (aprovo / com ajustes / conversar; plano pretendido quando há planos; assinaturas).
- **FR-V3, papel sem sobra:** nenhuma página pode passar da folha (vira uma folha a mais com o rodapé sozinho). As
  alturas são estimadas para decidir o que divide a folha; conferido no PDF rasterizado (ver Testes).
- **FR-V4, registro da Inove:** o orçamento da proposta conceito v4 entra pelo importador oficial
  (`orcamentos:importar-historico` + `orcamentos:importar-imagens`), como rascunho, com o PDF v4 guardado como
  original e as 13 telas. Pacote em `C:\tmp\matriz_vixhouse\registro` (fonte: `tools/registro.py`).

## Plano técnico

| Arquivo | Mudança |
|---|---|
| `database/migrations/2026_10_25_120000_orcamento_modelo_vixhouse.php` | coluna `modelo`; existentes → `classico` |
| `app/Models/Orcamento.php` | `MODELOS`, padrão `vixhouse`, lugar de imagem `telas` |
| `app/Services/Orcamentos/Modelos.php` | `modeloDoPdf()`, `FORA_DO_MODELO_VIXHOUSE`; o modelo vai no formulário |
| `app/Services/Orcamentos/Orcamentos.php` | grava/duplica o modelo; sem escolha, o do produto |
| `app/Services/Orcamentos/Historico.php` | importa no clássico, salvo `"modelo"` no levantamento |
| `app/Services/Orcamentos/Pdf.php` | escolhe a view pelo modelo; logos pela URL (prévia) ou disco (PDF) |
| `app/Http/Controllers/OrcamentoController.php` | valida e devolve `modelo` |
| `resources/views/orcamentos/pdf/vixhouse.blade.php`, `_vixhouse_linha`, `_vixhouse_passos` | o modelo |
| `resources/views/orcamentos/pdf.blade.php` | o clássico mostra as `telas` junto das fotos |
| `resources/views/orcamentos/editor.blade.php` | select "Modelo do PDF" e a dica da tabela da dor |
| `public/fonts/{inter,jakarta}-*.woff2`, `public/marcas/vixhouse-wordmark*.png` | fontes e logo |
| `tests/Feature/Orcamentos/ModeloVixHouseTest.php` | 3 testes novos; os antigos fixam o clássico onde é ele que testam |
| `tests/Feature/Redesign/{Componentes,Tokens}Test.php` | o PDF VixHouse é papel, como o clássico: fora da regra de tema |

## Testes

- `php artisan test tests/Feature/Orcamentos`: 17 passam (14 antigos + 3 novos).
- Suíte inteira (25/10/2026): 2.055 passam; 5 falham no ambiente local, fora do que mudou (runner da Central,
  executor de staging, VixOrganize, script do quadro e WebP do GD).
- PDF conferido de verdade: HTML do `Pdf::html` impresso pelo Chromium (Playwright 1.55 em Docker) e rasterizado
  (`conferir_pdf.py`): o orçamento da Inove (12 páginas) e quatro do histórico renderizados no VixHouse sem gravar
  (FRID, MedSenior, Clube das Madames, ENCEJ) — nenhuma página encolhida nem passando da folha.

## Pendências

- Publicar (tag) só com a liberação do Felipe, depois de ver no 8096.
- Registrar a Inove na **produção**: rodar os dois importadores com o pacote acima (o `ssh` da Central é negado).
- VixTrace não tem modelo de produto (entra como "Outro" com o projeto VixTrace no pré-cliente); textos-padrão dele,
  se o Felipe quiser.
- Os Ajustes de produção ainda dizem "VixSoftHouse" no `emissor_nome` (o clássico usa).
