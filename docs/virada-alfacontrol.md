# Implantação do AlfaControl na Matriz — runbook

**Estado em 10/08/2026, madrugada.** A Matriz está publicada em produção
(`v2026.08.10.2`) e **inerte**: AlfaGym e AlfaControl estão cadastrados sem
`base_url` e sem `token`, então `integravel()` é falso e o sincronizador os
ignora. Nada é lido de sistema nenhum até alguém configurar.

Fase 1: a Matriz **só lê**. Revenda, cliente, licença e módulo continuam sendo
operados no painel `/saas` do AlfaControl durante toda a implantação.

---

## O que a implantação real desmentiu

Este documento foi escrito supondo um incremento sobre uma integração do
AlfaGym já rodando em produção. **Ela nunca rodou.** A produção da Matriz estava
127 commits atrás, sem a tabela `origens_externas`, com 1 revenda e 0 clientes.

Três consequências:

1. **Não existe "confirmar o gym por um ciclo"** antes de ligar o AlfaControl —
   o sync do gym nunca rodou em produção. Os dois entram pela primeira vez.

2. **A sobreposição existe, e é justamente a revenda.** A INVEST SOLUÇÕES está
   nos dois sistemas com o mesmo CNPJ — e **formatada dos dois lados**
   (`52.638.029/0001-05`). É exatamente o caso que a reconciliação existe para
   resolver, e o caso em que o bug de máscara (corrigido em 11/08) teria
   duplicado a revenda e cobrado a mesma empresa duas vezes.

   > Correção de registro: a primeira versão deste documento dizia
   > "sobreposição zero". Eu havia comparado contra o **staging** do AlfaGym
   > (LXC 101 no Proxmox), achando que era produção. A produção do gym é outro
   > servidor — `187.127.2.226`, credenciais em `AlfaGym/CLAUDE.md`.

3. **A licença do AlfaControl está fora.** O sistema não tem a capacidade
   `sincroniza_licencas`, porque `renovar` lá somava licenças ativas em vez de
   substituir — 6 clientes com mais de uma ativa, um deles com quatro. Correção
   pronta em PR no repositório do AlfaControl; enquanto não entrar, revenda,
   cliente e módulo sincronizam e licença não é lida.

---

## Ordem de ligar

Cada passo é reversível sozinho. A ordem existe para que, se algo sair torto,
se saiba **qual** passo causou.

### 1. Chave no AlfaControl (produção)

```bash
CHAVE=$(openssl rand -hex 32)
echo -n "$CHAVE" | sha256sum          # → MATRIZ_API_KEY_HASH
```

Precisa estar nos **dois** serviços do `docker-compose.prod.yml` (blue e green).
Esquecer um faz a API cair a cada troca de deploy, e o sintoma é
indistinguível de "sistema fora do ar".

⚠️ O contrato só existe em produção depois de uma tag `v*` no AlfaControl — a
`main` alimenta staging, não produção.

Conferir:

```bash
curl -s -H "X-Matriz-Key: $CHAVE" https://control.alfasolucoes.cloud/api/matriz/v1/ping
# → {"contrato":"1.0","sistema":"alfacontrol","cadastro_local_aberto":true,...}

curl -s -o /dev/null -w '%{http_code}\n' https://control.alfasolucoes.cloud/api/matriz/v1/ping
# → 401
```

### 2. Endereço e chave na Matriz

Na tela de Sistemas, preencher `base_url` e `token` do `alfacontrol`. Salvar a
tela sem redigitar a chave **não** a apaga mais.

### 3. Ensaiar

```bash
php artisan alfa:sincronizar-sistemas --sistema=alfacontrol --simular
```

Roda tudo em transação e desfaz. **Ler o relatório é o portão**, não
formalidade. Com os dados de hoje o esperado é criar 1 revenda e ~10 clientes —
qualquer número muito diferente disso merece investigação antes de gravar.

### 4. Carregar e conferir

```bash
php artisan alfa:sincronizar-sistemas --sistema=alfacontrol
php artisan alfa:conferir-migracao --sistema=alfacontrol
```

A conferência sai com erro enquanto houver cliente sem revenda, cliente
licenciado sem âncora de licença, ou revenda sem acesso ao painel. As duas
primeiras não devem aparecer com a licença desligada.

### 5. O `schedule:run` — o único passo que pede janela vigiada

**Ainda não foi instalado em produção**, e é de propósito.

Ligá-lo dispara pela primeira vez o retrato horário **e**
`app:fechar-competencia-mensal`, que nunca rodou. Com 0 clientes o fechamento
não gera cobrança nenhuma, mas isso deixa de valer no instante em que o passo 4
carregar a base.

Fazer **depois** da carga conferida, com alguém olhando, e nunca na mesma janela
da configuração dos sistemas. O bloco idempotente está em
`deploy/provisionar.sh`.

---

## O que NÃO acontece nesta fase

