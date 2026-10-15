# Central de IA — roteiro de produção

Preparado em 13/10/2026 depois da revisão para produção: suíte inteira verde e migrações conferidas em
MariaDB (subir, desfazer, subir). **Quem publica é o Rossini.** Este documento diz o que configurar, em
que ordem, e o que cada pessoa faz na própria máquina.

## Regras que não se negociam (decisões do Felipe, 13/10/2026)

- **Contas das IAs por pessoa.**
  - Cada um usa o próprio login do Claude, do Codex e do Copilot, na **própria máquina**: Felipe o dele,
    Rossini o dele, Alexandre o dele.
  - O servidor nunca guarda credencial de IA (constitution, Princípio II).
- **Pastas e máquina de cada um são só dele.**
  - O pedido de uma conta só vai ao runner dessa conta.
  - O OneDrive do Felipe só é alcançado pelo runner do Felipe, na máquina dele.
- **Deploy, produção, push e publicação pedidos pela Central exigem SEMPRE o código no Telegram**
  (segundo fator), qualquer que seja a regra escolhida em Ajustes.
  - A máquina sem Telegram configurado não executa esse tipo de pedido.
  - Quem garante isso é o runner (`deploy/central-ia/politica.mjs`, `exigeConfirmacao`), e não o servidor.
- **O método cstk é opção de cada pessoa** (Ajustes → "Implementar pelo método cstk"), desligada por padrão
  (T-374, 14/10/2026: era imposto a todos). Quem liga precisa do plugin cstk no Claude Code da própria
  máquina; o Codex e o Copilot, que não têm os skills, devolvem frente nova ao Claude.
