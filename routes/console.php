<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Resumo diário de prazos por e-mail (o servidor precisa rodar `php artisan schedule:run` a cada minuto).
Schedule::command('nexplangov:alertas')->dailyAt('07:00')->timezone('America/Sao_Paulo')->withoutOverlapping();

// Pacotes de devolução de dados vencidos: o arquivo sai do armazenamento, o registro fica.
Schedule::command('nexplangov:devolucao-limpar')->dailyAt('03:10')->timezone('America/Sao_Paulo')->withoutOverlapping();
