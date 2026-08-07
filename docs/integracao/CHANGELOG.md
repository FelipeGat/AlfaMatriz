# Histórico do contrato da API da Matriz

Toda alteração em [CONTRATO-API-v1.md](CONTRATO-API-v1.md) entra aqui, com a
data e o motivo. Cinco sistemas leem esse documento: uma mudança silenciosa
quebra a integração de quem já implementou.

Regra de versão:

- **campo novo** numa resposta → continua `1.x`, consumidor ignora o que não conhece;
- **remover, renomear, mudar tipo ou mudar significado** → exige `/v2`, com o
  `/v1` no ar até todos migrarem.

## 1.0 — revisão de 2026-08-07, antes de qualquer implementação

Saíram do contrato: o endereço `/financeiro`, o formato `fatura`, o campo
`contadores.faturado_no_sistema` e o `valor` dentro de `por_revenda`.

**Motivo: nenhum valor em dinheiro vem dos sistemas.** O contrato do cliente
vive no AlfaMatriz (`clientes.valor_mensal`) e o preço que a revenda paga também
(tiers de atacado, que já geram a cobrança mensal consolidada). Pedir dinheiro a
cinco sistemas seria manter cinco verdades sobre a mesma coisa, e na primeira
vez que divergissem ninguém saberia qual acreditar. Dos sistemas vem só o USO:
quantas unidades estão ativas e em que estado está a licença.

O efeito prático é uma redução do trabalho de cada sistema — são dois endereços
a menos para implementar, e nenhum deles precisa mais tocar no próprio módulo
financeiro.

Como nenhum sistema tinha implementado o contrato ainda, isto é uma revisão da
1.0 e não uma versão nova. Daqui em diante, remover endereço exige `/v2`.

## 1.0 — 2026-08-07

Primeira versão. Cobre **somente leitura**.

- Prefixo `/api/matriz/v1`, autenticação por `X-Matriz-Key`, com o sistema
  guardando apenas o resumo criptográfico da chave.
- Envelope único para coleções, com paginação de ordenação estável por
  `id_externo`.
- Catálogo fechado de erros.
- Endereços: `ping`, `revendas`, `clientes`, `planos`, `licencas`, `usuarios`,
  `contadores`.
- `cliente.unidades_ativas` definido como a quantidade da unidade de cobrança
  declarada em `ping.unidade_cobranca` — é o número que a matriz confronta com
  o que a Alfa faturou da revenda.
- `licenca.bloqueia_acesso` declara se vencer a licença realmente barra o
  acesso naquele sistema. Existe porque em alguns sistemas vencer é decorativo,
  e a matriz não pode prometer um efeito que não acontece.

Os endereços de escrita (provisionar cliente, liberar licença, modo de
manutenção) ficam para a versão seguinte, quando a matriz passar a ser dona do
cadastro.
