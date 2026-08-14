# Spec: Seguranca do painel

> feature: seguranca-do-painel
> status: rascunho

## Contexto

Varredura de segurança do painel inteiro, feita em 14/08/2026. O que ela achou
de bom vale ser dito, porque delimita o que sobra: o isolamento por revenda é
consistente (todo controller de matriz recusa quem tem `revenda_id`, e todo
recurso de revenda confere o dono do registro, não o do formulário); não há
saída sem escape no Blade nem SQL montado com dado de usuário; o disco de
upload está fechado no nginx, inclusive contra execução de PHP; a senha nunca
é gravada em log nem em auditoria; e as dependências não têm aviso aberto.

O que sobra são seis buracos, e nenhum deles é um erro de escrita — todos são
uma porta que ficou aberta porque nunca foi fechada de propósito:

1. A recuperação de senha por e-mail está no ar e **não funciona**: em produção
   o `MAIL_MAILER` é `log` e o `LOG_LEVEL` é `warning`, então o e-mail é
   descartado antes de virar linha. Quem clica lê "enviamos o link" e espera
   para sempre. E o dia em que alguém baixar o nível do log para investigar
   outra coisa, cada pedido passa a gravar um link de redefinição válido, por
   uma hora, num arquivo de texto.
2. Só o login tem limite de tentativas. Confirmar a senha, não.
3. Quem tiver a permissão `usuarios` é, na prática, administrador: ela permite
   marcar o próprio perfil como Administrador e repor a senha de qualquer
   conta lendo a nova na tela. Hoje só o admin a tem — mas o próprio código já
   antecipa, por escrito, o dia em que outro perfil a receber.
4. Nenhuma resposta traz política de conteúdo nem exigência de HTTPS. E os
   cabeçalhos que existem moram só no nginx, fora do alcance da suíte.
5. O endereço de um sistema integrado é texto livre, e é para ele que o painel
   manda a chave de integração no cabeçalho `X-Matriz-Key`.
6. O fechamento de `/storage/` — hoje a única barreira entre um boleto e a
   internet — é uma linha de um arquivo de configuração que nenhum teste lê.

## Histórias

### US-071 — A recuperação de senha que não entrega nada sai do ar

Como dono do painel, quero que a tela de login não ofereça um autoatendimento
de senha que o servidor não consegue cumprir, para que ninguém fique esperando
um e-mail que nunca sai, e para que nenhum link de redefinição válido possa
parar num arquivo de log.

#### AC-260 — A tela de login não oferece mais recuperação de senha

- **Dado** que a pessoa abriu a tela de login
- **Quando** ela procura o link "Esqueci minha senha"
- **Então** ele não está lá (a view já o condiciona a `Route::has('password.request')`, então sumir a rota sumir o link)

#### AC-261 — Os endereços de recuperação deixam de existir

- **Dado** alguém que guardou `/forgot-password` ou `/reset-password`
- **Quando** ele abre ou envia qualquer um dos dois
- **Então** o servidor responde "não encontrado" (404), sem gerar token nenhum

#### AC-262 — O cadastro público não sobra nem como código

- **Dado** que o cadastro público nunca foi roteado, mas o controller e a tela dele continuam no repositório esperando uma linha de rota
- **Quando** se procura por eles
- **Então** não existem — a única porta de conta nova é a tela de usuários e o comando `alfa:criar-usuario`

### US-072 — Confirmar a senha tem limite de tentativas

Como dono do painel, quero que a confirmação de senha pare depois de algumas
tentativas erradas, para que uma sessão deixada aberta não vire uma máquina de
adivinhar a senha de quem a deixou.

#### AC-263 — A sétima tentativa errada de confirmar a senha é recusada

- **Dado** uma sessão aberta e seis tentativas de confirmar a senha já recusadas no último minuto
- **Quando** a sétima é enviada
- **Então** o painel recusa por excesso de tentativas (429), sem sequer conferir a senha

### US-073 — Gerenciar contas não é virar dono do sistema

Como dono do painel, quero que a permissão de usuários não conceda, por tabela,
o poder de administrador, para que abrir a tela de contas a outro perfil não
entregue o sistema inteiro junto.

#### AC-264 — Só administrador concede o perfil Administrador

- **Dado** uma pessoa com permissão de usuários e sem o perfil Administrador
- **Quando** ela salva uma conta — a dela ou a de outro — marcando o perfil Administrador
- **Então** o painel recusa e avisa que só um administrador promove alguém a administrador, e os perfis da conta continuam como estavam

#### AC-265 — Só administrador repõe a senha de um administrador

- **Dado** uma pessoa com permissão de usuários e sem o perfil Administrador
- **Quando** ela pede uma senha nova para uma conta que é administradora
- **Então** o painel recusa e a senha daquela conta continua valendo

### US-074 — O navegador ganha uma segunda linha de defesa

