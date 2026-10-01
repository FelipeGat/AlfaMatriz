# A oficina do agente

Você é o agente de código da Alfa, comandado pelo Telegram. Esta pasta é a sua oficina: **um clone
por sistema**, lado a lado. O pedido chega dizendo uma tarefa do quadro; o SISTEMA da tarefa diz em
qual pasta você trabalha.

> Este arquivo é cópia de `deploy/agente/oficina-CLAUDE.md` do repositório AlfaMatriz. Para mudar
> uma regra daqui, mude lá — a cópia é refeita na atualização do agente.

## Qual pasta é de qual sistema

O nome da esquerda é o que aparece no campo "sistema" da tarefa (`ver_tarefa`).

| Sistema no quadro | Pasta | O que é | Branch de trabalho |
|---|---|---|---|
| AlfaMatriz | `AlfaMatriz/` | Laravel + Blade (este painel, o quadro e a agenda) | `Rossini` |
| AlfaControl | `AlfaControl/` | Spring Boot (`backend/`) + React (`frontend/`) | `Rossini` |
| AlfaGym | `AlfaGym/` | Spring Boot + React | `Rossini` |
| AlfaHome | `AlfaHome/` | Laravel | `Rossini` |
| AlfaJornada | `AlfaJornada/` | Spring Boot + React | `Rossini` |
| AlfaMed | `AlfaMed/` | Laravel | `Rossini` |
| AlfaSchool | `AlfaSchool/` | Spring Boot + React | `Rossini` |
| Gestor | `Gestor/` | Laravel (repositório `Gestor.Alfa`) | `Rossini` |
| AlfaDeploy | `AlfaDeploy/` | Python (o painel de staging e os vigias de deploy) | `main` |
| Alfa Solucções | `AlfaSite/` | PHP + Vite (o site institucional) | `main` |

Dois aplicativos não têm sistema próprio no quadro; a tarefa deles vem no sistema do backend:

| Pasta | O que é |
|---|---|
| `AlfaMobile/` | Flutter — o app do AlfaControl e do AlfaGym |
| `AlfaJornada_App/` | Flutter — o app do AlfaJornada |

Tarefa sem sistema, ou de um sistema que não está na tabela: **pergunte qual é a pasta** em vez de
adivinhar pelo título.

## Como trabalhar numa tarefa

1. **Leia a tarefa** (`ver_tarefa`) e os anexos (`ver_anexo`) no servidor MCP `alfamatriz-producao`.
2. **Entre na pasta do sistema e leia o `CLAUDE.md` DELA antes de qualquer coisa.** As regras do
   repositório valem mais do que as deste arquivo. Vários têm armadilhas escritas ali (o AlfaHome
   tem um portão de aprovação próprio; o AlfaGym tem horários em que não se publica).
3. `git fetch` e `git pull` na branch de trabalho antes de começar. Outras pessoas e outras sessões
   empurram para estes repositórios o dia inteiro.
4. Faça a mudança seguindo os padrões que já existem no repositório.
5. **Rode os testes do sistema** (abaixo) e diga o resultado com os números. Se não conseguiu rodar,
   diga que não rodou e por quê — nunca "deve estar funcionando".
6. Pare e relate. **Commit e push só quando a pessoa pedir.** Quando pedir: commit na branch de
   trabalho; se o repositório tem `Rossini`, depois merge na `main` e push das duas.
7. Mover a tarefa no quadro (`mover_tarefa`) acompanha o que de fato aconteceu: Em andamento ao
   começar, Em revisão quando o código está na `main`. Nunca mova para uma etapa que o trabalho não
   alcançou.

## Testes por tipo de projeto

- **Laravel**: `composer install` (uma vez) e `php artisan test`.
- **Spring Boot**: `JAVA_HOME=$(/usr/libexec/java_home -v 17) mvn -q test`, dentro de `backend/`.
  Sem o Java 17 o Lombok quebra com um erro que não menciona nem o Java nem o Lombok.
- **React/Vite**: `npm ci` (uma vez) e `npm run build`; `npm test` quando existir.
- **Flutter**: `flutter pub get` e `flutter test`.

## O que esta máquina NÃO tem

- **Docker.** O comando existe, mas o serviço é de outro usuário. Teste que sobe contêiner
  (Testcontainers, `docker compose`) não roda aqui: diga isso, não tente contornar.
- **Banco de produção.** Você alcança a produção só pelo MCP do quadro e da agenda. Não procure
  credencial, `.env` de produção nem túnel.
- **SSH para os servidores.** Não há chave. Deploy não é com você.
- **Espaço sobrando.** O disco é pequeno: instale as dependências só do sistema em que está
  trabalhando, e não deixe `target/`, `build/` e `node_modules/` de sistemas que não está tocando.

## Publicar

**Você nunca cria tag nem faz deploy.** Quando o trabalho estiver na `main` e pronto para o ar,
diga qual sistema e qual versão publicar e pare. Quem publica é a pessoa, pelo Telegram:
`/publicar <sistema> vAAAA.MM.DD` (ou `.N` se já houve uma publicação no dia). Isso só vale para
os sistemas que publicam por tag — AlfaMatriz, AlfaControl, AlfaGym, AlfaHome, AlfaJornada e
AlfaDeploy. Para os outros, diga que não sabe como aquele sistema vai ao ar.

## Quando parar e perguntar

- A tarefa não diz o suficiente para saber o que mudar.
- A mudança exige apagar dado, mexer em produção ou em credencial.
- O `CLAUDE.md` do repositório e este guia dizem coisas diferentes (o do repositório vence, mas avise).
- Os testes já estavam vermelhos antes de você mexer.
