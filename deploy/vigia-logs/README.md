# Vigia de logs (#219)

Manda ao AlfaMatriz, de hora em hora, os erros novos do log deste servidor. O
AlfaMatriz agrupa por assinatura, abre tarefa de **Bug** no quadro para erro
novo e avisa no Telegram (erro novo, erro que voltou, pico).

Nasceu do Wellhub (#191): 36 de 36 check-ins falharam com um WARN no log, e
ninguém viu por semanas.

## O que ele manda

- **ERROR** (e CRITICAL, ALERT, EMERGENCY, FATAL, SEVERE): sempre.
- **WARN/WARNING**: só quando traz exceção (linha com `Exception`/`Error:` ou
  stack `at ...` / `#N ...`). WARN comum fica no log.
- Cada erro vai com data, nível, mensagem, classe da exceção e as primeiras
  linhas do stack (`VIGIA_LINHAS_TRECHO`, padrão 15).

Fontes: arquivos de log do Laravel (`VIGIA_ARQUIVOS`) e `docker logs` de
containers (`VIGIA_CONTAINERS`, para o Spring Boot do AlfaGym).

Na **primeira rodada** ele não manda o passado: marca o fim de cada fonte e
vigia daí em diante. Para mandar o que já está no log, `--desde-o-inicio`.

## Instalação

1. No AlfaMatriz, gere o token do sistema (aparece uma vez só):

   ```
   php artisan alfa:vigia-token alfagym
   ```

2. No servidor do sistema, copie esta pasta (por exemplo para
   `/opt/vigia-logs/`) e crie `/opt/vigia-logs/.env`, com `chmod 600`:

   ```
   VIGIA_URL=https://<endereço do AlfaMatriz>/api/vigia/erros
   VIGIA_TOKEN=<o token do passo 1>
   VIGIA_AMBIENTE=producao
   VIGIA_ARQUIVOS="/var/www/sistema/storage/logs/laravel*.log"
   VIGIA_CONTAINERS=
   VIGIA_FUSO=-03:00
   ```

   - `VIGIA_AMBIENTE`: `producao` ou `staging`.
   - `VIGIA_CONTAINERS`: nomes de container, ex. `alfagym-backend`.
   - `VIGIA_FUSO`: o fuso em que o Laravel carimba o log (o do
     `config/app.php`, não necessariamente o do servidor).
   - Opcionais: `VIGIA_ESTADO` (padrão `.estado/` ao lado do script),
     `VIGIA_ORIGEM` (padrão: hostname), `VIGIA_LINHAS_TRECHO` (15),
     `VIGIA_LOTE` (200 erros por envio).
   - O arquivo é LIDO, não executado: uma chave por linha, sem comentário
     na mesma linha do valor.

   Mais de um arquivo ou container: separe por espaço.

3. Confira sem enviar nada:

   ```
   bash /opt/vigia-logs/enviar-erros.sh --imprimir --desde-o-inicio
   ```

4. Primeira rodada de verdade (só marca o ponto de partida):

   ```
   bash /opt/vigia-logs/enviar-erros.sh
   ```

5. Cron, de hora em hora (`crontab -e` do usuário que lê os logs — e que
   está no grupo `docker`, se houver container):

   ```
   7 * * * * /opt/vigia-logs/enviar-erros.sh >> /var/log/vigia-logs.log 2>&1
   ```

## Bom saber

- O estado só anda quando o AlfaMatriz aceitou o lote. Fora do ar, a próxima
  rodada manda de novo.
- Lote recusado por conteúdo (413/422) é descartado e registrado; token
  recusado (401) não anda o estado.
- Precisa de `bash`, `awk`, `curl` (7.55+) e, para containers, `docker`.
- Calar um erro que não é problema: no AlfaMatriz,
  `php artisan alfa:vigia-ignorar "texto do erro" [--sistema=alfagym]`
  (entre barras é regex; sem argumento, lista; `--remover` tira).
