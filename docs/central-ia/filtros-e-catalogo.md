# Períodos, consumo e catálogo de projetos

## Interface

- Atividade abre no dia atual de São Paulo. Campos De/Até e botão Hoje permitem consultar outros dias; o filtro Usuário é exclusivo de quem tem permissão administrativa. Eventos e execuções consultados pelo período não dependem do recorte recente do cockpit.
- Consumo tem filtros De/Até, Usuário e IA. A tabela agrega por dia e provedor entrada, saída, cache e requisições premium. Dados vêm dos snapshots dos runners: ausência de registros não comprova consumo zero e o sistema não inventa histórico de cotas. Cartões da assinatura e gráfico legado estão identificados como situação atual/últimos sete dias, separados do relatório filtrado; são ocultados ao selecionar outra conta ou provedor.
- Ajustes usa sliders com valor visível e suporte de teclado. Sem teto interno é uma opção explícita; zero bloqueia pedidos. Limites máximos e restrições administrativas continuam validados no backend. Concorrência respeita o teto global.
- Projetos é um catálogo separado, com Cards/Lista e agrupamento. Inclui todos os projetos informados pelo runner da conta, além dos fixados ou com atividade. Não enumera pastas do host nem revela projetos de outros usuários. Projetos ainda não agrupados aparecem em Sem grupo; o seletor permite organizá-los. Abrir leva à área de conversa. Chats Ativos permanece organizado por assunto/tarefa.

## Segurança e operação

Relatórios exigem sessão autenticada e acesso à Central. Consultar outra conta exige também governança administrativa. A visão administrativa omite projetos, conteúdo, instruções, erros e credenciais. Período máximo de um ano; até mil eventos e mil execuções com aviso para reduzir o período. Respostas atrasadas de filtros anteriores não substituem a seleção atual. Não há migração, inferência, reinício ou alteração de produção.

## Validação

146 testes da Central / 836 verificações passaram. Testes JavaScript de filtros, ajustes e governança passaram; build Vite e git diff --check passaram. Duas depreciações preexistentes do PHPUnit. Revisão visual interativa no navegador permanece com o usuário. Rollback por reversão dos commits desta etapa; nenhum dado é removido.