- **Nada preso à máquina de uma pessoa** (T-374, dono do produto, 14/10/2026: "a Central de IA é para um
  time"). Governança pelo perfil, bot da empresa, conexão de máquina pela tela, e o runner sem caminho,
  agenda, memória ou painel de ninguém.

## Antes de publicar

1. **Staging primeiro.** O portão `php artisan test` precisa ficar verde. Em 13/10/2026 passaram 1.597
   testes; o `ArquivoDeTarefasTest` oscila de vez em quando, e é anterior à Central.
2. **`.env` de produção** (em `compartilhado/.env`). Nenhuma destas variáveis derruba o AlfaMatriz se faltar:

   | Variável | Valor | Por quê |
   |---|---|---|
   | `APP_URL` | o endereço da tailnet (`https://alfamatriz.tail0939dd.ts.net`) | O comando de instalação aponta para ele. |

   **Quem administra a Central** (limites e liberação das outras contas) é quem tem o perfil Administrador **e**
   o perfil "Central de IA" (T-374). O `CENTRAL_IA_DONO` saiu; se estiver no `.env`, é ignorado. Conta nova
   com a Central nasce com a execução bloqueada até um desses administradores liberar em Ajustes → Outras contas.
   | `CENTRAL_IA_ACESSO` | `restrito` (padrão; dono do produto, 14/10/2026, T-368) | Quanto o servidor PEDE ao runner. A máquina de cada um ainda impõe o próprio teto. Mesmo no `total`, push, ssh e publicação ficam bloqueados fora de pedido de deploy, que sempre pede o código; o Codex só recebe `total` em pedido de deploy. |
   | `CENTRAL_IA_ARQUIVOS_ROOT` | o `publicar.sh` escreve sozinho | Anexos e prévias em `compartilhado/central-ia`, fora das versões azul/verde. |

3. **Telegram: cada pessoa tem os PRÓPRIOS bots, dois** (T-374; decisão do dono em 14/10/2026 de que cada
   membro tem o seu, e não um bot da empresa para todos; o par segue a decisão da T-368 de que o token do bot do
   código nunca passa pelo servidor). Não há bot no `.env`:
   os antigos `CENTRAL_IA_TELEGRAM_TOKEN`, `_BOT`, `_TOKEN_CONFIRMACAO` e `_BOT_CONFIRMACAO` saíram e, se ainda
   estiverem no `.env`, são ignorados. Cada um cria o seu bot no @BotFather e cola o token em Ajustes →
   Telegram; o servidor confere no Telegram, guarda cifrado e o serviço `alfamatriz-central-ia-telegram` passa
   a ouvi-lo. Esse é o bot da **conversa**. O código de confirmação sai por um **segundo** bot da pessoa, o bot do
   código: o token dele é colado no terminal durante a instalação e fica só no arquivo 0600 da máquina. Se o
   servidor guardasse o token do bot que manda o código, quem o invadisse encaminharia o código a si mesmo e
   confirmaria sozinho. O instalador recusa o bot da conversa no lugar do bot do código.
   - **O Felipe** cola o token do `GrupoSolucoesAgentBot` (o bot de antes) como bot da conversa: é o mesmo bot,
     então a conversa dele continua ligada sem parear de novo. Depois cria o bot do código, toca em Começar nele,
     gera um comando novo em Minhas máquinas e roda-o na máquina dele, colando o token quando ele pedir.
   - Os bots ouvidos são os cadastrados com o `APP_URL` atual: **mudar o `APP_URL` faz todos pararem de ser
     ouvidos, sem erro**, até cada pessoa cadastrar o bot de novo. Não mude o `APP_URL` depois de ligar.
   - O Telegram aceita um leitor só por bot: o mesmo bot não pode estar cadastrado em staging e em produção ao
     mesmo tempo (um derruba o outro com 409, e a tela avisa). Para testar no staging, um bot de teste.
   - Uma cópia do banco em outro lugar não lê os bots: cada bot fica marcado com o `APP_URL` de onde foi
     cadastrado.
   - O serviço do bot sobe mesmo sem bot nenhum cadastrado, e espera.
4. **Homologação:** dada como cumprida pelo Felipe em 13/10/2026.

## Publicar

O fluxo de sempre (`deploy/publicar.sh`). O que ele faz a mais por causa da Central:

- **Anexos e prévias:**
  - cria `compartilhado/central-ia`, com dono `www-data`;
  - acrescenta `CENTRAL_IA_ARQUIVOS_ROOT` ao `.env`, uma vez só.
- **Bot do Telegram:** reinicia `alfamatriz-central-ia-telegram`, se a unidade estiver instalada. Isso vale
  também no "desfazendo" da saúde pública.
- **Migrações:** roda as 17 da Central (`2026_10_10_200000` … `2026_10_14_090000`).
  - São aditivas e criam só tabelas e colunas `central_ia_*`.
  - Os únicos dados que escrevem são o recurso e o perfil `central_ia` e o recurso `central_ia_agentes`, de
    forma idempotente.
  - **O Administrador NÃO recebe a Central** por padrão.

## Depois de publicar (uma vez)

1. **Bot:**
   ```
   cp deploy/central-ia/alfamatriz-central-ia-telegram.service /etc/systemd/system/
   systemctl daemon-reload && systemctl enable --now alfamatriz-central-ia-telegram
   journalctl -u alfamatriz-central-ia-telegram -n 20     # deve mostrar "Ouvindo @…"
   ```
   O LXC precisa de saída para `api.telegram.org`.
2. **Acesso:** dar o perfil "Central de IA" conta a conta, na tela de usuários. Ninguém recebe por padrão.
3. **Conferir a tela:** abrir a Central, as abas Chats Ativos, Business e Ajustes, e o menu lateral
   recolhido e aberto.

## Cada pessoa, na própria máquina (Linux, Windows com WSL, Mac)

Pela tela, sem ninguém rodar nada no servidor (T-374):

1. Tailscale ligado na máquina; Node 18+ e git; login no Claude Code, no Codex e no Copilot **com a própria conta**.
2. Central de IA → Ajustes → Telegram: criar o bot da conversa no @BotFather (`/newbot`), colar o token e tocar em
   "Abrir o seu bot e tocar em Começar".
3. Criar no @BotFather um **segundo** bot, só para o código de confirmação, e tocar em Começar nele. O token dele
   NÃO vai para a tela: o comando do passo 4 pede no terminal (ou ele vem em
   `CENTRAL_IA_TELEGRAM_TOKEN_CONFIRMACAO`). Para trocar esse bot depois, rodar de novo o comando de atualizar.
4. Ajustes → Minhas máquinas → **Gerar comando de instalação** e colar no terminal (no Windows, no Ubuntu do WSL).
   O comando baixa o runner, pergunta a pasta dos projetos, grava `~/.config/alfa/central-ia-runner.env` (0600) com
   o token, o chat do Telegram, o token do bot do código e um segredo de confirmação gerado ali mesmo, e liga o serviço (systemd no
   Linux/WSL, launchd no Mac). O código do comando vale 15 minutos e uma vez só.
5. A máquina aparece na lista em até 30 s. Atualizar depois: o comando curto da mesma tela.

O teto local (`CENTRAL_IA_ACESSO_MAXIMO`, `CENTRAL_IA_CONFIRMAR`, `CENTRAL_IA_ANALISE`) continua no arquivo
da máquina — é ele que torna a máquina inviolável pelo servidor. O `alfa:central-ia-token` continua existindo
para quem precisar emitir à mão.

## O que a revisão de segurança deixou documentado (desenho escolhido, não defeito)

- **Acesso total com rastro** (constitution, Princípio VI, decisão de 10/10/2026). Com o teto `total`, a
  IA faz o que o usuário faria na máquina dele.
  - O que segura é o código no Telegram para deploy e para o início de cada chat.
  - Tudo fica na auditoria: quem viu e quem alterou o quê.
- **Chat já confirmado (regra "início") não pede código de novo por 12 h.** A exceção é deploy, que pede
  sempre. Quem quiser mais rigor escolhe "toda implementação" em Ajustes.
- **O código confere a instrução, a pasta e o acesso, mas não o contexto montado pelo servidor.** Um
  servidor comprometido poderia pôr ordens no contexto. Por isso o servidor de produção é só da tailnet e
  o token do runner é por máquina e revogável (Desconectar, em Ajustes → Minhas máquinas).
- **Quem tem root no servidor lê o banco.** Nenhuma regra de aplicação impede isso. Emitir token de runner
  para a conta de outra pessoa é o mesmo que se passar por ela; pela tela, cada um só gera o seu.
- **O chat do Telegram vai à máquina uma vez, na instalação** (T-374). Depois o servidor não muda para onde o
  código vai: se mudasse, quem o controlasse mandaria o código para o próprio Telegram.
