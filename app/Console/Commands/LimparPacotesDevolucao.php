<?php

namespace App\Console\Commands;

use App\Models\PacoteDevolucao;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('nexplangov:devolucao-limpar')]
#[Description('Apaga os pacotes de devolução de dados vencidos (o registro fica, o arquivo sai)')]
class LimparPacotesDevolucao extends Command
{
    public function handle(): int
    {
        $n = 0;
        PacoteDevolucao::where('situacao', 'pronto')->where('expira_em', '<', now())->each(function (PacoteDevolucao $p) use (&$n) {
            if ($p->arquivo_caminho) {
                Storage::disk('local')->delete($p->arquivo_caminho);
            }
            $p->forceFill(['situacao' => 'expirado', 'arquivo_caminho' => null])->save();
            $n++;
        });
        $this->info("{$n} pacote(s) vencido(s) apagado(s).");

        return self::SUCCESS;
    }
}
