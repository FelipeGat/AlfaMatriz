# Spec: Domínio próprio da empresa

> feature: dominio-proprio
> status: rascunho

## Contexto

O AlfaMatriz subiu numa URL do Tailscale (`alfamatriz.tail0939dd.ts.net`).
A Alfa quer o painel no domínio da empresa, `matriz.alfasolucoes.cloud`, como
já acontece com os outros sistemas (`home`, `gym`, `control`).

O Funnel do Tailscale não serve domínio próprio — ele só emite certificado
para `*.ts.net`. Então a entrega troca o mecanismo de exposição: passa a usar
um túnel Cloudflare, que é o padrão já adotado no `alfahome-prod`.

O Cloudflare Access (exigir login corporativo antes do painel carregar) foi
avaliado e ficou de fora por ora — ver Q-006. A proteção continua sendo a do
painel: cadastro fechado, senha e bloqueio por tentativas.

## Histórias

### US-011 — O painel atende no domínio da empresa

Como responsável pela Alfa, quero o painel em `matriz.alfasolucoes.cloud`,
para que o endereço seja o da empresa e não um subdomínio técnico do
Tailscale.

#### AC-024 — O domínio entrega o painel em HTTPS

- **Dado** o túnel Cloudflare publicado
- **Quando** alguém acessa `https://matriz.alfasolucoes.cloud`
- **Então** a tela de login do AlfaMatriz é entregue em HTTPS válido, a
  checagem de saúde responde 200 e o cadastro segue fechado (404)

#### AC-025 — Os endereços gerados usam o domínio próprio

- **Dado** o ambiente publicado configurado com o domínio da empresa
- **Quando** o painel gera um link ou um redirecionamento
- **Então** o endereço sai como `https://matriz.alfasolucoes.cloud`, e não
  como o antigo endereço do Tailscale

### US-013 — Uma porta pública só

Como responsável pela Alfa, quero que só o domínio da empresa responda na
internet, para não manter duas portas públicas abertas para os mesmos dados
financeiros — mantendo, ainda assim, um acesso de emergência.

#### AC-028 — O endereço do Tailscale sai da internet, mas continua no tailnet

- **Dado** o domínio próprio já funcionando
- **Quando** o Funnel é desligado
- **Então** o painel deixa de ser publicado na internet pelo Tailscale, e
  continua acessível de dentro do tailnet — servindo de acesso de emergência
  caso a Cloudflare fique indisponível

## Fora de escopo

- **Cloudflare Access** (autenticação corporativa antes do painel): decidido
  em Q-006, fica para quando a questão dos e-mails da equipe estiver resolvida.
- Migrar os outros sistemas (`gym`, `home`, `control`) para este padrão.
- Redirecionar o endereço antigo do Tailscale para o domínio novo: ele
  simplesmente deixa de ser público.
- Regras de firewall da Cloudflare (WAF, rate limit).
- Certificado próprio: quem emite e renova é a Cloudflare.

## Suposições

| ID | Suposição | Status | Resolução |
|---|---|---|---|
| ASM-012 | A conta Cloudflare que hospeda `alfasolucoes.cloud` pode criar túneis (Zero Trust) | aberta | Confirmar ao autenticar o `cloudflared`; o domínio já usa a Cloudflare como DNS e proxy |
| ASM-013 | Um túnel novo para o AlfaMatriz não interfere no túnel já existente do `alfahome-prod` | aberta | O padrão da casa é um túnel por container, com credencial própria |
| ASM-014 | Sem o Access, o painel volta a ter a tela de login exposta a qualquer pessoa na internet — igual ao que já acontece hoje com o Funnel | confirmada | Decisão consciente do dono em 2026-08-05: mantém o nível de proteção atual |

## Perguntas em aberto

| ID | Pergunta | Status | Resposta |
|---|---|---|---|
| Q-006 | Quem o Cloudflare Access deve deixar entrar: qualquer e-mail do domínio corporativo, lista fechada, ou login pelo Google? | respondida | Adiada: parte da equipe usa e-mail pessoal e a regra travaria o próprio dono. O Access fica fora desta entrega até os e-mails de acesso estarem definidos |
