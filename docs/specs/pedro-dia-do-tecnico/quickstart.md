# Quickstart: cenários de teste

## Cenário 1 — Consultar o dia (story 1, happy path)

1. Retrato com a visita 1185 (Vitor, 23/10 08:00–10:00, atendimento 824 do pré-cliente Revizza).
2. O Wallas (gestor, lista de acesso interno) escreve "como está o dia do Victor hoje?".
3. → **Expected**: o Pedro lista a visita com "Revizza", nº 824, 08:00–10:00, agendada, e a hora
   da leitura do retrato.

## Cenário 2 — Consultar fora da lista (erro)

1. Um número de cliente escreve "agenda do Vitor hoje".
2. → **Expected**: nenhuma visita aparece; segue o atendimento de cliente.

## Cenário 3 — Relato do Vitor de 23/10 (story 2)

1. Retrato: visita 1185 (Revizza 08:00–10:00); atendimento 756 aberto no Corais de Parati;
   Manuela e Éder sem cadastro.
2. O Wallas manda o relato do Vitor.
3. → **Expected**: prévia com 3 paradas (Corais 08:00–09:30 → visita nova no 756; Manuela 09:30–
   14:30 proposto, cliente não encontrado; Éder 14:30–18:40, cliente não encontrado), o
   **conflito** com a visita 1185 às 08:00 e a pergunta sobre cada lacuna; nenhuma mudança na fila.

## Cenário 4 — Confirmar e gravar (story 3)

1. Prévia fechada com: reagendar 1185 para 10:00–11:00; criar visita no 756 08:00–09:30.
2. O Wallas responde "sim".
3. → **Expected**: duas mudanças na fila; o runner aplica; o Gestor tem a 1185 às 10:00–11:00 e
   uma visita nova no 756; o Pedro devolve os números. Repetir o "sim" não cria nada.

## Cenário 5 — Visita mudou entre a prévia e o "sim" (erro)

1. Prévia propõe reagendar a 1185; antes do "sim", alguém muda a 1185 no Gestor.
2. → **Expected**: a mudança falha com "a visita mudou desde a prévia"; o Pedro avisa e remonta.

## Cenário 6 — Papel sem permissão (erro)

1. O Alexandre (suporte) pede "passa a visita da Revizza para as 10:00".
2. → **Expected**: o Pedro mostra a visita mas diz que só diretor e gestor alteram a agenda;
   nada vai para a fila.

## Cenário 7 — Roundtrip de verdade

1. Na base local do Gestor (cópia de produção), rodar o leitor e conferir que `visitas` sai com os
   campos do contrato; mandar ao Matriz local; aplicar uma mudança pelo script em ensaio (sem
   `--apply`) e conferir a linha `SUPORTE_AGENDA:` contra o contrato.