Como dono do painel, quero que toda resposta diga ao navegador de onde ele pode
carregar código e para onde pode mandar dado, para que uma falha de escape em
qualquer tela não vire um caminho para tirar dado de cliente do painel.

#### AC-266 — Toda tela do painel chega com política de conteúdo

- **Dado** qualquer tela do painel, autenticada ou não
- **Quando** a resposta chega ao navegador
- **Então** ela traz `Content-Security-Policy` prendendo carregamento e envio ao próprio site (`default-src 'self'`, `connect-src 'self'`, `form-action 'self'`, `base-uri 'self'`, `object-src 'none'`, `frame-ancestors 'none'`)

#### AC-267 — Toda tela exige HTTPS nas próximas visitas

- **Dado** o painel servido em produção
- **Quando** qualquer tela responde
- **Então** ela traz `Strict-Transport-Security` com validade de ao menos um ano

#### AC-268 — Os cabeçalhos valem também fora do nginx

- **Dado** o painel servido sem o nginx do container (desenvolvimento, ensaio, suíte)
- **Quando** qualquer tela responde
- **Então** `X-Frame-Options`, `X-Content-Type-Options` e `Referrer-Policy` continuam presentes, porque quem os põe passa a ser o aplicativo

### US-075 — O endereço de um sistema integrado não aponta para dentro da rede

Como dono do painel, quero que o endereço de um sistema integrado só possa ser
um endereço público em HTTPS, para que quem edita o catálogo não consiga fazer
o painel entregar a chave de integração a um servidor escolhido por ele.

#### AC-269 — Endereço sem HTTPS é recusado

- **Dado** o formulário de um sistema integrado
- **Quando** alguém salva o endereço começando com `http://`
- **Então** o painel recusa e explica que o endereço precisa ser HTTPS, e o registro não muda

#### AC-270 — Endereço de máquina interna é recusado

- **Dado** o formulário de um sistema integrado
- **Quando** alguém salva um endereço apontando para a própria máquina ou para a rede interna (`localhost`, `127.x`, `10.x`, `172.16–31.x`, `192.168.x`, `169.254.x`)
- **Então** o painel recusa, e a chave de integração não chega a ser enviada a lugar nenhum

### US-076 — O fechamento do disco de anexos não se perde numa edição

Como dono do painel, quero que a suíte acuse se a regra que fecha `/storage/`
sumir do arquivo do nginx, para que a proteção que hoje é a única barreira dos
boletos, das notas e dos prints de defeito não desapareça numa edição de
configuração sem ninguém notar.

#### AC-271 — A suíte acusa se `/storage/` reabrir

- **Dado** o arquivo `deploy/nginx-alfamatriz.conf`
- **Quando** a suíte roda
- **Então** ela confirma que `/storage/` está fechado (`deny all`) e que só `/storage/marcas/` é servido — e falha se qualquer um dos dois deixar de valer

## Fora de escopo

- **Política de senha.** Fica nos 8 caracteres do padrão do Laravel — decisão do
  dono do produto em 14/08/2026, com as duas alternativas (12 caracteres, e
  12 mais checagem de vazamento) apresentadas e recusadas.
- **Configurar SMTP.** A recuperação sai do ar em vez de passar a funcionar.
- **A ação `imprimir`** — ver Q-020.
- **O mapeamento `PUT/PATCH` → `incluir`** — ver Q-021.
- **Reescrever o isolamento por revenda.** A varredura o conferiu controller a
  controller e ele está consistente; mexer nele agora só criaria risco.

## Suposições

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-059 | O painel só é alcançado pelo túnel da Cloudflare, que termina o TLS — por isso o HSTS emitido pelo aplicativo em HTTP local chega ao navegador dentro de uma resposta HTTPS | confirmada | `deploy/cloudflared-alfamatriz.yml` e o `trustProxies` de `bootstrap/app.php` |
| ASM-060 | Ninguém em produção depende da recuperação por e-mail: com `MAIL_MAILER=log` e `LOG_LEVEL=warning`, nenhum link jamais saiu | confirmada | `deploy/.env.producao.exemplo` linhas 26 e 58; `LogTransport` grava em nível `debug` |
| ASM-061 | O Alpine avalia expressão com `new Function`, então a política de conteúdo precisa manter `'unsafe-inline'` e `'unsafe-eval'` em `script-src`. O ganho real é fechar `connect-src`, `form-action`, `base-uri` e `object-src` — ou seja, tirar o canal de saída do dado, não impedir a injeção | aberta | conferir o quadro de tarefas e o Centro de Controle no navegador com a política aplicada |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-020 | A ação `imprimir` é concedida na grade de perfis e **nenhuma rota a confere** — é uma caixa que não faz nada. Ela deve passar a valer em alguma tela (exportação, relatório), ou sair da grade? | aberta | — |
| Q-021 | `PUT` e `PATCH` são lidos como `incluir` pelo `ChecarPermissao`, então quem pode cadastrar também pode reescrever todo registro existente — não há ação `editar`. Isso é intencional? | aberta | — |
