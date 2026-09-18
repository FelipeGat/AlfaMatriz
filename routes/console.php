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
