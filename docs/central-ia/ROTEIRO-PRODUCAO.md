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

## Antes de publicar

1. **Staging primeiro.** O portão `php artisan test` precisa ficar verde. Em 13/10/2026 passaram 1.597
   testes; o `ArquivoDeTarefasTest` oscila de vez em quando, e é anterior à Central.
2. **`.env` de produção** (em `compartilhado/.env`). Nenhuma destas variáveis derruba o AlfaMatriz se faltar:

   | Variável | Valor | Por quê |
   |---|---|---|
   | `CENTRAL_IA_DONO` | e-mail do Felipe em produção | Só o dono administra os limites dos outros, e só ele (ou a própria pessoa) altera uma conta com a Central. |
   | `CENTRAL_IA_TELEGRAM_TOKEN` | token do bot | Sem ele: sem Telegram, sem pareamento, sem código de confirmação. |
   | `CENTRAL_IA_TELEGRAM_BOT` | nome do bot | Só se for um bot diferente do `GrupoSolucoesAgentBot`. |
   | `CENTRAL_IA_ACESSO` | `total` (decisão do Felipe) ou `restrito` | Quanto o servidor PEDE ao runner. A máquina de cada um ainda impõe o próprio teto. |
   | `CENTRAL_IA_ARQUIVOS_ROOT` | o `publicar.sh` escreve sozinho | Anexos e prévias em `compartilhado/central-ia`, fora das versões azul/verde. |

3. **Telegram: um consumidor por bot.** Se produção usar o mesmo bot do ambiente local, o bot local
   (`docker stop alfamatriz-dev-telegram`, na máquina do Felipe) tem que estar PARADO antes de ligar o de
   produção. Ligados os dois, o Telegram derruba ambos (409). A alternativa é criar um bot só para produção.
   **Decisão do Felipe pendente.**

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

## Cada pessoa, na própria máquina

1. Fazer login no Claude Code, no Codex e no Copilot **com a própria conta**.
2. Receber o token da própria conta: `php artisan alfa:central-ia-token <email> --nome=<maquina>`.
   - O token aparece uma vez.
   - **Só para a própria pessoa.** Emitir token com o e-mail de outra conta é se passar por ela.
3. Instalar o runner conforme `deploy/central-ia/README.md`, copiando TODOS os módulos `.mjs`. Em
   `~/.config/alfa/central-ia-runner.env` (permissão 0600):
   - `ALFAMATRIZ_URL=https://alfamatriz.tail0939dd.ts.net` (a máquina precisa estar na tailnet);
   - o token do passo 2;
   - `CENTRAL_IA_ACESSO_MAXIMO`: o teto desta máquina;
   - `CENTRAL_IA_CONFIRMAR`: o mínimo de confirmação desta máquina. **Na do Felipe: `inicio`.**
   - Para usar o código pelo Telegram: `CENTRAL_IA_TELEGRAM_TOKEN`, `CENTRAL_IA_TELEGRAM_CHAT_ID` e
     `CENTRAL_IA_SEGREDO_CONFIRMACAO`, que é um segredo de 64 caracteres hexadecimais **da própria pessoa**.
4. Parear o Telegram em Ajustes → Telegram.

## O que a revisão de segurança deixou documentado (desenho escolhido, não defeito)

- **Acesso total com rastro** (constitution, Princípio VI, decisão de 10/10/2026). Com o teto `total`, a
  IA faz o que o usuário faria na máquina dele.
  - O que segura é o código no Telegram para deploy e para o início de cada chat.
  - Tudo fica na auditoria: quem viu e quem alterou o quê.
- **Chat já confirmado (regra "início") não pede código de novo por 12 h.** A exceção é deploy, que pede
  sempre. Quem quiser mais rigor escolhe "toda implementação" em Ajustes.
- **O código confere a instrução, a pasta e o acesso, mas não o contexto montado pelo servidor.** Um
  servidor comprometido poderia pôr ordens no contexto. Por isso o servidor de produção é só da tailnet e
  o token do runner é por máquina e revogável (`--revogar`).
- **Quem tem root no servidor lê o banco.** Nenhuma regra de aplicação impede isso. Emitir token de runner
  para a conta de outra pessoa é o mesmo que se passar por ela; o combinado é cada um emitir só o seu.
