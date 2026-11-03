<?php

namespace App\Console\Commands;

use App\Licitacao\Alertas\EnviadorDeAlertas;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nexplangov:alertas')]
#[Description('Envia por e-mail os alertas de prazos (contratos, atas, peças, impugnações e entregas)')]
class EnviarAlertas extends Command
{
    public function handle(EnviadorDeAlertas $enviador): int
    {
        $this->info($enviador->enviar().' resumo(s) enviado(s).');

        return self::SUCCESS;
    }
}
