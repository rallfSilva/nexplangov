<?php

namespace App\Console\Commands;

use App\Models\Orgao;
use Database\Seeders\FluxosPadrao;
use Database\Seeders\ModelosPadrao;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nexplangov:modelos-padrao {--orgao= : Sigla de um órgão (padrão: todos)}')]
#[Description('Instala os modelos de documentos e o fluxo padrão nos órgãos que ainda não os têm (idempotente)')]
class InstalarModelosPadrao extends Command
{
    public function handle(): int
    {
        $orgaos = Orgao::query()->when($this->option('orgao'), fn ($q, $s) => $q->where('sigla', mb_strtoupper($s)))->get();

        foreach ($orgaos as $orgao) {
            $novos = ModelosPadrao::instalarPara($orgao);
            $fluxo = FluxosPadrao::instalarPara($orgao);
            $this->info("{$orgao->sigla}: {$novos} modelo(s) novo(s)".($fluxo ? ' e fluxo padrão criado.' : '.'));
        }

        return self::SUCCESS;
    }
}
