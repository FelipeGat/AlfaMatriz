# Central de IA — entrega local na branch Felipe

## Estado desta entrega

Este checkout registra o candidato já implementado, seus testes e a revisão
arquitetural aprovada. O AlfaMatriz em uso continua na `main`, porta 8096.
Nenhum serviço foi ativado, banco migrado, usuário real vinculado, consumidor
Telegram trocado ou chamada de IA executada por esta entrega.
O checkout separado está na branch local `Felipe`, que recebeu por
fast-forward os 200 commits da `main` local até `1e2bbb9` antes desta entrega.
A referência remota não foi atualizada nem houve push; isso não comprova
sincronização com alterações posteriores no GitHub.

**A arquitetura de destino usa um único banco MariaDB do AlfaMatriz.** Leia
[single-database.md](single-database.md): é a decisão atual. O transporte
`CentralBackend`, tickets e a referência `tools/central-ia/reference/backend`
são o candidato anterior, preservado para reaproveitamento e rastreabilidade.
Não representam a implementação concluída do banco único e não devem ser
ativados como solução final. As flags da interface e integração permanecem
desabilitadas por padrão; ausência de política válida nega o acesso.

## Conteúdo registrado

- Módulo nativo Blade/Alpine: menu, conversa, histórico paginado, autoria,
  reconexão e envio com chave de idempotência.
- Autoridade de identidade e política exclusiva por ID estável e UUID,
  conta ativa e permissão específica; sem exceção de administrador.
- Testes de identidade, autorização, revogação e isolamento com fixtures.
- Snapshot do backend anterior, incluindo Telegram, propriedade, deduplicação,
  limites e testes, em `tools/central-ia/reference/backend`. É referência de
  código, não outro serviço a instalar ou publicar. Não contém dependências,
  credenciais, sessões, bancos, volumes ou runtime.
- Ferramenta do ensaio autorizado de backup real; os artefatos cifrados e
  as chaves ficam fora desta entrega. Relatórios contêm apenas metadados.

## Operação e restrições

Windows: `http://localhost:8096`. Mac na rede atual: `http://home:8096`.
Docker/WSL e computador ligado são necessários; VS Code aberto não é.
O serviço existente e o consumidor do bot permanecem independentes deste
checkout. Não abrir segundo consumidor nem rodar o scheduler/filas genéricas
do AlfaMatriz local: o banco é uma cópia com integrações reais.

**Não publicar esta branch nem executar suas migrações em produção.** Mesmo
com flags desabilitadas, `migrate` criaria as novas tabelas de identidade.
Migrações reais, alterações no bot e reinícios continuam sujeitos a aprovação.
O `down()` da migração de identidade recusa exclusão destrutiva deliberadamente.

## Documentação e evidências

- [Plano de banco único](single-database.md): trabalho ainda pendente.
- [Ativação local e rollback](real-local-activation.md): histórico do plano
  de dois bancos; adaptá-lo à decisão de banco único antes de ativar.
- [Transição local e HTTP 503](local-transition.md): testes com fixtures.
- [Identidade](identity-integration.md) e [interface](interface-integration.md):
  evolução histórica e mecanismos testados.
- `evidencias/real-backup-validation.json`: restauração real isolada aprovada,
  59 tabelas/20.699 registros AlfaMatriz e 19 tabelas/32 registros Central.
- `evidencias/local-persistence.json`: persistência do candidato fictício.
- `evidencias/local-503-diagnostic.json`: reprodução com worker único;
  não comprova a causa exata do incidente histórico.

## Reprodução segura dos testes

`tools/central-ia/testing/TestCase.php` é o bootstrap de laboratório usado
na validação: exige SQLite em memória, impede HTTP não simulado e desativa
Vite nos testes PHP. Não substitui o TestCase padrão no código da aplicação.
O laboratório usa PHP 8.2.30, vendor conforme `composer.lock`, filesystem
somente leitura, sem rede e storage/cache temporários. Dependências não
foram instaladas nem copiadas para o Git nesta entrega.
O `/tmp` desse contêiner de testes precisa de `exec`: os testes antigos de
deploy executam scripts simulados nesse local. Storage e cache mantêm
`noexec`. Essa configuração de teste não muda os executores de IA.

Com a imagem e dependências do laboratório já disponíveis:

```bash
python3 tools/central-ia/run-tests.py \
  --central-root /home/duque_de_caxias/projetos/central-agentes
```

A ferramenta de backup preservada exige `cryptography` já disponível e o
diretório original como contexto explícito:

```bash
CENTRAL_BACKUP_ROOT=/home/duque_de_caxias/projetos/central-agentes \
  python3 tools/central-ia/run-real-backup-validation.py
```

Esse comando acessa os bancos reais de desenvolvimento e cria um novo backup:
não é teste sintético e só deve ser repetido com autorização adequada. Não
executá-lo como rotina automática. Não faz migração ou vínculo real.

## Próximos passos

Implementar e ensaiar o domínio `central_ia_*` no MariaDB descartável, adaptar
consultas/envio ao banco único, transferir legado idempotentemente e testar
propriedade, cursor, entregas e quotas. Confirmar a identidade pelo login
nativo antes da vinculação. Apresentar o ensaio e aprovar ativação local.
Manter PostgreSQL antigo e backups até recuperação comprovada. Inferência
e executores reais continuam uma etapa separada.
