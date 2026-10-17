# Contrato: rotas web do Secretário

[PROPOSTA — a validar na implementação]. São rotas novas, todas no grupo autenticado de `routes/web.php`
(`auth`, `verified`, `conta-ativa`, `senha-em-dia`) e com `permissao:secretario`.

Nenhuma rota recebe id de usuário. Item, aviso ou proposta de outra conta responde **404**. JSON em snake_case,
como o resto do AlfaMatriz.

| Método | Rota | Nome | Ação de permissão | O que faz |
|---|---|---|---|---|
| GET | `/secretario` | `secretario.meu-dia` | ler | Tela Meu Dia (Blade). |
| GET | `/secretario/itens` | `secretario.itens` | ler | `{atrasados: Item[], hoje: Item[], proximos: Item[], sem_data: Item[], proximo: Item\|null, telegram_pareado: bool}` |
| POST | `/secretario/interpretar` | `secretario.interpretar` | incluir | Corpo `{texto}` (até `central_ia.max_texto`). Resposta: `{proposta: {id, resumo, dados}}`, `{falta: "quando", pergunta}` ou `{nao_e_do_secretario: true}`. Não grava item. |
| POST | `/secretario/propostas/{id}/confirmar` | `secretario.propostas.confirmar` | incluir | Cria o item e programa os avisos. Idempotente: a segunda chamada devolve o mesmo item. |
| POST | `/secretario/propostas/{id}/descartar` | `secretario.propostas.descartar` | incluir | |
| POST | `/secretario/itens/{id}/acao` | `secretario.itens.acao` | editar | Corpo `{acao: concluir\|adiar_15\|adiar_60\|amanha\|cancelar\|cancelar_esta\|cancelar_todas}`. Responde o item atualizado ou 409 `{encerrado: true}`. |
| POST | `/secretario/itens/{id}/reagendar` | `secretario.itens.reagendar` | editar | Corpo `{texto}` ("quinta às 15h"), interpretado só como "quando". |
| GET | `/secretario/configuracoes` | `secretario.configuracoes` | ler | Tela de configurações. |
| PUT | `/secretario/configuracoes` | `secretario.configuracoes.salvar` | editar | `{auto_confirmar: bool, antecedencia_padrao: 0..1440, telegram: bool}` |

**Item** (resposta): `{id, tipo, titulo, descricao, quando (ISO local), quando_texto ("sáb, 17/10 às 09:00"), so_data, prioridade, status, recorrencia_texto, atrasado: bool}`.

## Chat da Central (sem rota nova)

`POST /central-ia/enviar` (`CentralIaController::enviar`) é interceptado depois da checagem do código de 6
dígitos. Se o texto é do Secretário e a conta tem `secretario,incluir`:
- grava a mensagem do usuário e a resposta do "Secretário" (`AUTOR_SISTEMA`) na conversa;
- devolve o mesmo formato do status direto da Central: `{id, status_direto: true}`, com 201 (ou 200 no reenvio
  do mesmo `client_id`).
- Se `tarefa_id` vier de outra conta, o Secretário não intercepta, e o fluxo normal da Central recusa.

Sem a permissão, nada muda.
