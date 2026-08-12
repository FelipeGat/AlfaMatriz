# Spec: Publicação azul/verde (o esquema do AlfaControl, aqui)

> feature: deploy-azul-verde
> status: rascunho

## Contexto

O AlfaControl publica sem interrupção há tempos: ele mantém dois containers do
backend (`backend-blue` e `backend-green`), sobe a versão nova naquele que está
parado, pergunta `/actuator/health` a ele e só então move o `upstream` do nginx
para o container novo — parando o antigo por último. Se a saúde não responde, o
container novo é derrubado e ninguém percebeu nada.

O AlfaMatriz publicava por cima de si mesmo. `git checkout`, `composer
install`, `npm ci` e `npm run build` rodavam **dentro do diretório que o nginx
estava servindo**. Durante os ~2 minutos de uma publicação, o sistema servia
uma mistura de código velho e novo, com o `vendor/` sendo reescrito por baixo;
um `npm run build` que falhasse no meio deixava a produção quebrada até alguém
entrar no servidor. A conferência de saúde existia, mas só depois — ela
descobria o estrago, não o evitava.

Esta entrega traz o desenho do AlfaControl para cá, trocando "dois containers +
upstream do nginx" por "duas cópias da aplicação + symlink", que é a forma que
serve a um PHP-FPM rodando direto no LXC.

O `fluxo-deploy` (a entrega anterior) listava isto em **Fora de escopo**, com a
justificativa "o AlfaMatriz é container único, como o AlfaHome". A justificativa
não se sustentou: container único não impede duas cópias no disco, e o custo de
não ter isso é uma janela de erro em cima do faturamento a cada publicação.

## Histórias

### US-050 — Publicar não deixa o sistema meio velho e meio novo

Como responsável pela Alfa, quero que uma publicação não seja visível para quem
está usando o sistema, para que marcar uma versão no meio do expediente deixe
de ser uma decisão de risco.

#### AC-167 — A versão nova é montada fora do ar

- **Dado** um sistema publicado e atendendo
- **Quando** uma versão nova é publicada
- **Então** o código, as dependências, o front-end compilado e os caches dessa
  versão são construídos na cópia que **não** está sendo servida, e o que está
  no ar não é tocado durante toda a construção

#### AC-168 — A troca só acontece depois de a versão nova responder saudável

- **Dado** uma versão nova já construída na cópia de reserva
- **Quando** a publicação chega ao fim
- **Então** a saúde dessa cópia é conferida por uma porta de ensaio que só o
  próprio servidor alcança, e a troca só acontece se ela responder 200

#### AC-169 — Falha ao preparar não toca no que está publicado

- **Dado** uma publicação que falha (dependência, build, migração ou ensaio)
- **Quando** ela é interrompida
- **Então** o sistema continua servindo a versão anterior, sem nenhuma
  alteração, e o comando termina com erro dizendo qual etapa falhou

### US-051 — Versão ruim que passou volta sozinha

Como responsável pela Alfa, quero que uma versão que sobe e quebra volte à
anterior sem depender de alguém estar acordado, para que o prejuízo seja de
segundos e não de horas.

#### AC-170 — Saúde reprovada depois da troca desfaz a troca

- **Dado** uma versão que passou no ensaio e foi colocada no ar
- **Quando** a saúde no endereço público não responde 200
- **Então** a troca é desfeita — a versão anterior volta ao ar por onde saiu,
  com dependências e caches intactos — e a saúde é conferida de novo

#### AC-171 — Depois de voltar, a esteira fica bloqueada

- **Dado** uma versão da qual o sistema acabou de voltar
- **Quando** o vigia roda de novo
- **Então** ele não aplica nada: a tag continua sendo a mais recente do
  repositório e, sem o bloqueio, ele a traria de volta em cinco minutos e o
  sistema quebraria sozinho outra vez

#### AC-172 — Voltar à mão é um comando só

- **Dado** um problema percebido por gente, e não pela checagem de saúde
- **Quando** alguém pede a volta da versão
- **Então** ela acontece pela troca de um symlink, sem reconstruir nada, e o
  comando recusa a volta se a cópia de reserva não estiver utilizável

