# Spec: Deploy no alfa-server com acesso externo

> feature: deploy-alfa-server
> status: rascunho

## Contexto

O AlfaMatriz roda hoje só na máquina de desenvolvimento. A equipe da Alfa
precisa usá-lo de fora da rede da empresa (casa, celular, cliente), com os
dados reais do negócio — revendas, clientes com CNPJ, faturamento e o
financeiro da própria Alfa. Não haverá anonimização.

A entrega coloca o sistema num container LXC dedicado no alfa-server
(Proxmox) e o publica numa URL pública HTTPS via Tailscale Funnel. Como a URL
é aberta na internet, a única barreira entre um desconhecido e os dados
financeiros da empresa passa a ser a tela de login — por isso o endurecimento
da autenticação faz parte desta entrega, não de uma próxima.

## Histórias

### US-001 — Só quem é da equipe entra

Como responsável pela Alfa, quero que apenas contas criadas por mim acessem o
painel, para que a URL pública não vire porta de entrada para estranhos.

#### AC-001 — Ninguém cria a própria conta

- **Dado** o painel publicado numa URL pública
- **Quando** um visitante abre a tela de cadastro ou tenta enviar um cadastro
  novo (`GET`/`POST /register`)
- **Então** o painel responde que a página não existe (404) e nenhuma conta é
  criada

#### AC-002 — Conta nova só por comando administrativo

- **Dado** um operador com acesso ao servidor
- **Quando** ele roda o comando de criação de usuário informando nome, e-mail e
  senha (`php artisan alfa:criar-usuario`)
- **Então** o usuário passa a existir e consegue entrar no painel, e o comando
  recusa e-mail já cadastrado sem sobrescrever a senha de quem já existe

#### AC-003 — Tentativa repetida de senha é bloqueada

- **Dado** um visitante tentando adivinhar a senha de um e-mail válido
- **Quando** ele erra a senha cinco vezes seguidas e tenta a sexta
- **Então** o painel recusa a tentativa avisando que ele deve esperar antes de
  tentar de novo, em vez de continuar aceitando tentativas

### US-002 — O painel funciona certo atrás da publicação externa

Como usuário do painel, quero que os links, formulários e o login funcionem
pela URL pública, para que o sistema não quebre por estar atrás do Funnel.

#### AC-004 — Os endereços gerados usam HTTPS

- **Dado** o painel atrás do proxy do Funnel, que entrega a requisição em HTTP
  interno sinalizando HTTPS no cabeçalho (`X-Forwarded-Proto: https`)
- **Quando** o painel gera um link ou um redirecionamento
- **Então** o endereço sai em `https://`, sem mistura de conteúdo inseguro

#### AC-005 — Existe uma checagem de saúde sem login

- **Dado** o painel no ar
- **Quando** alguém (ou o script de verificação) acessa `/healthz`
- **Então** a resposta é 200 com o estado do sistema e da conexão com o banco,
  sem exigir login e sem revelar dado de negócio

### US-003 — Produção não carrega os atalhos do ambiente local

Como responsável pela Alfa, quero que o ambiente publicado não use a senha de
exemplo do README nem exponha detalhes internos em erro, para que os dados
reais não fiquem à mercê de um descuido de configuração.

#### AC-006 — A senha do admin vem do ambiente, nunca fixa no código

- **Dado** a carga inicial do banco em produção
- **Quando** ela roda sem uma senha de administrador definida no ambiente
  (`ADMIN_PASSWORD`)
- **Então** a carga falha avisando que a senha é obrigatória, em vez de criar o
  admin com a senha de exemplo publicada no README

#### AC-007 — O ambiente de produção nasce endurecido

- **Dado** o modelo de configuração de produção usado no deploy
  (`deploy/.env.producao.exemplo`)
- **Quando** o operador o usa como base do ambiente publicado
- **Então** ele já vem sem modo de depuração, com ambiente `production`,
  endereço em `https://` e cookie de sessão restrito a HTTPS

### US-004 — Subir e re-subir o sistema é repetível

Como operador, quero provisionar, publicar e conferir o sistema por scripts,
para que uma nova versão (ou uma recriação do container) não dependa de
lembrar a sequência certa.

#### AC-008 — Provisionar duas vezes não quebra nada

- **Dado** o script de provisionamento do container
  (`deploy/provisionar.sh`)
- **Quando** ele é executado uma segunda vez sobre um servidor já provisionado
- **Então** ele reconhece o que já existe e segue sem recriar o container nem
  apagar o banco, terminando com sucesso

#### AC-009 — Publicar uma versão é um comando só

