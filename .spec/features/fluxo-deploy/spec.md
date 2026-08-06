# Spec: Fluxo de deploy no padrão da casa (staging + produção por tag)

> feature: fluxo-deploy
> status: rascunho

## Contexto

Os seis sistemas da Alfa seguem um fluxo próprio, gerenciado pelo painel
AlfaDeploy: cada um tem **staging**, que acompanha a `main` sozinho, e
**produção**, que só muda quando alguém marca uma versão (tag `v*`). O painel
mostra todos numa tabela com versão, saúde e nó.

O AlfaMatriz é o único fora desse fluxo: subiu direto em produção (LXC 115),
não tem staging, não aparece no painel e não tem verificação automática de
código. Esta entrega o traz para o mesmo padrão dos irmãos.

Uma diferença que a entrega precisa respeitar: nos outros sistemas quem segura
o deploy de staging é o portão de CI (a imagem só existe no GHCR se a
verificação passou). O AlfaMatriz não publica imagem — ele compila no próprio
servidor. O portão equivalente aqui é a suíte de testes do projeto, que já
existe e cobre 49 casos.

## Histórias

### US-028 — O AlfaMatriz aparece no painel junto com os outros

Como responsável pela Alfa, quero ver o AlfaMatriz na mesma tabela dos demais
sistemas, para acompanhar versão e saúde dele sem precisar entrar no servidor.

#### AC-063 — O painel lista o AlfaMatriz com os dados de acompanhamento

- **Dado** o inventário do painel AlfaDeploy
- **Quando** o painel é carregado
- **Então** o AlfaMatriz aparece como um sistema, com o container do staging, o
  diretório da aplicação e o endereço de checagem de saúde preenchidos

#### AC-064 — O painel não oferece ações que destruam os dados reais

- **Dado** que o AlfaMatriz guarda dados reais de clientes e do financeiro
- **Quando** o inventário do painel é lido
- **Então** o AlfaMatriz não traz os campos que alimentam a re-anonimização e a
  restauração de contas de teste — sem eles, essas ações não têm alvo e não
  podem embaralhar a base

### US-029 — A mudança na main chega sozinha no staging

Como quem desenvolve, quero que toda alteração na `main` apareça no staging sem
ninguém fazer nada, para conferir o resultado antes de pensar em produção.

#### AC-065 — O staging acompanha a main automaticamente

- **Dado** uma alteração nova na `main`
- **Quando** o executor de staging roda
- **Então** ele traz o código, instala dependências, compila o front-end,
  aplica migrações e recarrega os caches do ambiente de staging

#### AC-066 — Código com teste falhando não entra nem no staging

- **Dado** uma alteração na `main` cuja suíte de testes falha
- **Quando** o executor de staging roda
- **Então** ele para antes de aplicar qualquer coisa, registra que o portão
  reprovou e deixa o staging na versão anterior — é o que substitui, aqui, o
  portão de CI dos outros sistemas

### US-030 — Produção só muda quando eu marco a versão

Como responsável pela Alfa, quero que a produção só se altere quando eu marcar
uma versão, para que nada chegue ao faturamento sem uma decisão explícita.

#### AC-067 — A produção aplica a versão marcada, e só ela

- **Dado** o ambiente de produção rodando uma versão
- **Quando** existe uma tag `v*` nova no repositório
- **Então** o vigia aplica essa tag em produção; e enquanto não houver tag
  nova, nenhuma alteração da `main` chega em produção

#### AC-068 — Backup antes de migrar e saúde conferida depois

- **Dado** a produção prestes a receber uma versão nova
- **Quando** o vigia aplica a versão
- **Então** ele gera uma cópia do banco antes de aplicar migrações e confere a
  saúde depois; falhando a checagem, ele para, registra a falha e não tenta de
  novo sozinho — para não insistir em cima de um sistema quebrado

### US-031 — O staging não carrega cliente real

Como responsável pela Alfa, quero que a base de staging seja uma cópia
embaralhada da produção, para poder testar à vontade sem que CNPJ, e-mail e
telefone de cliente real fiquem numa segunda base.

#### AC-069 — A cópia para staging troca os dados pessoais por falsos

- **Dado** uma cópia do banco de produção
- **Quando** ela é preparada para o staging
- **Então** nomes, e-mails, telefones e CNPJ de clientes saem trocados por
  dados falsos, as senhas viram uma senha de teste conhecida, e os valores do
  financeiro continuam intactos — é o volume real que torna o teste útil

## Fora de escopo

- Blue-green ou implantação sem interrupção: o AlfaMatriz é container único,
  como o AlfaHome.
- Rollback automático: falhou, o vigia para e avisa; voltar versão é decisão
  humana (o painel já tem a tela de versões).
- Migrar os outros sistemas para o portão por testes: eles continuam no CI/GHCR.
- Publicação de imagem no GHCR: o AlfaMatriz compila no servidor.
- Anonimização contínua: o embaralhamento acontece quando a cópia é feita, não
  de forma automática a cada dia.

## Suposições

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-023 | Há folga no alfa-server para mais um container: o staging pede ~2 GB de RAM e 16 GB de disco | aberta | Confirmar antes de criar: o host tem 11 GB de RAM com ~5,6 GB disponíveis e 346 GB livres no storage `dados` |
| ASM-024 | O painel AlfaDeploy tolera um sistema sem os campos de anonimização e sem imagem de CI | aberta | Confirmar carregando o painel depois do cadastro; o código lê esses campos com `.get()` em alguns pontos |
| ASM-025 | Rodar a suíte de testes no servidor como portão é aceitável em tempo (hoje ~7s) e não depende de serviço externo | aberta | Confirmar na primeira execução do portão |
| ASM-026 | O staging usa o próprio endereço do tailnet, sem domínio público nem túnel Cloudflare | aberta | É o padrão dos outros stagings; confirmar que atende |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-010 | O banco do staging leva os dados reais da empresa ou uma cópia embaralhada? Os outros stagings são anonimizados, mas conferir cálculo de faturamento pode exigir número real | respondida | Embaralhada, em 2026-08-05 — mesmo padrão dos outros stagings. Virou a US-031 |
