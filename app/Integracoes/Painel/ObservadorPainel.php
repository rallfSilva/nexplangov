<?php

namespace App\Integracoes\Painel;

use App\Jobs\EnviarAoPainel;
use App\Models\Ata;
use App\Models\AtaItem;
use App\Models\Contrato;
use App\Models\Empenho;
use App\Models\Fornecedor;
use App\Models\ItemPca;
use App\Models\ItemProcesso;
use App\Models\Liquidacao;
use App\Models\Orgao;
use App\Models\Processo;
use App\Models\TermoContrato;
use App\Models\Unidade;
use Illuminate\Database\Eloquent\Model;

/**
 * Cada registro salvo nos módulos que o painel acompanha entra na fila de envio ao Painel de Compras,
 * depois do commit (o que foi desfeito não vai). Itens viram o envio do processo ou da ata a que pertencem.
 * Sem PAINEL_URL e PAINEL_TOKEN no .env, nada é enfileirado.
 */
final class ObservadorPainel
{
    /** @var list<class-string<Model>> */
    public const MODELOS = [Orgao::class, Unidade::class, Fornecedor::class, ItemPca::class, Processo::class, ItemProcesso::class,
        Ata::class, AtaItem::class, Contrato::class, TermoContrato::class, Empenho::class, Liquidacao::class];

    public static function registrar(): void
    {
        foreach (self::MODELOS as $modelo) {
            $modelo::saved(fn (Model $m) => self::agendar($m));
        }
    }

    public static function agendar(Model $m): void
    {
        if (! app(ClientePainel::class)->habilitado()) {
            return;
        }
        $alvo = match (true) {
            $m instanceof ItemProcesso => $m->processo,
            $m instanceof AtaItem => $m->ata,
            default => $m,
        };
        if ($alvo) {
            EnviarAoPainel::dispatch($alvo::class, (int) $alvo->getKey())->afterCommit()->delay(now()->addSeconds(30));
        }
    }
}
