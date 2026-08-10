# Implantação do AlfaControl na Matriz — runbook

Fase 1: a Matriz **só lê** o AlfaControl. Revenda, cliente, licença e módulo
continuam sendo operados no painel `/saas` do AlfaControl durante toda a
implantação. Este documento é a ordem de ligar as coisas, e o que conferir
entre um passo e outro.

A ordem importa: cada passo é reversível sozinho, e nenhum deles mexe no
AlfaGym, que está em produção.

---

## 0. Antes de qualquer coisa

O AlfaGym continua rodando o tempo todo. Se algo neste runbook afetar o retrato
dele, pare e volte atrás — a generalização foi feita para não tocá-lo, e o
`CompatibilidadeAlfaGymTest` existe justamente para acusar isso antes.

---

## 1. Publicar a Matriz com o AlfaControl **desconfigurado**

Publicar a generalização com o AlfaControl **sem `base_url` e sem `token`**.

Nesse estado `Sistema::integravel()` é falso e o sincronizador o ignora em
silêncio — é o estado normal entre publicar a integração e configurá-la.

Conferir depois de publicar:

```bash
php artisan alfa:sincronizar-sistemas          # só o AlfaGym deve aparecer
php artisan alfa:conferir-migracao             # o gym continua sem divergência
```

Abrir a lista de clientes e confirmar que as ações de licença do AlfaGym
continuam onde estavam.

> ⚠️ **O `schedule:run` entra aqui, e em janela própria.** Ele nunca foi
> instalado em produção — ligá-lo dispara pela primeira vez o retrato horário
> **e** o `app:fechar-competencia-mensal`. Não misturar com a configuração do
> AlfaControl: se algo sair torto, é preciso saber qual dos dois causou.

---

## 2. Ligar a API do lado do AlfaControl

No AlfaControl, gerar a chave e publicar o hash:

```bash
CHAVE=$(openssl rand -hex 32)
echo -n "$CHAVE" | sha256sum          # o hash vai para MATRIZ_API_KEY_HASH
```

`MATRIZ_API_KEY_HASH` precisa estar nos **dois** serviços do
`docker-compose.prod.yml` (blue e green). Esquecer um faz a API cair a cada
troca de deploy — e o sintoma é indistinguível de "sistema fora do ar".

Conferir:

```bash
curl -s -H "X-Matriz-Key: $CHAVE" https://control.alfasolucoes.cloud/api/matriz/v1/ping
# → {"contrato":"1.0","sistema":"alfacontrol","cadastro_local_aberto":true,...}

curl -s -o /dev/null -w '%{http_code}\n' https://control.alfasolucoes.cloud/api/matriz/v1/ping
# → 401, e o corpo é o envelope do contrato (não o {"error":...} do resto da app)
```

Chave vazia deixa a API desligada: é o kill switch, e não precisa de redeploy.

---

## 3. Ensaiar a carga na Matriz

Cadastrar `base_url` e `token` do sistema `alfacontrol` na tela de Sistemas, e
**ensaiar antes de gravar**:

```bash
php artisan alfa:sincronizar-sistemas --sistema=alfacontrol --simular
```

O ensaio roda tudo dentro de uma transação e desfaz ao final. **Ler o relatório
é o portão desta etapa**, não formalidade:

- `criadas` alto demais em revendas é o sinal de que a reconciliação por
  documento não casou o que devia — a mesma revenda já existe na Matriz vinda
  do AlfaGym, com outro id externo. Conferir os CNPJs antes de rodar de verdade;
  duplicar revenda dobra a cobrança dela.
- Cliente aparecendo como criado quando já existe na Matriz tem a mesma causa.

Só seguir quando os números fizerem sentido.

---

## 4. Carregar de verdade

```bash
php artisan alfa:sincronizar-sistemas --sistema=alfacontrol
php artisan alfa:conferir-migracao --sistema=alfacontrol
```

A conferência sai com erro enquanto houver: cliente sem revenda, cliente
licenciado sem âncora de licença, ou revenda sem acesso ao painel.

Depois disso, na tela de clientes: o cliente que usa os dois sistemas mostra
**duas linhas**, uma por sistema, e o AlfaControl aparece **sem ações de
licença** — o que está certo nesta fase.

---

## 5. Deixar o ciclo horário assumir

Com o retrato conferido, o `schedule:run` do passo 1 já mantém tudo em dia. O
sincronizador percorre todos os sistemas configurados, e uma indisponibilidade
do AlfaControl não interrompe o AlfaGym.

---

## 6. Gate de acesso do AlfaControl (independente, mas relacionado)

Descoberta desta implantação: **congelar um cliente no painel do AlfaControl
nunca impediu ninguém de logar**. O gate novo sobe em modo `observar`.

```bash
mysql -u <user> -p alfacontrol < docs/gate-cliente-censo.sql   # no repo do AlfaControl
```

Se algum cliente marcado como `inativo`/`congelado` logou nos últimos 30 dias,
há gente usando o sistema com o status errado — a lista vai para a operação
**antes** de virar `bloquear`. Depois de uma semana em observação, com o log
limpo:

```
AUTH_GATE_CLIENTE_MODO=bloquear
```

Desligar não precisa de redeploy: `AUTH_GATE_CLIENTE_STATUS=` (vazio).

---

## O que NÃO acontece nesta fase

- A Matriz não cria revenda nem cliente no AlfaControl.
- A Matriz não libera, renova nem suspende licença no AlfaControl. A tela não
  oferece a ação, e um POST direto é recusado com 422.
- A Matriz não contrata nem cancela módulo — só lê o que está contratado.

Tudo isso é a Fase 2, e depende de o AlfaControl publicar o lado de escrita do
contrato. Ligar cada uma é acrescentar a capacidade correspondente na linha do
sistema (`provisiona_revenda`, `provisiona_cliente`, `gerencia_licenca`,
`gerencia_modulos`) — sem tocar em controller nem em tela.

⚠️ **`exige_admin_no_cliente` não entra**: o AlfaControl não cria usuário
administrador junto com o cliente, ao contrário do AlfaGym.

---

## Pendências registradas

- **Faturamento com módulos** está fora até a resposta de: `valor_mensal` no
  AlfaControl é preço de **atacado** (revenda→Alfa) ou de **varejo**
  (condomínio→revenda)? Se for varejo, somá-lo na cobrança da revenda infla a
  fatura. Os módulos já são lidos e exibidos; só a soma na cobrança espera.
- **Contagem de unidades**: hoje 1 por cliente ativo, sem olhar a licença — a
  mesma regra que o faturamento já usa. Registrado como decisão.
