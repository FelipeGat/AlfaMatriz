# Tasks: Integração — a matriz enxerga os sistemas

> feature: integracao-leitura

<!--
  T-xxx = tarefa (código de rastreio, único no projeto inteiro).
  Refs: as histórias/critérios que a tarefa atende.
  Arquivos: separados por VÍRGULA — é o que decide o que pode rodar em paralelo.
  Status: pendente | em-andamento | concluida
    (atalho: `onp-spec tarefa <feature> <T-xxx> <status>`)
-->

## Contrato compartilhado

Vale para todas as tarefas desta feature:

- **Tudo que decide alguma coisa mora em `app/Services/Integracao/`.** É o único caminho em
  `srcGlobs` do `onpspec.config.json`; ampliar esse glob mudaria o audit de tudo que já está
  verde. Controllers ficam finos, como `ProdutoController` e `FaturamentoController` já são.
- **Nenhum teste toca a rede.** O conector falso (T-065) atende os testes de serviço; os testes
  do conector HTTP usam resposta simulada, que é o único jeito de provar endereço e cabeçalho.
- **Idempotência é estrutural**, por `(sistema, identificador na origem)` — não uma conferência
  antes de gravar.
- **A chave de integração nunca aparece em registro nem em mensagem de erro** (AC-081).
- **Só sistemas de categoria `saas`** entram na integração — é o filtro que mantém o `gestor`
  fora (ASM-035).
- Nomes em português no domínio, como no resto do projeto (`Sincronizacao`, `SistemaCliente`).
- Teste anotado com `@spec:AC-xxx` no docblock, em `tests/Feature/Integracao/`.

## T-059 — Escrever o contrato da integração [concluida]
- Refs: AC-078
- Arquivos: docs/integracao/CONTRATO-API-v1.md, docs/integracao/CHANGELOG.md, tests/Feature/Integracao/ContratoDocumentadoTest.php
- Notas: prefixo `/api/matriz/v1`, cabeçalho próprio `X-Matriz-Key`. **Não** reaproveitar a chave
  do AlfaMonitor: ela é só de leitura e já está distribuída; pendurar escrita nela daria ao
  monitor poder de derrubar cliente. Descrever envelope, paginação com ordenação estável,
  catálogo fechado de erros e a regra de evolução de versão. O teste confere que o documento
  existe e cobre cada endereço que o conector usa — documento e código não podem divergir.

## T-060 — Configuração do sistema: chave preservada e estado da integração [concluida]
- Refs: AC-079, AC-080
- Arquivos: config/integracao.php, app/Http/Controllers/SistemaController.php, app/Models/Sistema.php, database/migrations/2026_08_07_120000_add_integracao_to_sistemas_table.php, tests/Feature/Integracao/ConfiguracaoDoSistemaTest.php
- Notas: **corrige um defeito real já em produção** — o campo da chave é oculto, chega sempre
  vazio, e salvar qualquer outro campo do sistema grava vazio por cima, desligando a integração
  em silêncio. Hoje é invisível porque ninguém lê a chave. Colunas novas em `sistemas`:
  quando sincronizou pela última vez, quantas falhas seguidas, quando foi importado e desde
  quando a matriz é dona do cadastro dele.

## T-061 — Retrato local: revendas, clientes e planos [concluida]
- Refs: US-036, AC-084, AC-085, AC-086
- Arquivos: database/migrations/2026_08_07_120100_create_sistema_revendas_table.php, database/migrations/2026_08_07_120200_create_sistema_clientes_table.php, database/migrations/2026_08_07_120300_create_sistema_planos_table.php, app/Models/Concerns/EspelhaSistema.php, app/Models/SistemaRevenda.php, app/Models/SistemaCliente.php, app/Models/SistemaPlano.php, tests/Feature/Integracao/RetratoLocalTest.php
- Notas: chave única por `(sistema, identificador na origem)`. Cada tabela guarda também a
  resposta crua, para um campo novo do contrato ficar visível sem migração. `sistema_clientes`
  carrega o vínculo com o cliente da matriz e a marca de como o vínculo nasceu (automático ou
  manual) — vínculo manual nunca é sobrescrito.

