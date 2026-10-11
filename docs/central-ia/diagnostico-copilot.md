# Diagnóstico GitHub Copilot — 11/10/2026

## Confirmado

- Capturas fornecidas pelo proprietário: conta GitHub FelipeGat, assinatura Copilot Free, créditos incluídos 91% utilizados naquele momento e CLI habilitado. Não há confirmação de renovação ou quantidade absoluta de créditos; a captura não comprova a conta autenticada no runner.
- WSL: executável /home/duque_de_caxias/.local/bin/copilot, versão 1.0.95. `gh` não encontrado no PATH consultado.
- Ajuda instalada confirma `/user` para identidade e `/usage` para uso; crédito de IA é a unidade atual, enquanto planos legados podem exibir premium.
- Coletor `uso-local.mjs` soma `session.usage_record.data.usage.cost` no campo premium. Essa soma local não é consulta à franquia nem saldo oficial. A tela foi corrigida para mostrar a origem e deixar saldo não verificado, sem percentual ou restante fictícios.

## Pendências

Confirmar `/user` e `/usage` no Copilot autenticado usado pelo runner. Não consultar arquivos de autenticação, copiar tokens, mudar contas ou usar `-p` para isso. Nenhuma sessão interativa/agente foi iniciada nesta investigação; somente versão e ajuda. Evitar inicialização de plugins/hooks desconhecidos apenas para obter a identidade. O operador pode usar uma sessão existente e enviar somente nome de conta, plano, crédito usado/restante e renovação.

Verificar futuramente integração oficial para coleta de cotas em modo não interativo. Não usar endpoints internos não documentados nem converter custo legado para créditos por hipótese. Manter autenticação e medição por usuário e origem/data de coleta. Credenciais Microsoft/OneDrive não substituem as do GitHub.

Fontes oficiais:
- https://docs.github.com/en/copilot/how-tos/copilot-cli/set-up-copilot-cli/authenticate-copilot-cli
- https://docs.github.com/en/copilot/reference/copilot-cli-reference/cli-command-reference

Sem inferência, instalação, troca de autenticação ou cobrança adicional. Alteração somente na identificação visual do consumo, sem migração ou reinício.