- A Matriz não cria revenda nem cliente no AlfaControl.
- Não libera, renova nem suspende licença: a tela não oferece a ação e um POST
  direto é recusado com 422.
- Não lê licença do AlfaControl.
- Não contrata nem cancela módulo — só lê o que está contratado.

Cada uma é uma capacidade na linha do sistema (`provisiona_revenda`,
`provisiona_cliente`, `gerencia_licenca`, `sincroniza_licencas`,
`gerencia_modulos`). Ligar é uma linha no banco, sem tocar em código.

⚠️ `exige_admin_no_cliente` **não entra**: o AlfaControl não cria usuário
administrador junto com o cliente, ao contrário do AlfaGym.

---

## Pendências fora da Matriz

**Gate de acesso do AlfaControl.** Censo feito em produção: os 12 clientes estão
`ativo`, nenhum em `inativo`/`congelado`. O gate barraria zero pessoas, então
dispensa a semana em modo `observar` — pode subir direto em
`AUTH_GATE_CLIENTE_MODO=bloquear`. Refazer o censo se demorar: um único cliente
congelado no meio tempo muda a resposta.

Vale lembrar por que importa: hoje congelar um cliente no painel **não corta o
acesso de ninguém**.

**Licenças duplicadas.** PR aberto no AlfaControl com a correção e o script de
consolidação do que já está gravado. Depois que entrar, ligar
`sincroniza_licencas` no `alfacontrol` faz a licença voltar a ser lida — e a
coluna "Licença" da lista de clientes passa a preencher sozinha.

**Rotina de expiração.** Não existe no AlfaControl: licença vencida continua
marcada como ativa. O contrato já reporta o status efetivo para a Matriz não
espelhar isso, mas a base de origem só muda quando alguém criar a rotina.

---

## Ajustes locais no staging (lembrete de limpeza)

Para o TLS fechar entre os dois stagings, o LXC 116 recebeu uma linha em
`/etc/hosts` (`control.alfasolucoes.cloud` → `10.0.3.136`) e passou a confiar no
certificado autoassinado do staging do AlfaControl. **Nada disso é necessário em
produção**, onde o certificado é real — mas fica lá até alguém remover.

---

## Ligar o AlfaGym — o que está pronto e o que falta

**Servidor de produção: `187.127.2.226`** (hostname `gym`). Não confundir com o
LXC 101 do Proxmox, que também se chama `alfagym`, também roda
`docker-compose.prod.yml` e também tem containers `alfagym-*` — mas é STAGING.
O sinal são os dados: o staging tem "Revenda Staging Teste" e "Revenda Teste
Fase5"; a produção tem INVEST SOLUCOES LTDA, Empresa e Revenda Teste.

Estado hoje:

| | |
|---|---|
| contrato `/api/matriz/v1` | publicado e respondendo |
| `MATRIZ_API_KEY_HASH` | **vazio** — contrato desligado |
| TLS no origin | Let's Encrypt válido para `gym.alfasolucoes.cloud` |
| caminho direto (sem Cloudflare) | funciona, com `--resolve` para 187.127.2.226 |

### O que a carga faria

```
revendas:  1 ANCORADA (INVEST, ja na Matriz vinda do AlfaControl)
           2 criadas (Empresa; Revenda Teste, sem documento)
academias: 8 criadas — nenhuma coincide com os condominios do AlfaControl
```

### Passos (o 2 exige janela)

1. Gerar a chave e gravar o hash no `.env` do servidor:
   `echo -n "<chave>" | sha256sum` → `MATRIZ_API_KEY_HASH`

2. ⚠️ **Reiniciar o backend.** O gym roda **um único container**, sem
   blue/green — ao contrário do AlfaControl, onde a troca é sem downtime. Aqui
   o gym fica fora do ar durante o boot do Spring. Escolher a janela.

3. Testar se o `X-Matriz-Key` sobrevive ao Cloudflare:
   `curl -H "X-Matriz-Key: <chave>" https://gym.alfasolucoes.cloud/api/matriz/v1/ping`
   - **200** → usar `https://gym.alfasolucoes.cloud` como `base_url`.
   - **401** → o Cloudflare está removendo o header. Contornar com uma linha em
     `/etc/hosts` do LXC 115 (`187.127.2.226 gym.alfasolucoes.cloud`), que faz o
     TLS continuar válido e tira o Cloudflare do caminho.

   Vale testar porque o 401 observado no staging veio de outra topologia — lá o
   caminho passa por um túnel, aqui é proxy direto para o origin.

4. Configurar `base_url` e `token` na Matriz, rodar `--simular`, conferir que a
   INVEST aparece como **atualizada** (e não criada), e só então carregar.

### Pendência de segurança

A senha de root de `187.127.2.226` está **commitada em texto puro** em
`AlfaGym/CLAUDE.md`, junto com o IP. Quem tem o repositório tem o servidor.
Rotacionar e tirar do arquivo.