## T-062 — Retrato local: licenças, usuários, financeiro e contadores [concluida]
- Refs: US-036, AC-084, AC-085, AC-086, AC-088, AC-089
- Arquivos: database/migrations/2026_08_07_120400_create_sistema_licencas_table.php, database/migrations/2026_08_07_120500_create_sistema_usuarios_table.php, database/migrations/2026_08_07_120600_create_sistema_faturas_table.php, database/migrations/2026_08_07_120700_create_sistema_contadores_table.php, app/Models/SistemaLicenca.php, app/Models/SistemaUsuario.php, app/Models/SistemaFatura.php, app/Models/SistemaContador.php, tests/Feature/Integracao/RetratoLocalTest.php
- Notas: o identificador da licença nunca pode ser nulo — sistema sem entidade de licença própria
  usa um derivado do cliente. Chave única sobre coluna que aceita nulo tem semântica diferente
  entre o banco dos testes e o de produção, e é assim que um teste verde esconde duplicata.
  Contadores são únicos por `(sistema, competência)`.

## T-063 — Registro de cada execução de sincronização [concluida]
- Refs: US-036, AC-084, AC-087
- Arquivos: database/migrations/2026_08_07_120800_create_sincronizacoes_table.php, app/Models/Sincronizacao.php, tests/Feature/Integracao/RetratoLocalTest.php
- Notas: escopo, origem (agendada, manual, comando), situação (em andamento, sucesso, parcial,
  falha), itens lidos/criados/atualizados, duração, código e mensagem do erro, e quem disparou.
  Sem esse registro ninguém descobre que a rotina morreu — é exatamente o defeito que o projeto
  tinha com o agendamento.

## T-064 — O contrato em código: interface, transportes e erro [pendente]

- Refs: AC-078
- Arquivos: app/Services/Integracao/ConectorSistema.php, app/Services/Integracao/RespostaIntegracao.php, app/Services/Integracao/ErroIntegracao.php, app/Services/Integracao/Documento.php, app/Services/Integracao/Dto/ClienteExterno.php, app/Services/Integracao/Dto/RevendaExterna.php, app/Services/Integracao/Dto/LicencaExterna.php, app/Services/Integracao/Dto/PlanoExterno.php, app/Services/Integracao/Dto/UsuarioExterno.php, app/Services/Integracao/Dto/FaturaExterna.php, app/Services/Integracao/Dto/ContadoresExternos.php
- Notas: `Documento` normaliza CPF/CNPJ para só dígitos — é a base do casamento (T-067) e
  precisa ser um lugar só. `ErroIntegracao` carrega o código do catálogo do contrato, e é o
  único tipo de erro que sai desta camada.

## T-065 — Conector falso e amostras de resposta [pendente]

- Refs: AC-078
- Arquivos: app/Services/Integracao/ConectorFalso.php, app/Services/Integracao/FabricaDeConector.php, app/Providers/AppServiceProvider.php, tests/Fixtures/Integracao/v1/clientes.json, tests/Fixtures/Integracao/v1/revendas.json, tests/Fixtures/Integracao/v1/licencas.json, tests/Fixtures/Integracao/v1/planos.json, tests/Fixtures/Integracao/v1/financeiro.json, tests/Fixtures/Integracao/v1/contadores.json, tests/Feature/Integracao/ConectorFalsoTest.php
- Notas: é o que faz a suíte inteira rodar sem rede. O falso programa falha por código de erro e
  guarda o que recebeu, para o teste conferir o que foi enviado. A fábrica é resolvida pelo
  contêiner, e o teste a substitui — nenhum serviço instancia conector direto.

## T-066 — Conector HTTP [pendente]

- Refs: AC-079, AC-081
- Arquivos: app/Services/Integracao/ConectorHttp.php, tests/Feature/Integracao/ConectorHttpTest.php
- Notas: nova tentativa só em falha de conexão, excesso de pedidos e erro do servidor — nunca em
  recusa, que não melhora repetindo. Chamada sempre fora de transação de banco. Chave ilegível
  (acontece se a chave da aplicação for trocada no servidor) vira erro nomeado, não exceção
  crua. Teste dedicado varre o registro atrás da chave (AC-081).

