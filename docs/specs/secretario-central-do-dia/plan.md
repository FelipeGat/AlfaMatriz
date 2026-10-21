# Implementation Plan: Secretário, Etapa 8 (Central do dia e de gestão)

**Spec**: [spec.md](spec.md) · 17/10/2026

## Summary

O comando `secretario:la-fora` (a cada 30 min) busca dólar, clima, Flamengo e notícias e guarda no cache. A
Central de gestão monta, sem IA, os números do dia e os blocos Grupo Soluções (retrato do Gestor) e VixCard (pontos
lançados, que são itens do Secretário com o campo `empresa`). As regras vão para Configurações. Minha agenda vira um
calendário Alpine (Dia, Semana e Mês) que lê a agenda consolidada em JSON. As caixas vêm do Gestor por um script da
ponte, que entrega o JSON direto ao servidor do Matriz por SSH, sem passar pela conversa. O IMAP passa a ler FLAGS.

## Constitution Check

| Princípio | Status | Notas |
|---|---|---|
| I. Privacidade | PASS | Pontos, números e caixas por `user_id`. "Lá fora" é público e não leva nada da conta. |
| II. IA na máquina | PASS | Nada aqui usa IA. |
| III. Um sistema | PASS | Mesmo cron, mesmas tabelas `secretario_*`, mais um campo. |
| IV. Segredos | PASS | A senha vai do cofre do Gestor ao cofre do Matriz; o arquivo de passagem é apagado. |
| V. Prova | PASS | Testes e conferência local e em produção. |
| VII. Externa | PASS | Só GET em fontes públicas; nada da conta sai. |
| VIII. Zero fabricação | PASS | Cada bloco com a fonte e a hora; fonte velha marcada; sem placar inventado. |

## Design

- `App\Services\Secretario\LaFora` com `buscar()` (fontes, 8 s cada, parse de JSON e RSS) e `ler()` (cache e idade);
  comando `secretario:la-fora`.
- Migração: `empresa` (nullable, até 40) em `secretario_itens`; `lido` (boolean, padrão true) em `secretario_emails`.
- `ClienteImap`: FETCH de `FLAGS` junto com o cabeçalho; `LeitorDeEmails` grava `lido` e reconfere as mensagens dos
  últimos 3 dias.
- `CentralDeGestao`: números e blocos; a view de Acompanhamentos ganha o topo, e as regras saem dela.
- `secretario/configuracoes.blade.php` ganha a seção de regras (mesmo PUT de antes).
- `secretario/agenda.blade.php` vira calendário; rota JSON `secretario.agenda.eventos?de=&ate=`.
- Script `deploy/central-ia/copiar_caixas_do_gestor.php` (ponte, só leitura) e comando
  `secretario:importar-caixas {email} {arquivo}` no Matriz.
