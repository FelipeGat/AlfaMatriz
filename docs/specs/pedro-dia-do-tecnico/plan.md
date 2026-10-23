# Implementation Plan: o Pedro lança e corrige o dia do técnico

**Spec**: [spec.md](spec.md) · **Research**: [research.md](research.md) ·
**Data model**: [data-model.md](data-model.md) · **Contrato**: [contracts/runner-agenda.md](contracts/runner-agenda.md) ·
**Cenários**: [quickstart.md](quickstart.md)

## Summary

O Pedro passa a ler a agenda dos técnicos pelo retrato do Gestor (uma lista `visitas` a mais no
caminho que já existe, só leitura) e a lançar, corrigir e cancelar visitas por uma fila nova de
mudanças, que só é preenchida depois do "sim" de quem é diretor ou gestor e é aplicada no Gestor
pela mesma máquina e ponte que já gravam os atendimentos. A IA propõe as paradas; o código confere
cada uma contra o retrato (ids, técnico, horário, conflitos) antes de mostrar a prévia e antes de
gravar; o Gestor reconfere o estado da visita na hora de mudar.

Entrega em três publicações, cada uma utilizável sozinha:

1. **Consulta** (story 1): retrato com `visitas`, bloco de agenda no pedido interno.
2. **Prévia** (story 2): relato → paradas → conferência → prévia com conflitos e lacunas, sem gravar.
3. **Gravação e correção** (stories 3 e 4): fila, script do Gestor, serviço de visitas no Gestor.

## Technical Context

| Campo | Valor |
|---|---|
| Linguagem / framework | PHP 8.x, Laravel 12 (AlfaMatriz e Gestor); runner em Node (`deploy/central-ia/runner.mjs`) |
| Armazenamento | MariaDB (AlfaMatriz), MySQL 8 (Gestor); testes do AlfaMatriz em SQLite |
| Integração com o Gestor | ponte da máquina do dono (`C:\tmp\run_prod.py`), scripts em `deploy/central-ia/` |
| IA | runner da conta do Pedro, pedido montado em `App\Services\Suporte\Contexto` |
| Testes | PHPUnit no AlfaMatriz (`tests/Feature/Suporte/`); Pest no Gestor (`tests/Integracao/`) |
| Restrições | só acesso interno; só diretor/gestor alteram; nada grava sem "sim"; Gestor reconfere |
| Escala | dezenas de visitas por dia; janela de 21 dias no retrato |

Sem `NEEDS CLARIFICATION` restante.

## Constitution Check

*Gate antes do Phase 0 e de novo depois do design.*

| Princípio | Status | Notas |
|---|---|---|
| I. Privacidade por conta | PASS | Agenda só para números da lista de acesso interno; a fila é da conta do Pedro, como as aberturas. Nenhuma rota recebe "quem sou eu" como parâmetro. |
| II. Cada pessoa com as suas IAs e a sua máquina | PASS | Leitura e gravação pelo runner da conta do Pedro, token `central-ia-runner`. |
| III. Um sistema só | PASS | Tabela nova no banco do AlfaMatriz, prefixo `suporte_` como as demais do Pedro; sem segunda aplicação. |
| IV. Segredos fora de código | PASS | Nada novo de credencial; a ponte e o token do runner já existem. |
| V. Prova executável | PASS | Teste por story (quickstart); migração aditiva com `down()`; o runner só conta como entregue reiniciado e observado. |
| VI. Acesso total com rastro | PASS | Cada mudança guarda quem pediu (FR-014) e o resultado; falha vira registro na fila. |
| VII. Autorização explícita | PASS | Escrita em produção do Gestor só depois do "sim" de quem pediu, mudança por mudança; publicação de cada entrega só com autorização do dono. |
| VIII. Zero fabricação | PASS | A agenda vem do retrato com a hora da leitura; o contrato novo está marcado como PROPOSTA; os valores de `status` da visita serão conferidos contra o modelo do Gestor na implementação. |

**Gestor** (constituição própria, `~/projetos/gestor/docs/constitution.md`): a extração do serviço
de visitas segue a regra de camadas (VI) e o fluxo branch → QA → PR (III); responder a seção
`App:` (IV) — o app usa o mesmo controller da API, que passará a chamar o serviço.

## Project Structure

```
AlfaMatriz (~/projetos/alfamatriz-felipe-central-ia)
├── deploy/central-ia/
│   ├── suporte_gestor_ler.php        ← + lista visitas, equipe com técnicos sem telefone
│   ├── suporte_gestor_agenda.php     ← NOVO (entrega 3)
│   ├── suporte.mjs                   ← + gravarAgenda (entrega 3)
│   └── runner.mjs                    ← + volta da fila de agenda (entrega 3)
├── app/Http/Controllers/SuportePedroRunnerController.php   ← visitas no retrato; rotas da fila
├── app/Services/Suporte/
│   ├── DiretorioGestor.php           ← visitas(), tecnico() por nome aproximado
│   ├── AcessoInterno.php             ← bloco de agenda; papel diretor/gestor
│   ├── Contexto.php                  ← regras do fluxo de agenda no pedido interno
│   ├── AgendaDoTecnico.php           ← NOVO: confere paradas, monta prévia, enfileira no "sim"
│   └── Pedro.php                     ← ação "agenda" no concluir()
├── app/Models/SuporteGestorAgenda.php            ← NOVO
├── database/migrations/*_suporte_agenda_do_tecnico.php   ← NOVO (coluna visitas + tabela)
└── tests/Feature/Suporte/PedroAgendaDoTecnicoTest.php    ← NOVO

Gestor (~/projetos/gestor)
├── app/Services/AtendimentoVisitaService.php     ← NOVO: reagendar e cancelar (extraído)
├── app/Http/Controllers/Api/V1/Admin/AtendimentoVisitaController.php   ← passa a usar o serviço
└── tests/Integracao/AtendimentoVisitaServiceTest.php   ← NOVO
```

## Convenções de Borda

| Camada | Estilo | Validação | Fonte da verdade |
|---|---|---|---|
| Banco do Gestor | snake_case (`data_inicio`, `funcionario_id`) | — | `atendimento_visitas` em produção |
| Linha `SUPORTE_GESTOR:` / `SUPORTE_AGENDA:` | snake_case em português (`tecnico_id`, `inicio`) | o servidor confere item a item | `contracts/runner-agenda.md` |
| Rotas do runner | kebab/minúsculas como as existentes | Request do Laravel | `routes/api.php` |
| Bloco JSON da IA (`<<<PEDRO … PEDRO>>>`) | snake_case em português | `Contexto::ler()` | prompt em `Contexto.php` |

Datas e horas: `AAAA-MM-DD HH:MM` no fuso do Gestor (America/Sao_Paulo) em todo o caminho; o
Matriz não converte (o banco do AlfaMatriz é UTC — conversão só na exibição).

## Riscos

- **IA cita id inexistente ou horário sem fim** → bloqueado pela conferência do código (Decision 7).
- **Retrato atrasado** → Gestor reconfere `esperado` (Decision 4).
- **Máquina desligada no "sim"** → mudança fica `pendente`; o aviso de fora do ar e a retomada de
  v2026.10.23.1 já cobrem a conversa; a mudança vence como as aberturas.
- **Gestor finaliza visita passada às 23:55** → comportamento aceito e comunicado (Decision 6).

## Complexity Tracking

Sem violação de princípio a justificar.
