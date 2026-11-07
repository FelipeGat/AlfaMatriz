# Implementation Plan: De quem é cada coisa em Acompanhamentos

**Spec**: [spec.md](spec.md) · 07/11/2026

## Summary

Sem migração: a área sai, na leitura, do que a notificação já guarda — o primeiro trecho do destino (`rota`), o
`tipo`, a `meta` "Suporte WhatsApp" e a tarefa (tipo, responsável, se nasceu de atendimento). Assim vale para os
avisos que já estão no sino, e nenhum dos mais de quarenta lugares que avisam precisa mudar.

## Constitution Check

| Princípio | Status | Notas |
|---|---|---|
| I. Privacidade | PASS | Continua por `destinatario_id`; o filtro só reparte o que a conta já via. |
| III. Um sistema | PASS | Mesma tabela, mesmas rotas; o dono da área sai de `services.areas`, como `suporte_pedro.responsaveis`. |
| V. Prova | PASS | Testes de classificação, da tela, da tela de outra pessoa e da área sem dono; suíte; 8096 nos dois temas. |
| VIII. Zero fabricação | PASS | Área sem conta fica "Sem dono", sem inventar pessoa. |

## Design

- `App\Services\Secretario\Areas`: `dosAvisos` (duas consultas para até 300 avisos: as tarefas e quais nasceram de
  atendimento), `doBlocoDoGestor`, `daArea`, `daPessoa`, `chave` (`eu`, `u<id>`, `sem`), `rotulo` e `filtro`.
- Precedência: suporte (meta "Suporte WhatsApp" ou `/suporte`; "Chamaram você" é da pessoa) → tarefa (tipos
  dirigidos à pessoa são dela; notícia é do responsável ou, sem ele, da área pelo tipo) → sincronização →
  caminho do destino → da própria pessoa.
- `CentralDeGestao::linhas`: cada linha ganha `de` — o Gestor pela área do bloco; pontos e alertas, de quem vê.
- Controller: `deAviso`, `filtroQuem` e `donosDasAreas` para a tela.
- Tela: o filtro mora no x-data da página (`quem`, `contas`, `passa()`), as duas listas filtram por ele, e o Tratado
  avisa a contagem pelo evento `tratou`. A escolha fica no `localStorage`.
