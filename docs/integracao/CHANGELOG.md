# Histórico do contrato da API da Matriz

Toda alteração em [CONTRATO-API-v1.md](CONTRATO-API-v1.md) entra aqui, com a
data e o motivo. Cinco sistemas leem esse documento: uma mudança silenciosa
quebra a integração de quem já implementou.

Regra de versão:

- **campo novo** numa resposta → continua `1.x`, consumidor ignora o que não conhece;
- **remover, renomear, mudar tipo ou mudar significado** → exige `/v2`, com o
  `/v1` no ar até todos migrarem.

## 1.0 — 2026-08-07

Primeira versão. Cobre **somente leitura**.

- Prefixo `/api/matriz/v1`, autenticação por `X-Matriz-Key`, com o sistema
  guardando apenas o resumo criptográfico da chave.
- Envelope único para coleções, com paginação de ordenação estável por
  `id_externo`.
- Catálogo fechado de erros.
- Endereços: `ping`, `revendas`, `clientes`, `planos`, `licencas`, `usuarios`,
  `financeiro`, `contadores`.
- `cliente.unidades_ativas` definido como a quantidade da unidade de cobrança
  declarada em `ping.unidade_cobranca` — é o número que a matriz confronta com
  o que a Alfa faturou da revenda.
- `licenca.bloqueia_acesso` declara se vencer a licença realmente barra o
  acesso naquele sistema. Existe porque em alguns sistemas vencer é decorativo,
  e a matriz não pode prometer um efeito que não acontece.
- `fatura.origem` distingue título de verdade de linha derivada da licença, para
  a tela de divergências não acusar falso alarme.

Os endereços de escrita (provisionar cliente, liberar licença, modo de
manutenção) ficam para a versão seguinte, quando a matriz passar a ser dona do
cadastro.