### US-052 — O dado do usuário não pertence à versão

Como responsável pela Alfa, quero que anexo e segredo não morem dentro da pasta
da versão, para que trocar de versão não faça arquivo de cliente sumir.

#### AC-173 — Anexos e segredo vivem fora das cópias

- **Dado** as duas cópias da aplicação
- **Quando** qualquer uma delas está no ar
- **Então** o `.env` e os anexos de cobranças e contas a pagar são os mesmos
  para as duas, guardados fora delas — trocar de versão não muda que arquivo o
  sistema enxerga

#### AC-174 — A conversão de uma instalação existente não perde nada

- **Dado** um servidor no formato antigo, com dados reais
- **Quando** ele é convertido para azul/verde
- **Então** o segredo, os anexos e a versão que estava no ar são preservados, o
  diretório continua atendendo pelo mesmo endereço, e rodar a conversão de novo
  não faz nada

### US-053 — Staging e produção publicam pelo mesmo caminho

Como quem desenvolve, quero que o staging use exatamente o mecanismo da
produção, para que o caminho da publicação seja exercitado antes de valer para
o faturamento.

#### AC-175 — O portão roda dentro da cópia em preparo

- **Dado** uma alteração nova na `main`
- **Quando** o executor de staging roda
- **Então** a suíte de testes roda dentro da cópia que não está no ar; se ela
  reprovar, o staging segue na versão anterior sem ter sido tocado

#### AC-176 — Uma implementação de publicação, dois chamadores

- **Dado** o vigia de tags (produção) e o executor de staging
- **Quando** qualquer um deles publica
- **Então** os dois chamam o mesmo script, e nenhum dos dois tem a sua própria
  cópia das etapas — foi a duplicação que fez a carga de referência existir num
  e faltar no outro, e recurso novo nascer invisível em produção

## Fora de escopo

- **Migração reversível.** As duas cópias dividem um banco só. Uma migração
  aplicada continua aplicada depois de voltar a versão — igual ao AlfaControl,
  onde azul e verde também falam com o mesmo MySQL. Vale a regra de sempre:
  acrescentar numa versão, remover em outra.
- **Mais de duas cópias.** Duas bastam para publicar sem janela e voltar uma
  vez. Histórico de versões no disco é outro problema, e o painel já tem a tela.
- **Zero-downtime para migração destrutiva.** Um `drop column` derruba a versão
  anterior durante os segundos entre a migração e a troca. Isso é disciplina de
  migração, não de publicação.
- **Trocar o painel AlfaDeploy.** A instalação continua se apresentando em
  `/var/www/alfamatriz` como repositório git; nada muda do lado do painel.

## Suposições

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-041 | O LXC comporta duas cópias completas (~600 MB cada, com `vendor` e `node_modules`) além do clone de controle | aberta | Conferir `df -h` antes de converter; o rootfs é de 16 GB |
| ASM-042 | O `opcache` do PHP-FPM está com `validate_timestamps` ligado (padrão do Debian) | aberta | Cada cópia tem caminho próprio, então a chave do opcache já difere; a publicação ainda assim recarrega o PHP-FPM depois da troca |
| ASM-043 | O painel AlfaDeploy continua enxergando o sistema sem alteração: o clone de controle segue em `/var/www/alfamatriz`, com `.git` e marcadores no mesmo lugar | aberta | Conferir a linha do AlfaMatriz no painel depois da conversão do staging |
| ASM-044 | Não há outro consumidor gravando dentro de `storage/app/public` fora do disco `public` do Laravel | aberta | Conferido no código: só `CobrancaController` e `ContaPagarController`, ambos por `Storage::disk('public')` |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-018 | Saúde ruim depois da troca: o vigia volta sozinho ou para e avisa? O `fluxo-deploy` dizia que voltar é decisão humana | respondida | Volta sozinho, em 2026-08-11. Com azul/verde a volta é um `rename` de symlink com a versão anterior intacta — o custo que justificava a decisão humana deixou de existir. Depois de voltar, a esteira fica bloqueada (AC-171) |