## T-067 — Casar cliente e revenda do sistema com os da matriz [pendente]

- Refs: AC-091
- Arquivos: app/Services/Integracao/VinculadorService.php, tests/Feature/Integracao/VinculoTest.php
- Notas: casa por documento normalizado e **só quando existe exatamente um candidato**. Zero ou
  dois ou mais deixa sem vínculo, para virar pendência na conferência. Vínculo marcado como
  manual nunca é sobrescrito por uma execução automática.

## T-068 — Sincronizar o cadastro do sistema [pendente]

- Refs: AC-084, AC-085
- Arquivos: app/Services/Integracao/SincronizacaoService.php, tests/Feature/Integracao/SincronizacaoCadastroTest.php
- Notas: ordem fixa — planos, revendas, clientes, usuários — porque cliente referencia revenda e
  usuário referencia cliente. Sistema mal configurado grava a execução com o motivo e **retorna**,
  em vez de lançar exceção: a tela precisa mostrar o motivo, não um rastro de pilha.

## T-069 — Ausência na origem e varredura interrompida [pendente]

- Refs: AC-086, AC-087
- Arquivos: app/Services/Integracao/SincronizacaoService.php, tests/Feature/Integracao/AusenciaNaOrigemTest.php
- Notas: **a armadilha número um de cópia espelhada.** Só marcar ausente depois que a varredura
  daquele escopo terminou com sucesso — senão uma falha na terceira página desativa a base
  inteira. Nunca apagar: apagar levaria junto o vínculo com o cliente da matriz e o histórico.

## T-070 — Sincronizar licenças, financeiro e contadores [pendente]

- Refs: AC-084, AC-088, AC-089
- Arquivos: app/Services/Integracao/SincronizacaoService.php, tests/Feature/Integracao/SincronizacaoFinanceiroTest.php
- Notas: o financeiro é por competência. Linha derivada (sistema que não tem título próprio) fica
  marcada como tal, para a tela de divergências não acusar falso alarme em cima dela (ASM-031).

## T-071 — Comando e agendamento da sincronização [pendente]

- Refs: AC-084
- Arquivos: app/Console/Commands/SincronizarSistemas.php, routes/console.php, tests/Feature/Integracao/ComandoSincronizarTest.php
- Notas: mesmo molde do comando de fechamento mensal. Uma execução leve de hora em hora e uma
  completa de madrugada (Q-013 decide os horários finais). Agora isso realmente roda: o
  agendamento do servidor foi instalado na feature `infra-agendamento`.

## T-072 — Importar o cadastro que já existe no sistema [pendente]

- Refs: AC-091, AC-092, AC-093
- Arquivos: app/Services/Integracao/ImportacaoService.php, tests/Feature/Integracao/ImportacaoTest.php
- Notas: separa as pendências por motivo — sem par, mais de um candidato, sem documento na
  origem, repetido dentro do próprio sistema. **Nunca cria cliente sozinha**: criar mudaria a
  receita da empresa sem ninguém decidir, porque o faturamento fatura em cima do vínculo
  cliente-sistema.

## T-073 — O corte, sistema por sistema [pendente]

- Refs: AC-094, AC-095
- Arquivos: app/Services/Integracao/CorteService.php, tests/Feature/Integracao/CorteTest.php
- Notas: recusa enquanto houver pendência de conferência, dizendo quantas faltam. Aplicado, grava
  quando e por quem. É o marco que a feature seguinte lê para saber se pode escrever naquele
  sistema — e é praticamente irreversível, por isso a trava.

## T-074 — Painel de integração e o selo de "atualizado há" [pendente]

- Refs: AC-082, AC-083, AC-095
- Arquivos: resources/views/components/atualizado-em.blade.php, resources/views/integracao/index.blade.php, app/Http/Controllers/IntegracaoController.php, resources/views/layouts/navigation.blade.php, routes/web.php, tests/Feature/Integracao/PainelTest.php
- Notas: grupo novo **Integração** no menu, entre Comercial e Financeiro — em Comercial viraria um
  grupo de dez itens. Um componente de "atualizado há" só, usado por todas as telas; senão cada
  uma inventa o seu e elas divergem.

