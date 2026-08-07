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

// Retrato do AlfaGym: leve por hora corrige o que mudou no dia; a varredura
// completa fica para o comando manual (app:sincronizar-alfagym).
Schedule::command('app:sincronizar-alfagym')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
