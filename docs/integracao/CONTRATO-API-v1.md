# Contrato da API da Matriz — v1

> Este documento é a **fonte única** do que cada sistema da casa precisa expor
> para conversar com o AlfaMatriz. Ele mora no repositório do AlfaMatriz de
> propósito: o consumidor é quem define o contrato, e cinco sistemas
> implementando cinco variações é exatamente o que ele existe para evitar.
>
> Alterou este arquivo? Registre em [CHANGELOG.md](CHANGELOG.md).

## Sumário

- [Endereço e autenticação](#endereço-e-autenticação)
- [Envelope das respostas](#envelope-das-respostas)
- [Erros](#erros)
- [Paginação](#paginação)
- [Evolução do contrato](#evolução-do-contrato)
- [Endereços](#endereços)
- [Formatos](#formatos)
- [O que o sistema NÃO precisa fazer](#o-que-o-sistema-não-precisa-fazer)

---

## Endereço e autenticação

Todos os endereços vivem sob o prefixo:

```
/api/matriz/v1
```

Autenticação por cabeçalho dedicado:

```
X-Matriz-Key: <chave em claro>
Accept: application/json
```

Regras inegociáveis:

1. **Chave própria, não reaproveitada.** Alguns sistemas já expõem uma API de
   monitoramento com chave própria (`X-Monitor-Key`), somente de leitura e já
   distribuída. A chave da matriz é outra. Reaproveitar a do monitoramento daria
   a ele, no futuro, o poder de liberar e bloquear licença de cliente pagante.
2. **O sistema guarda apenas o resumo criptográfico da chave** (SHA-256), nunca
   a chave em claro. Quem gera a chave é a matriz, que a guarda cifrada.
3. **Propriedade de configuração vazia = integração desligada.** Sem chave
   configurada, todo pedido é recusado. É o padrão seguro para um sistema que
   ainda não foi integrado.
4. **A recusa não revela qual parte estava errada.** Cabeçalho ausente e chave
   inválida devolvem exatamente a mesma resposta — dizer "chave inválida"
   confirma para quem sonda que o cabeçalho certo foi encontrado.

## Envelope das respostas

Toda coleção responde no mesmo formato:

```json
{
  "contrato": "1.0",
  "sistema": "alfagym",
  "gerado_em": "2026-08-07T13:04:11-03:00",
  "pagina": { "numero": 1, "tamanho": 200, "total_itens": 743, "total_paginas": 4 },
  "dados": []
}
```

Recurso único (`/ping`, `/contadores`) usa o mesmo envelope, com `dados` como
objeto e sem `pagina`.

- `contrato` — versão do contrato que a resposta cumpre. **Obrigatório.**
- `sistema` — identificador curto e estável do sistema (`alfagym`,
  `alfacontrol`, `alfahome`, `alfajornada`, `alfamed`).
- `gerado_em` — data e hora ISO-8601 **com deslocamento de fuso**. A matriz opera
  em horário de São Paulo e guarda em UTC; sem o deslocamento, três horas somem
  em silêncio.

## Erros

```json
{
  "contrato": "1.0",
  "erro": {
    "codigo": "cliente_nao_encontrado",
    "mensagem": "Nenhuma academia com o identificador 128.",
    "detalhes": {}
  }
}
```

O catálogo é **fechado**: a matriz só entende os códigos abaixo, e trata
qualquer outro como erro genérico do sistema.

| Código | HTTP | Quando |
|---|---|---|
| `nao_autenticado` | 401 | chave ausente ou inválida (mesma resposta para os dois) |
| `nao_autorizado` | 403 | chave válida, mas sem direito àquela operação |
| `cliente_nao_encontrado` | 404 | identificador de cliente inexistente |
| `licenca_nao_encontrada` | 404 | identificador de licença inexistente |
| `licenca_ja_ativa` | 409 | tentativa de liberar o que já está liberado |
| `plano_invalido` | 422 | plano informado não existe no sistema |
| `cnpj_duplicado` | 422 | documento já pertence a outro cadastro |
| `competencia_invalida` | 422 | competência fora do formato `AAAA-MM` |
| `operacao_nao_suportada` | 501 | o sistema não implementa aquele endereço |
| `limite_de_taxa` | 429 | pedidos demais |
| `erro_interno` | 500 | falha não prevista |
| `indisponivel` | 503 | sistema em manutenção ou sem banco |

A mensagem é para gente ler: ela aparece literalmente na tela do painel. Nunca
inclua rastro de pilha, consulta de banco nem dado de outro cliente nela.

## Paginação

```
?pagina=1&tamanho=200
```

- `tamanho` padrão 200, teto 500.
- **Ordenação estável obrigatória por `id_externo`.** Sem ordem estável, um
  registro inserido durante a varredura empurra outro para uma página já lida, e
  ele desaparece do retrato local sem que ninguém perceba.
- `?atualizado_desde=<ISO-8601>` é opcional. Mesmo quando existe, a matriz faz
  uma varredura completa por dia — é o que corrige um retrato que ficou torto.

## Evolução do contrato

- **Campo novo** numa resposta: continua v1. O consumidor ignora o que não
  conhece.
- **Remover ou renomear campo**, mudar tipo, mudar significado: exige `/v2`. O
  `/v1` continua respondendo até todos os sistemas migrarem.
- A matriz **recusa** resposta cuja versão principal de contrato ela não
  conhece, e registra o erro `contrato_incompativel` em vez de gravar dado torto
  no retrato local. Um retrato errado é pior que um retrato ausente: ele parece
  confiável.

## Endereços

Esta versão do contrato cobre **somente leitura**. Os endereços de escrita
(provisionar cliente, liberar licença, modo de manutenção) entram na próxima
versão, quando a matriz passar a ser dona do cadastro.

| Método | Endereço | Devolve |
|---|---|---|
| GET | `/ping` | situação do sistema e versão do contrato |
| GET | `/revendas` | coleção de `revenda` |
| GET | `/clientes` | coleção de `cliente` |
| GET | `/planos` | coleção de `plano` |
| GET | `/licencas` | coleção de `licenca` |
| GET | `/usuarios` | coleção de `usuario` |
| GET | `/financeiro?competencia=AAAA-MM` | coleção de `fatura` |
| GET | `/contadores?competencia=AAAA-MM` | objeto `contadores` |

## Formatos

### ping

```json
{
  "sistema": "alfagym",
  "versao": "2026.08.1",
  "contrato": "1.0",
  "unidade_cobranca": "academia ativa",
  "relogio": "2026-08-07T13:04:11-03:00",
  "cadastro_local_aberto": true
}
```

`cadastro_local_aberto` declara se o painel do próprio sistema ainda aceita
cadastrar cliente. É como a matriz sabe se a virada já valeu naquele sistema.

### revenda

```json
{
  "id_externo": "3",
  "nome": "Invest Soluções",
  "cnpj": "12345678000199",
  "email": "contato@investsolucoes.com.br",
  "telefone": "14999999999",
  "ativo": true,
  "clientes_ativos": 18
}
```

`cnpj` vai **só com dígitos**. A matriz normaliza de qualquer forma, mas mandar
formatado transfere para ela a decisão sobre qual máscara é a certa.

### cliente

```json
{
  "id_externo": "128",
  "nome": "Academia Corpo em Movimento",
  "razao_social": "Corpo em Movimento LTDA",
  "cpf_cnpj": "98765432000155",
  "email": "financeiro@corpoemmovimento.com.br",
  "telefone": "14988888888",
  "cidade": "Bauru",
  "uf": "SP",
  "ativo": true,
  "status": "ativo",
  "revenda_id_externo": "3",
  "unidades_ativas": 1,
  "criado_em": "2025-03-11T09:22:00-03:00",
  "atualizado_em": "2026-08-01T17:40:12-03:00"
}
```

`status` ∈ `ativo` · `pendente` · `bloqueado` · `cancelado`.

**`unidades_ativas` é o campo mais importante do contrato inteiro.** É a
quantidade da unidade de cobrança declarada em `/ping` que aquele cliente
representa — e é o que a matriz confronta com o que a Alfa faturou da revenda.
Exemplos: no AlfaGym, `1` (uma academia); no AlfaMed, o número de vidas ativas;
no AlfaJornada, o número de funcionários ativos. Subdivisão interna do cliente
(filial, unidade, empresa dentro do cliente) **não** conta, a menos que ela seja
a unidade de cobrança declarada.

### plano

```json
{
  "id_externo": "2",
  "nome": "Growth",
  "ativo": true,
  "preco_mensal": 599.00,
  "moeda": "BRL",
  "limites": { "max_clientes": 500, "max_usuarios": 20 }
}
```

Existe para a matriz poder escolher um plano ao liberar licença, em vez de
mandar texto livre. `limites` é livre: a matriz guarda e mostra, não interpreta.

### licenca

```json
{
  "id_externo": "91",
  "cliente_id_externo": "128",
  "revenda_id_externo": "3",
  "status": "ativa",
  "plano": "Growth",
  "plano_id_externo": "2",
  "tipo": "mensal",
  "inicio_em": "2026-07-01",
  "fim_em": "2026-08-01",
  "dias_para_vencer": 24,
  "bloqueia_acesso": true,
  "liberada_por": "Rossini",
  "liberada_em": "2026-07-01T10:12:00-03:00"
}
```

`status` ∈ `ativa` · `pendente` · `vencida` · `bloqueada` · `cancelada`.
`fim_em` nulo significa sem expiração.

**`bloqueia_acesso` impede a matriz de mentir.** Nem todo sistema barra o login
quando a licença vence — em alguns, vencer é decorativo. A tela de confirmação
de bloqueio lê este campo para dizer a verdade a quem está clicando.

Sistema **sem entidade de licença própria** (que guarda vigência em campos do
cliente) deve devolver uma linha derivada, com
`id_externo = "cliente:{id_externo do cliente}"`. O identificador nunca pode ser
nulo: a chave única do retrato local depende dele.

### usuario

```json
{
  "id_externo": "512",
  "cliente_id_externo": "128",
  "nome": "Marina Alves",
  "email": "marina@corpoemmovimento.com.br",
  "papel": "admin",
  "ativo": true,
  "ultimo_acesso_em": "2026-08-06T19:03:00-03:00"
}
```

Só os administradores do cliente. **Nunca** devolver senha, resumo de senha,
token de sessão ou qualquer credencial.

### fatura

```json
{
  "id_externo": "lic-91-2026-08",
  "cliente_id_externo": "128",
  "revenda_id_externo": "3",
  "competencia": "2026-08",
  "valor": 599.00,
  "moeda": "BRL",
  "status": "aberto",
  "vencimento_em": "2026-08-10",
  "pago_em": null,
  "dias_em_atraso": 0,
  "unidades_cobradas": 1,
  "plano": "Growth",
  "licenca_id_externo": "91",
  "origem": "derivado"
}
```

`status` ∈ `pago` · `aberto` · `vencido` · `cancelado`.

`origem` ∈ `titulo` · `derivado`. **`derivado` marca que o sistema não tem
título de cobrança de verdade e a linha foi inferida da licença.** A tela de
divergências não acusa diferença em cima de linha derivada — seria falso alarme
contra um número que o próprio sistema não considera oficial.

Escopo: **só o que o sistema cobra do cliente pela licença.** O financeiro
interno do produto (mensalidade de aluno, conta a receber de condômino,
lançamento de família) **não** entra neste contrato.

### contadores

```json
{
  "competencia": "2026-08",
  "unidade_cobranca": "academia ativa",
  "clientes_total": 40,
  "clientes_ativos": 33,
  "clientes_pendentes": 2,
  "clientes_bloqueados": 5,
  "unidades_ativas": 33,
  "licencas_ativas": 33,
  "licencas_vencendo": 6,
  "licencas_vencidas": 4,
  "faturado_no_sistema": 8210.00,
  "por_revenda": [
    {
      "revenda_id_externo": "3",
      "nome": "Invest Soluções",
      "clientes_ativos": 18,
      "unidades_ativas": 18,
      "valor": 4230.00
    }
  ]
}
```

`por_revenda` existe para a tela de divergências resolver a comparação em **uma**
chamada, em vez de somar milhares de linhas do lado da matriz.

## O que o sistema NÃO precisa fazer

Para não haver dúvida sobre o tamanho do trabalho de cada sistema:

- **Não** precisa avisar a matriz de nada (nenhum aviso de mudança). Quem
  pergunta é a matriz.
- **Não** precisa guardar histórico nem série temporal. A matriz guarda.
- **Não** precisa calcular MRR, churn, nem indicador nenhum. A matriz calcula.
- **Não** precisa expor o financeiro interno do produto.
- **Não** precisa de tela nova. Só dos endereços e da propriedade de
  configuração da chave.
