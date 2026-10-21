# Research: Secretário, Etapa 5 (e-mails e Gestor)

## Decision 1: Cliente IMAP próprio, mínimo e só de leitura

**Decision**: um `ClienteImap` pequeno (socket TLS + comandos IMAP4rev1) que só conhece os comandos de leitura:
`LOGIN`, `LIST`, `EXAMINE`, `UID SEARCH`, `UID FETCH` com `BODY.PEEK[...]`, `LOGOUT`. Não existe método para
`SELECT`, `STORE`, `COPY`, `MOVE`, `EXPUNGE`, `APPEND` nem SMTP.

**Rationale**:
- **Garantia pelo protocolo**: `EXAMINE` abre a pasta em modo só leitura (RFC 3501 §6.3.2), e `BODY.PEEK` não liga
  a marca `\Seen` (§6.4.5). O Gestor usa o `webklex/php-imap` e marca como lida ao abrir (`ImapService.php:583`);
  com um cliente que não tem comando de escrita, esse erro é impossível.
- Não traz dependência nova ao `composer.json`.

**Alternatives considered**:
- `webklex/php-imap` (o do Gestor): API ampla, inclusive escrita, e nova dependência. Rejeitada pelo motivo
  acima.
- `ext-imap`: não vem no PHP 8.4 e não é garantida no servidor.

**Fontes**: RFC 3501 (IMAP4rev1), seções 6.2.3 (LOGIN), 6.3.2 (EXAMINE), 6.4.4 (SEARCH), 6.4.5 (FETCH,
BODY.PEEK), 6.4.8 (UID) e 7.3.1 (UIDVALIDITY).

## Decision 2: Teste com servidor IMAP real

**Decision**: testes PHP com um transporte simulado (respostas IMAP gravadas). O teste local usa um servidor IMAP
de verdade em container (GreenMail), conferindo que as marcas `\Seen` não mudam depois da leitura.

## Decision 3: Classificação por regra

**Tipo**, por palavras no assunto ou no começo do texto:
- orçamento: "orçamento", "cotação";
- proposta;
- documento: "contrato", "nota fiscal", "NF", "boleto", "segue em anexo";
- reunião: "reunião", "agenda", "call";
- prazo: "prazo", "até dia", "vencimento";
- cobrança: "pagamento", "fatura", "cobrança";
- outro.

**Urgência alta**: "urgente", "hoje", "imediato", "o quanto antes" ou "prazo" com data de hoje ou amanhã.

**Aguardando resposta**, quando as três condições valem:
- remetente fora do próprio domínio da caixa;
- pedido de retorno: "?", "aguardo", "retorno", "pode me enviar", "consegue", "favor", "por gentileza";
- não há mensagem nos enviados com `In-Reply-To` igual ao `Message-ID` dela.

## Decision 4: Gestor pela ponte, só leitura

O script `deploy/central-ia/secretario_gestor.php` replica as consultas do resumo da Central do Gestor
(`CentralController.php` linhas 155-183):
- `Orcamento` com status `aguardando_aprovacao` e `em_elaboracao`;
- `Cobranca` com status ≠ `pago`, sem `prejuizo_em`, vencidas ou vencendo hoje;
- `ContaPagar` com status `em_aberto` vencendo até hoje;
- `ReuniaoCliente::comPendencia()`.

Ele também traz os leads do funil parados há mais de 7 dias (`pre_clientes.estagio_funil_em`). A saída é **uma
linha** `SECRETARIO_GESTOR:{json}`.

Onde ele roda:
- O runner o executa por `CENTRAL_IA_GESTOR="programa|ponte|script|pasta"`, no mesmo formato do `CENTRAL_IA_AGENDA`; a `pasta` (cwd da ponte) é opcional e fica na configuração da máquina, nunca no código (T-374).
- No Felipe: `C:/Python314/python.exe|C:\tmp\run_prod.py|C:\tmp\secretario_gestor.php`, rodando a partir de
  `/mnt/c/tmp` (memória `gestor-alfa-ponte-producao`: caminho Windows e `cwd` em `/mnt/c/tmp`).
- **Sem `--apply`.** Conferido no `run_prod.py` em 17/10/2026: a ponte **não** desfaz nada sozinha, só passa
`APPLY=1` com `--apply`. A garantia fica no script, em três camadas: com `APPLY=1` ele recusa rodar; a sessão
do MySQL é `READ ONLY`, então qualquer escrita falha no banco; e tudo roda numa transação desfeita no fim.

## Decision 5: Rascunho e resumo pela IA, com os trechos lidos na hora

O servidor busca na hora (com `BODY.PEEK`) até 20 mensagens × 1.500 caracteres do corpo em texto e monta a instrução
como `AgentesService::pedir`, igual à análise de reunião (Etapa 4). O texto vai marcado como dado de terceiros e
nada é guardado. O rascunho volta na conversa e na tela Mensagens, para copiar. Não existe envio.
