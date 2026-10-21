<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fechamento mensal: usa lastDayOfMonth (não monthlyOn(30, ...)) porque nem
// todo mês tem dia 30 — assim fevereiro também fecha corretamente.
Schedule::command('app:fechar-competencia-mensal')
    ->lastDayOfMonth('20:00')
    ->withoutOverlapping()
    ->onOneServer();

// Despesas fixas do mês no dia 01 (18/10/2026): antes nasciam no fechamento
// do último dia, já vencidas. O fechamento segue e não duplica.
Schedule::command('despesas:gerar-fixas')
    ->monthlyOn(1, '06:00')
    ->withoutOverlapping()
    ->onOneServer();

// Retrato dos sistemas integrados: leve por hora corrige o que mudou no dia.
// Sem --sistema, percorre todos os que declaram `sincroniza` e estão
// configurados — um sistema novo entra sozinho ao ser configurado, sem mexer
// no agendamento.
Schedule::command('alfa:sincronizar-sistemas')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Lembretes da Agenda. O compromisso avisa perto da hora (a cada cinco
// minutos, uma janela de 30 min — ver `Compromisso::LEMBRETE_MINUTOS`); o
// prazo avisa de manhã, no dia do vencimento. Os dois escrevem no sino, e o
// carimbo `lembrete_enviado_em` é quem impede o compromisso de repetir.
Schedule::command('agenda:lembrar-compromissos')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('agenda:lembrar-prazos')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();

// Avisos antecipatórios do dia: o que VAI vencer (despesa/receita amanhã,
// licença em 7 dias) e o lead parando. Antes das condições da fila de ação,
// não em vez delas — ver o docblock do comando. De manhã, como os prazos.
Schedule::command('avisos:do-dia')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();

// Limpeza da Central de IA (T-378): anexos e prévias com mais de 90 dias,
// histórico com mais de 1 ano (prazos em `central_ia.retencao`). De
// madrugada, longe do uso; o que apagou fica no log.
Schedule::command('central-ia:limpar')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();

// Lembretes do Secretário (docs/specs/secretario-executivo): a cada minuto,
// porque o aviso tem de chegar na hora. Sem fila e sem IA — chega com a
// máquina do dono desligada. O texto dos itens não vai para o log.
Schedule::command('secretario:despachar')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Google Agenda do Secretário (docs/specs/secretario-agenda): eventos de
// ontem a +30 dias, a cada 10 min. É o que deixa a pergunta pelo chat e o
// briefing responderem na hora, sem depender de o Google responder naquele
// instante nem da máquina do dono.
Schedule::command('secretario:sincronizar-google')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Briefing do Secretário (docs/specs/secretario-briefing): no horário de cada
// conta (padrão 07:00, seg–sex), um por dia; parado no horário, sai na volta
// até o meio-dia. Montado por regra — não depende da IA nem da máquina do dono.
Schedule::command('secretario:briefing')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Acompanhamento proativo (docs/specs/secretario-acompanhamento): as regras
// de cada conta a cada 5 min e o resumo pela política dela (silêncio,
// intervalo, teto). Por regra, sem IA.
Schedule::command('secretario:acompanhar')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// "Lá fora" do Meu Dia (docs/specs/secretario-central-do-dia): dólar, clima,
// Flamengo e notícias de fontes públicas, para o cache. Só GET.
Schedule::command('secretario:la-fora')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Reuniões do Secretário (docs/specs/secretario-reunioes): transcrição presa há
// mais de 60 min volta para a fila; na 3ª tentativa falha e o áudio é apagado.
Schedule::command('secretario:reunioes')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// E-mails do Secretário (docs/specs/secretario-emails-gestor): a cada 10 min,
// só leitura (EXAMINE + BODY.PEEK — nunca marca como lido), só metadados.
Schedule::command('secretario:emails')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Projetos da VixCard (docs/specs/secretario-vixorganize): a fila do
// VixOrganize das contas conectadas, a cada 10 min. Só GET.
Schedule::command('secretario:vixorganize')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Os alertas do AlfaMonitor que o webhook não entregou (#399): o monitor
// desiste depois de ~8h40, e o Matriz fora mais que isso os perderia. A
// cada hora pede ao monitor o que veio depois do último já conferido — quase
// sempre nada de novo. Fora da hora cheia, longe da sincronização.
Schedule::command('alfa:monitor-reconciliar')
    ->hourlyAt(20)
    ->withoutOverlapping()
    ->onOneServer();

// Boleto da revenda por cliente (docs/specs/cobranca-revendas-boleto): o
// fechamento do último dia gera a cobrança; às 08:00 sai o boleto (no dia 01,
// ou no seguinte se falhar), e de hora em hora o Mercado Pago é consultado
// para dar baixa no que foi pago.
Schedule::command('cobrancas:emitir-boletos-revendas')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('cobrancas:conferir-boletos')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// O histórico dos alertas do AlfaMonitor (#394) guarda 90 dias
// (`MonitorEvento::DIAS_DE_HISTORICO`); o estado de agora não depende dele.
Schedule::command('model:prune', ['--model' => [\App\Models\MonitorEvento::class]])
    ->dailyAt('03:40')
    ->withoutOverlapping()
    ->onOneServer();

// O Pedro, do suporte no WhatsApp (docs/specs/suporte-pedro-whatsapp): na abertura
// (07:00, dias úteis) põe na fila quem escreveu fora do horário, e a cada minuto
// responde sozinho, sem IA, o pedido que a máquina não atendeu a tempo. Também
// cobra o atendimento crítico e o nº do Gestor pendente, no intervalo da tela Suporte › Pedro.
Schedule::command('suporte:pedro')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// A base do suporte (T-476): a conversa que o Pedro atendeu sozinho e o cliente
// largou além da janela de 24 h vira registro "encerrado sem confirmação".
Schedule::command('suporte:registros')
    ->hourlyAt(17)
    ->withoutOverlapping()
    ->onOneServer();

// Os tokens do quadro que a Central de IA emite por pedido (T-472) vencem
// sozinhos, mas a linha fica: um pedido por vez, todo dia, enche a tabela.
Schedule::command('sanctum:prune-expired', ['--hours' => 24])
    ->dailyAt('03:50')
    ->withoutOverlapping()
    ->onOneServer();
