<?php

namespace App\Console\Commands;

use App\Models\Orgao;
use App\Support\GeradorDeCarga;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nexplangov:carga-demo {--orgao=PMVV : Sigla do órgão fictício} {--processos=500 : Quantos processos gerar}')]
#[Description('Gera volume de dados fictícios para teste de carga (nunca em produção)')]
class CargaDemo extends Command
{
    public function handle(GeradorDeCarga $gerador): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('A carga fictícia não roda em produção.');

            return self::FAILURE;
        }

        $orgao = Orgao::where('sigla', $this->option('orgao'))->first();
        if (! $orgao) {
            $this->error('Órgão não encontrado. Rode antes: php artisan db:seed --class=DemoSeeder');

            return self::FAILURE;
        }

        $qtd = max(1, min(20000, (int) $this->option('processos')));
        $inicio = microtime(true);
        $r = $gerador->gerar($orgao, $qtd);

        $this->table(['Registro', 'Gerados'], collect($r)->map(fn ($v, $k) => [str_replace('_', ' ', $k), $v])->values()->all());
        $this->info(sprintf('Pronto em %.1f s, no órgão %s.', microtime(true) - $inicio, $orgao->sigla));

        return self::SUCCESS;
    }
}
