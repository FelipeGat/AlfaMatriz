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

// Sincronização com os sistemas da casa: uma completa de madrugada e uma leve
// de hora em hora (Q-013 decide os horários finais). O registro em
// sincronizacoes é o que permite descobrir se a rotina parou sem ninguém
// perceber — foi exatamente esse defeito que esta seção veio corrigir.
Schedule::command('app:sincronizar-sistemas --escopo=completa --origem=agendada')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('app:sincronizar-sistemas --escopo=clientes --origem=agendada')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