- **Dado** o container já provisionado
- **Quando** o operador roda o script de publicação (`deploy/publicar.sh`)
- **Então** o script traz o código, instala dependências de produção, compila o
  front-end, aplica as migrações, aplica as cargas de referência e recarrega os
  caches — parando com erro claro se qualquer etapa falhar

#### AC-009b — Publicar deixa as cargas de referência em dia

- **Dado** uma versão que acrescentou um recurso novo ao cadastro de permissões
- **Quando** essa versão é publicada
- **Então** a permissão do recurso passa a existir no ambiente publicado, sem
  ninguém rodar carga à mão — porque o deploy aplica as cargas de referência
  idempotentes junto das migrações

#### AC-010 — A conferência pós-deploy é automática

- **Dado** o sistema publicado na URL pública
- **Quando** o operador roda o script de conferência (`deploy/smoke.sh`)
- **Então** ele confere que a URL responde em HTTPS, que a checagem de saúde
  volta 200, que a tela de login abre e que a tela de cadastro está fechada —
  saindo com sucesso só se todas passarem, e listando qual falhou caso contrário

### US-005 — Os dados reais têm cópia de segurança

Como responsável pela Alfa, quero uma cópia diária do banco com histórico de
uma semana, para que uma falha de disco ou um erro de operação não leve embora
o faturamento e o financeiro da empresa.

#### AC-011 — Cópia diária com histórico de sete dias

- **Dado** o script de backup (`deploy/backup.sh`) rodando todo dia pelo
  agendador do servidor
- **Quando** ele é executado
- **Então** ele grava um arquivo compactado do banco datado no diretório de
  backup e apaga as cópias com mais de sete dias, mantendo as sete mais recentes

#### AC-012 — Restaurar exige confirmação e arquivo válido

- **Dado** o script de restauração (`deploy/restaurar.sh`)
- **Quando** o operador o chama apontando um arquivo que não existe, ou sem
  confirmar explicitamente que quer sobrescrever o banco
- **Então** o script recusa e termina com erro explicando o motivo, sem tocar no
  banco de produção

## Fora de escopo

- Segundo fator de autenticação (2FA) — decidido em ASM-005: fica para depois.
- Importação de dados de sistema anterior: o banco sobe vazio com carga inicial.
- Envio das cópias de backup para fora do alfa-server (nuvem/off-site): as
  cópias ficam no storage `backup` do próprio servidor.
- Domínio próprio (ex.: `matriz.alfasolucoes.cloud`): a URL desta entrega é a do
  Tailscale (`https://alfamatriz.tail0939dd.ts.net`).
- Alta disponibilidade, réplica ou balanceamento: um container só.
- A verificação ao vivo contra o servidor de produção não roda na suíte
  automatizada; o teste prova a forma do script de conferência (AC-010) e a
  execução real fica registrada no relatório de execução.

## Suposições

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-001 | O VMID 115 e o IP 10.0.3.115 estão livres no alfa-server | confirmada | `pct config 115` não existe e o IP não responde a ping (verificado em 2026-08-05) |
| ASM-002 | O tailnet da empresa permite Funnel (portas 443/8443/10000) e emissão de certificado HTTPS | confirmada | As capacidades do nó incluem `funnel`, `https` e `funnel-ports?ports=443,8443,10000` |
| ASM-003 | O MariaDB padrão do Debian 12 atende no lugar do MySQL 8.0 usado no docker-compose local | confirmada | As 30+ migrações e os três seeders rodaram sem erro no container em 2026-08-05 |
| ASM-004 | O PHP 8.2 do Debian 12 atende (o composer.json exige `^8.2`) | confirmada | `composer.json` exige `php: ^8.2` |
| ASM-005 | A equipe aceita subir sem 2FA nesta entrega, com senha forte + bloqueio por tentativas como única barreira | confirmada | Dono do produto confirmou em 2026-08-05: sobe sem 2FA por ora |
| ASM-006 | O container terá seu próprio nó Tailscale (`alfamatriz`), sem alterar a configuração do gateway atual | confirmada | Nó `alfamatriz` (100.120.97.63) entrou no tailnet e publica o próprio Funnel; o gateway segue intocado em 8101–8107 |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-001 | Quais e-mails da equipe precisam de conta no primeiro dia? | respondida | Só o admin (`admin@alfatecnologia.com.br`), com senha vinda do ambiente; os demais o dono cria depois via `php artisan alfa:criar-usuario` |
| Q-002 | Backup do banco (rotina e retenção) entra nesta entrega ou vira feature separada? | respondida | Entra nesta entrega: dump diário com retenção de 7 dias (US-005) |