## T-075 — Tela de conferência e aplicação do corte [pendente]

- Refs: AC-092, AC-093, AC-094
- Arquivos: resources/views/integracao/conferencia.blade.php, app/Http/Controllers/ConferenciaController.php, tests/Feature/Integracao/TelaConferenciaTest.php
- Notas: os quatro motivos de pendência, cada linha com a ação ao alcance da mão (vincular,
  criar a partir deste, ignorar, escolher qual fica). O botão do corte fica desabilitado
  enquanto sobrar pendência, com o número na frente.

## T-076 — Tela de clientes por sistema [pendente]

- Refs: US-038, AC-091
- Arquivos: resources/views/integracao/clientes.blade.php, app/Http/Controllers/IntegracaoClienteController.php, tests/Feature/Integracao/TelaClientesTest.php
- Notas: tabela consolidada com filtro por sistema, revenda e situação, e a coluna que diz qual
  cliente da matriz corresponde — ou avisa que ainda não há vínculo, com o caminho para resolver.

## T-077 — Tela de licenças dos sistemas [pendente]

- Refs: US-036, AC-084
- Arquivos: resources/views/integracao/licencas.blade.php, app/Http/Controllers/IntegracaoLicencaController.php, tests/Feature/Integracao/TelaLicencasTest.php
- Notas: só leitura nesta feature — pendentes, ativas, vencendo em trinta dias e vencidas, na
  mesma faixa segmentada usada no atraso das Despesas. As ações de liberar e bloquear entram na
  feature seguinte.

## T-078 — Financeiro dos sistemas e exportação [pendente]

- Refs: AC-088, AC-090
- Arquivos: resources/views/integracao/financeiro.blade.php, app/Http/Controllers/IntegracaoFinanceiroController.php, tests/Feature/Integracao/TelaFinanceiroTest.php
- Notas: por competência, consolidado sistema por revenda, com o total conferindo com a soma das
  linhas. Exportação sem pacote novo, com separador e marca de codificação que o Excel em
  português abre sem estragar os acentos.

## T-079 — Tela de divergências [pendente]

- Refs: AC-090
- Arquivos: app/Services/Integracao/DivergenciaService.php, resources/views/integracao/divergencias.blade.php, app/Http/Controllers/DivergenciaController.php, tests/Feature/Integracao/DivergenciaTest.php
- Notas: confronta a contagem do sistema com a que a Alfa faturou daquela revenda na competência,
  usando a apuração que o faturamento já grava. Aponta o caso, não só o total — um total
  divergente sem o caso não ajuda ninguém a agir.

## T-080 — AlfaGym: chave da matriz e endereços de leitura [pendente]

- Refs: AC-096
- Arquivos: tests/Feature/Integracao/ContratoAlfaGymTest.php
- Notas: **trabalho no repositório do AlfaGym** (`~/dev/AlfaGym`), pacote novo espelhando o de
  monitoramento que já existe lá: filtro de chave própria, envelope, consulta entre inquilinos
  no molde do serviço de métricas. Conferir o dialeto das migrações antes de escrever — há
  arquivo em sintaxe de um banco dentro de um projeto que roda outro. A prova mecânica vive
  aqui, no arquivo listado, alimentada pelas amostras capturadas na T-081.

## T-081 — Ligar de verdade e provar o formato [pendente]

- Refs: AC-097
- Arquivos: tests/Fixtures/Integracao/alfagym/clientes.json, tests/Fixtures/Integracao/alfagym/licencas.json, tests/Fixtures/Integracao/alfagym/contadores.json, README.md
- Notas: gerar a chave, ligar a matriz no AlfaGym, capturar as respostas reais e commitá-las como
  amostra. Fecha ASM-030, ASM-031, ASM-033 e ASM-034, e mede quanto tempo a sincronização leva
  de verdade. Mesmo molde da tarefa de execução real da feature `gestao-acessos`.
