# Revisão aprovada: um banco no AlfaMatriz local

Decisão de 10/10/2026: o módulo nativo terá o banco MariaDB do AlfaMatriz
como única fonte persistente de usuários, conversas, mensagens, integrações,
execuções e Telegram. O PostgreSQL antigo permanece preservado durante os
ensaios e até a transferência aprovada e recuperação comprovada.

## Mudanças em relação ao candidato anterior

- Reutilizar `users` e autenticação/permissões nativas. Não importar usuários,
  hashes de senha, APP_KEY ou sessões da Central como contas AlfaMatriz.
- Usar tabelas de domínio com prefixo `central_ia_` e `user_id` proprietário
  apontando para a conta AlfaMatriz confirmada. Não colidir com tabelas de
  mensagens, tarefas, auditoria, cache, sessões ou jobs existentes.
- Manter IDs legados de conversa/mensagem/entrega como referências únicas
  de origem. Adaptar chaves estrangeiras transacionalmente, sem depender
  de coincidências entre IDs dos dois bancos.
- Manter a identidade exclusiva, estado ativo e permissão específica em
  toda operação. Administradores não recebem exceção.
- Integrar consultas e envio diretamente ao módulo Laravel; tickets e
  grants entre duas aplicações deixam de ser necessários para a interface
  final. O candidato anterior fica preservado como referência e testes.
- Telegram e filas passam a usar o mesmo domínio e banco do AlfaMatriz.
  Manter somente um consumidor, com cursor e idempotência preservados.
- Redis continua apenas como infraestrutura auxiliar de fila/locks; não
  será uma segunda fonte de histórico ou cadastros.
- Executores permanecem separados, sem acesso direto ao banco administrativo,
  socket Docker, home do host ou credenciais de outros serviços. Recebem
  somente contexto explicitamente autorizado por tarefa.

## Ensaio anterior à ativação

1. Usar restauração descartável do MariaDB e do PostgreSQL já cifrados.
   Não executar aplicações, agentes ou consumidor Telegram nesses bancos.
2. Criar tabelas novas somente no MariaDB descartável. Definir o mapeamento
   de proprietário explicitamente para fixture de identidade; não presumir
   identidade real por email ou IDs coincidentes.
3. Transferir conversas, mensagens, vínculos, cursor, entregas, execuções,
   contadores e auditoria. Não importar cookies/sessões de login. Referências
   de autenticação de agentes permanecem externas e não são copiadas.
4. Validar textos, autoria, datas/fusos, JSON, nulos, booleanos, UUIDs, IDs,
   ordenação, constraints, estados e consumo com digests e metadados, sem
   expor conteúdo privado. Repetir a importação e comprovar idempotência.
5. Testar acesso cruzado, administrador, revogação, envio duplicado e
   cursor Telegram. Nenhuma requisição deve chegar à API do bot no ensaio.
6. Apresentar resultados, arquivos e janela de transição antes de modificar
   o banco local usado pelo proprietário ou reiniciar consumidores existentes.

## Rollback e preservação

Manter origens, backups e chaves restritos. Migração real somente aditiva,
sem apagar usuários/tabelas existentes. Após aprovação específica, controlar
escritas da Central para obter snapshot final, transferir diferenças e trocar
o único consumidor. Não rodar dois consumidores nem reenviar entregas antigas.
Em caso de falha, impedir novas escritas, preservar mensagens posteriores e
reconciliar antes de retomar o consumidor anterior. Não executar `down`
destrutivo ou restaurar snapshot antigo sobre mensagens novas.

## Git em 10/10/2026

O repositório local `/home/duque_de_caxias/projetos/alfamatriz` está na
branch `main`, commit `1e2bbb9`. O trabalho candidato e a documentação estão
em `/home/duque_de_caxias/projetos/central-agentes`, fora desse repositório.
Nenhum commit ou push deste trabalho foi feito pelo executor. A referência
local `origin/Felipe` não comprova o estado atual remoto sem atualização.
Preparar entrega revisável para `Felipe` exige autorização específica para
criar checkout separado e registrar commits; nunca mover a branch do serviço
8096 em execução. Segredos, bancos, backups, runtime e vendor são excluídos.
