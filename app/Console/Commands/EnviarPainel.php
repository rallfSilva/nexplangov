<?php

namespace App\Console\Commands;

use App\Integracoes\IntegracaoIndisponivel;
use App\Integracoes\Painel\ClientePainel;
use App\Integracoes\Painel\EnvioPainel;
use App\Integracoes\Painel\MapeadorPainel;
use App\Models\Ata;
use App\Models\Contrato;
use App\Models\Empenho;
use App\Models\Fornecedor;
use App\Models\ItemPca;
use App\Models\Liquidacao;
use App\Models\Orgao;
use App\Models\Processo;
use App\Models\TermoContrato;
use App\Models\Unidade;
use Illuminate\Console\Command;

/**
 * Carga inicial (ou recarga) do Painel de Compras: envia tudo o que já existe, na ordem de dependência.
 * Reenviar é seguro: o painel atualiza o que já tem, sem duplicar. Depois disso, o envio é automático a cada alteração.
 */
class EnviarPainel extends Command
{
    protected $signature = 'painel:enviar {--orgao= : Sigla do órgão (padrão: todos)} {--simular : Só conta os registros, sem enviar}';

    protected $description = 'Envia ao Painel de Compras (SIC/SELC) os dados já cadastrados';

    public function handle(ClientePainel $cliente, EnvioPainel $envio): int
    {
        if (! $this->option('simular') && ! $cliente->habilitado()) {
            $this->error('Configure PAINEL_URL e PAINEL_TOKEN no .env (o token é gerado no painel com php artisan sic:token-integracao NEXPLANGOV).');

            return self::FAILURE;
        }
        $orgaos = Orgao::query()->when($this->option('orgao'), fn ($q, $s) => $q->where('sigla', $s))->get();
        if ($orgaos->isEmpty()) {
            $this->error('Nenhum órgão encontrado.');

            return self::FAILURE;
        }

        foreach ($orgaos as $o) {
            $map = new MapeadorPainel;
            $map->adicionar($o);
            $modelos = [Unidade::class, Fornecedor::class, Processo::class, Ata::class, Contrato::class, TermoContrato::class, Empenho::class, Liquidacao::class];
            foreach ($modelos as $modelo) {
                $modelo::query()->where('orgao_id', $o->id)->lazyById(200)->each(fn ($m) => $map->adicionar($m));
            }
            ItemPca::query()->where('orgao_id', $o->id)->with('plano.orgao', 'unidade')->lazyById(200)->each(fn ($m) => $map->adicionar($m));
            $lotes = $map->lotes();

            $this->info("{$o->sigla}: ".collect($lotes)->map(fn ($r, $t) => "$t ".count($r))->implode(' · '));
            if ($this->option('simular')) {
                continue;
            }
            try {
                foreach ($envio->enviar($lotes, null, $o->id, "Carga inicial {$o->sigla}") as $r) {
                    $r['estado'] === 'enviado' ? $this->line("  {$r['tipo']}: lote {$r['lote']} na fila do painel") : $this->warn("  {$r['tipo']}: {$r['erro']}");
                }
            } catch (IntegracaoIndisponivel $e) {
                $this->error($e->getMessage().' Rode o comando de novo quando o painel voltar; reenviar não duplica.');

                return self::FAILURE;
            }
        }
        $this->line('Acompanhe cada lote no painel em Importação de dados > Histórico (origem NexPlanGov).');

        return self::SUCCESS;
    }
}
