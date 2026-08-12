# Tarefas — deploy-azul-verde

## T-096 — Nginx serve um symlink e ganha porta de ensaio [concluida]
- Refs: US-050, AC-167, AC-168
- Arquivos: deploy/nginx-alfamatriz.conf, tests/Feature/Deploy/ConfiguracaoNginxAzulVerdeTest.php
- Notas: a raiz servida passa a ser `atual/public`, nunca uma pasta de versão.
  Um segundo server block, só em `127.0.0.1:8081`, serve `preparo/public` — é
  por ele que a versão nova é conferida antes de entrar. O `$realpath_root` do
  `fastcgi_param` deixa de ser detalhe e passa a ser o que separa o opcache das
  duas cópias. A telemetria do painel sai de `public/` (que é da versão) para
  a raiz da instalação, servida por `alias`.

## T-097 — O motor da publicação [concluida]
- Refs: US-050, US-051, AC-167, AC-168, AC-169, AC-170, AC-176
- Arquivos: deploy/publicar.sh, tests/Feature/Deploy/ScriptPublicarTest.php
- Notas: prepara a cópia de reserva inteira, confere pela porta de ensaio,
  troca o symlink com `mv -T` (rename atômico) e confere a saúde pública —
  desfazendo a troca se ela reprovar. Sai 0, 1 (não trocou) ou 2 (trocou e
  voltou). Ganha `--portao` para o staging rodar a suíte dentro da cópia em
  preparo. O `mv -T` é do GNU: há fallback para `ln -sfn` porque a suíte roda
  no macOS, e esquema de troca que só funciona no servidor não é testável.

## T-098 — Voltar versão em um comando [concluida]
- Refs: US-051, AC-171, AC-172
- Arquivos: deploy/voltar.sh, tests/Feature/Deploy/ScriptVoltarTest.php
- Notas: troca os symlinks de volta, recusa se a reserva não tiver `vendor`,
  grava o marcador que BLOQUEIA o vigia (senão ele reaplica a mesma tag em 5
  minutos) e atualiza a telemetria do painel. Instalado como
  `/usr/local/bin/alfamatriz-voltar.sh`: numa hora ruim ninguém deveria ter de
  lembrar o caminho do repositório.

## T-099 — Anexos e segredo fora das versões [concluida]
- Refs: US-052, AC-173
- Arquivos: config/filesystems.php, .env.example
- Notas: o disco `public` passa a aceitar `FILESYSTEM_PUBLIC_ROOT`, apontado no
  servidor para `compartilhado/anexos`. Sem isso, um anexo gravado numa versão
  sumiria da vista na troca seguinte — sem erro nenhum, só um download que
  passa a responder "arquivo não encontrado". O `links` usa a mesma raiz: se as
  duas divergirem, o `storage:link` aponta para uma pasta e o app grava noutra.

## T-100 — Converter as instalações que já existem [concluida]
- Refs: US-052, AC-174
- Arquivos: deploy/converter-para-azul-verde.sh, deploy/provisionar.sh,
  tests/Feature/Deploy/ScriptConverterAzulVerdeTest.php
- Notas: idempotente, e construindo tudo ao lado — as dependências são
  copiadas, não movidas, para que uma interrupção no meio deixe a instalação
  antiga inteira e ainda no ar. O diretório de origem vira o clone de controle:
  `.git`, marcadores e `deploy/` continuam onde estavam, e nada que aponta para
  `/var/www/alfamatriz` precisa mudar de endereço (inclusive o painel).

## T-101 — Vigia e staging passam a usar o motor [concluida]
- Refs: US-050, US-051, US-053, AC-170, AC-171, AC-175, AC-176
- Arquivos: deploy/deploy-tag-watcher-alfamatriz.sh,
  deploy/deploy-staging-alfamatriz.sh, deploy/preparar-staging.sh,
  tests/Feature/FluxoDeploy/VigiaTagTest.php,
  tests/Feature/FluxoDeploy/ExecutorStagingTest.php,
  tests/Feature/FluxoDeploy/DeploySemJanelaDeErroTest.php
- Notas: os dois perdem a sua cópia das etapas e chamam `publicar.sh`. O vigia
  trata a saída 2 (trocou e voltou) como falha registrada e para. O executor de
  staging deixa de mesclar código novo no diretório que está no ar para testá-lo
  ali: o portão roda na cópia em preparo, e reprovar não exige mais um
  `git reset --hard` em cima do site vivo.

## T-102a — Converter o staging (LXC 116) [concluida]
- Refs: US-050, US-052, US-053, AC-167, AC-174, AC-175
- Arquivos: —
- Executado em 12/08/2026. Ordem seguida: deploy antigo para levar os scripts
  novos ao container → pausa → `provisionar.sh --ambiente staging` → cópia do
  `deploy-staging-alfamatriz.sh` para o `/usr/local/bin` do host → despausa →
  publicação real. Conferido: `atual`/`preparo` alternando entre as cópias,
  saúde 200 nas duas portas, porta de ensaio respondendo só no localhost,
  sonda do painel devolvendo `SHA=db34acd DIRTY=0 CODE=200`, anexos e `.env`
  em `compartilhado/`, 12 GB livres. Volta de versão cronometrada:
  **0,48 s**. Um deploy completo pelo executor novo levou 90 s, todo ele fora
  do ar da versão publicada.
- **Dois defeitos encontrados aqui, que teriam ido para a produção:**
  1. O `provisionar.sh` trocava a configuração do Nginx antes de converter — o
     site responderia 404 durante toda a conversão.
  2. A troca do cron do `schedule:run` deixava o servidor **sem agendador
     nenhum**: a checagem de "já existe" casava com a linha antiga, e a
     remoção seguinte levava as duas. Não dá erro — só para de rodar. O
     staging ficou 6 minutos assim, entre a conversão e a correção.

## T-102b — Converter a produção (LXC 115) [pendente]
- Refs: US-050, US-052, AC-167, AC-174
- Arquivos: —
- Notas: execução real, fora de horário de uso, nesta ordem — (1) `df -h` no
  LXC 115 (havia 14 GB livres em 12/08); (2) `deploy/backup.sh` para ter cópia
  do banco desta hora, e não a da madrugada; (3) **pausar o vigia**
  (`touch /var/www/alfamatriz/.deploy-paused`) — ele roda de 5 em 5 minutos e
  não pode disparar no meio da conversão; (4) marcar a tag `v*`, que é o que
  move a produção, e deixar o vigia antigo aplicá-la uma última vez pelo
  caminho antigo — é o que leva os scripts novos ao container; (5)
  `deploy/provisionar.sh` (produção), que converte, troca o Nginx, instala o
  vigia novo e o `alfamatriz-voltar.sh` e corrige o cron do agendador; (6)
  **conferir o crontab com os próprios olhos** — o agendador é o item que já
  sumiu em silêncio uma vez (T-102a); (7) despausar; (8) conferir a saúde na
  URL pública, a `deploy-status.json` respondendo pelo `alias` do Nginx e a
  linha do sistema no painel; (9) publicar a tag seguinte e cronometrar a
  troca; (10) só então apagar `vendor/` e `node_modules/` da raiz de controle.
- Diferença em relação ao staging: aqui há **anexos** possíveis. Em 12/08 a
  pasta estava vazia nos dois ambientes; conferir de novo antes, e depois da
  conversão abrir um anexo antigo de cobrança pela tela.
- Atenção: diferente do staging, o vigia da produção roda DENTRO do container e
  é instalado pelo `provisionar.sh` — não há passo manual no host. O script do
  host só existe para o staging.
