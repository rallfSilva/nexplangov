<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Resumo diário de prazos por e-mail (o servidor precisa rodar `php artisan schedule:run` a cada minuto).
Schedule::command('nexplangov:alertas')->dailyAt('07:00')->timezone('America/Sao_Paulo')->withoutOverlapping();
Schedule::command('nexplangov:treinamento encerrar-vencidas')->dailyAt('00:30')->timezone('America/Sao_Paulo')->withoutOverlapping();
