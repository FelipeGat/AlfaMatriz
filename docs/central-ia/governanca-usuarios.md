# Administração individual da Central de IA

Implementação local na branch Felipe. Não habilita contas, executa inferência ou altera produção. Reutiliza o JSON da tabela de preferências existente; não exige migração.

## Operação

Em **Atividade → Usuário**, o administrador seleciona uma conta por seu ID estável. Pode consultar contagens de solicitações, provedor, operação, estado e horários. Prompts, respostas, erros detalhados, projetos, arquivos, sessões e credenciais continuam privados, inclusive para administradores. A seleção não concede acesso ao módulo nem às conversas.

Em **Ajustes → Administração**, selecione Alexandre ou outra conta e configure provedores permitidos, solicitações por dia por provedor, teto total e concorrência. São limites superiores: as preferências do proprietário só podem restringi-los. Zero bloqueia o uso; ausência de teto não elimina os controles globais. Atualizações usam revisão para rejeitar gravações concorrentes. Revogação elimina os metadados administrativos carregados no navegador quando o servidor nega a próxima consulta.

Administrar exige conta ativa, sem escopo de revenda e permissões `usuarios:ler` e `usuarios:editar`, além do acesso vigente à Central. Não basta enviar um ID pelo navegador. Todas as consultas e alterações são autorizadas no servidor. A política administrativa não pode ser removida pelo formulário pessoal.

## Bloqueio de execução para outras contas

O executor instalado ainda utiliza permissões amplas do usuário Linux. Não é possível afirmar que um agente em execução esteja impedido tecnicamente de fazer deploy ou acessar OneDrive nesse ambiente. Por isso, contas sem as permissões administrativas acima **não recebem execuções**, mesmo sem política salva. Web, Telegram, roteamento, fallback e retirada da fila aplicam o bloqueio. Deploy e OneDrive permanecem falsos na política; o cliente não pode desbloquear esses campos. As configurações de provedor e cotas ficam preparadas, mas não habilitam a execução de Alexandre.

O acesso amplo previamente autorizado para Felipe permanece. Este controle não encerra processos já iniciados nem controla CLIs executados fora da Central. Não existe garantia de proteção contra administradores do sistema operacional ou acesso direto ao banco.

## Próxima etapa necessária

Antes de liberar execução para Alexandre: autenticação própria por provedor; executor individual com diretórios permitidos por tarefa; sem credenciais pessoais de Felipe, integrações OneDrive, Docker ou destinos de produção; isolamento de rede e ferramentas; testes negativos de leitura, escrita, deploy e integrações. A liberação deverá ser explícita e não ocorrer por alteração de um checkbox. A versão instalada do runner também precisa ser reconciliada com o código versionado.

## Validação e reversão

Testes automatizados cobrem autorização, DTO sem conteúdo privado, preservação da política frente a manipulação das preferências pessoais, cotas efetivas, campos proibidos, conflito de revisão e bloqueio de trabalho pendente. Testes JavaScript cobrem resposta atrasada após troca de usuário, payload restrito e limpeza após revogação.

Nenhuma política real foi gravada por scripts nesta etapa. Não foram acessadas conversas reais de Alexandre. Não houve reinício de serviços, inferência, alteração do Telegram ou migração.

Rollback: preservar os registros de preferências e o histórico. Antes de reverter o código, manter as contas não administrativas e seus runners bloqueados; versões anteriores podem ignorar a política e recuperar permissões amplas. Não apagar a tabela nem remover as políticas para reverter a interface. Validar novamente autorização, fila e Telegram antes de qualquer liberação.

Resultado desta etapa: suíte integral passou com **1.521 testes / 9.190 verificações**, PHP 8.2.30, banco sintético isolado. Após ajuste da semântica de teto nulo, governança novamente validada: **5 testes / 31 verificações**. Permanecem duas depreciações preexistentes do PHPUnit. `test-governanca.mjs`, `test-ajustes.mjs`, build Vite e `git diff --check` passaram. A revisão visual no navegador pelo usuário permanece pendente; não houve teste com credenciais reais de Alexandre.
