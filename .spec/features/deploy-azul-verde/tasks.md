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

## T-102 — Converter staging e produção no servidor [pendente]
- Refs: US-050, US-052, AC-167, AC-174
- Arquivos: —
- Notas: execução real, nesta ordem e nunca em paralelo — (1) conferir `df -h`
  no LXC (ASM-041); (2) converter o **staging** (LXC 116) rodando
  `deploy/provisionar.sh --ambiente staging`, e só DEPOIS copiar o
  `deploy-staging-alfamatriz.sh` novo para o `/usr/local/bin` do **host
  Proxmox** — ele passa a chamar o `publicar.sh` dentro do container, e chamá-lo
  antes da conversão falharia; (3) publicar uma versão pelo staging e conferir
  a linha do AlfaMatriz no painel AlfaDeploy (ASM-043); (4) só então converter
  a **produção** (LXC 115), fora de horário de uso, com backup do banco
  recém-gerado; (5) conferir que um anexo antigo de cobrança ainda abre depois
  da conversão, e que a `deploy-status.json` continua respondendo na URL
  pública; (6) publicar uma versão de verdade e cronometrar a troca; (7) apagar
  `vendor/` e `node_modules/` da raiz de controle.
- Atenção: o executor de staging vive no **host**, e o
  `provisionar.sh` só instala scripts DENTRO do container. Ele não se atualiza
  sozinho — já aconteceu de uma etapa publicada no repositório ficar inerte em
  produção por causa disso.
