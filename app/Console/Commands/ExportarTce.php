<?php

namespace App\Console\Commands;

use App\Integracoes\Tce\ExportadorTce;
use App\Models\Orgao;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('nexplangov:exportar-tce {orgao : Sigla do órgão} {inicio : Data inicial (AAAA-MM-DD)} {fim : Data final (AAAA-MM-DD)} {--saida= : Caminho do ZIP}')]
#[Description('Gera a remessa ao tribunal de contas (layout genérico NexPlanGov)')]
class ExportarTce extends Command
{
    public function handle(ExportadorTce $exportador): int
    {
        $orgao = Orgao::where('sigla', mb_strtoupper((string) $this->argument('orgao')))->first();
        if (! $orgao) {
            $this->error('Órgão não encontrado.');

            return self::FAILURE;
        }
        $inicio = Carbon::parse($this->argument('inicio'));
        $fim = Carbon::parse($this->argument('fim'));
        $saida = $this->option('saida') ?: storage_path("app/private/remessa-tce-{$orgao->sigla}-{$inicio->format('Ymd')}-{$fim->format('Ymd')}.zip");

        $exportador->gerar($orgao, $inicio, $fim, $saida);
        $this->info("Remessa gerada em {$saida}");

        return self::SUCCESS;
    }
}
