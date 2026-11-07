# Implementation Plan: Notificações tratadas uma a uma em Acompanhamentos

**Spec**: [spec.md](spec.md) · 07/11/2026

## Summary

Sem migração: "tratada" é a mesma `lida_em` que o sino já usa, para o número do sino e a lista de Acompanhamentos
contarem a mesma coisa. A tela de Acompanhamentos ganha um partial com a lista e o controller passa as não lidas, as
tratadas de 7 dias e o aviso em destaque. Tratado e Desfazer vão por `fetch` às rotas do sino (`notificacoes.lida`,
já existente, e `notificacoes.nao-lida`, nova). O destino de Abrir passa a sair do modelo (`Notificacao::destino`).

## Constitution Check

| Princípio | Status | Notas |
|---|---|---|
| I. Privacidade | PASS | Toda consulta por `destinatario_id` da conta logada; destaque alheio é ignorado; rotas novas dão 404 para aviso de outro. |
| III. Um sistema | PASS | Mesma tabela `notificacoes`, mesmas rotas do sino; nada paralelo. |
| V. Prova | PASS | Testes de tela, ação, isolamento e destino; suíte; teste local na 8096. |
| VII. Externa | PASS | Nada sai do sistema. |
| VIII. Zero fabricação | PASS | O excedente acima de 300 é dito com o número real. |

## Design

- `Notificacao::tom()`: o mapa de gravidade (crit, warn, brand) num lugar só, usado pelo sino, pelo card e pela lista.
- `Notificacao::destino()`: tarefa com rota do quadro vira `tarefas.index?tarefa=N` (mantendo o resto da query); toda
  rota é refeita no endereço atual (`url(caminho)`), porque os avisos antigos guardaram o host da tailnet.
- `NotificacaoController@abrir` usa `destino()`; `@marcarNaoLida` (POST `notificacoes/{id}/nao-lida`) devolve o
  número do sino em JSON, como o `marcarLida`.
- `SecretarioAcompanhamentosController@index`: `notificacoes` (não lidas, críticas primeiro, até 300),
  `notificacoesTotal`, `tratadas` (lidas há até 7 dias, até 50) e `notificacaoFoco` (`?notificacao=`, só da conta).
- `secretario/_notificacoes.blade.php`: abas, busca, as 20 primeiras e "ver todas", linha com Abrir/Tratado/Desfazer.
  O Alpine da seção lê e escreve o `naoLidas` do `shell`, então o número do sino acompanha.
- Sino (`layouts/_notificacoes-lista`, `layouts/notificacoes`): link da linha e rodapé condicionados a
  Acompanhamentos (sem escopo de revenda e com `secretario:ler`, a mesma régua do menu).
