<?php

namespace App\Jobs;

use App\Licitacao\Cotacao\CotadorIa;
use App\Licitacao\Ia\IaIndisponivel;
use App\Licitacao\RegraViolada;
use App\Models\ItemProcesso;
use App\Models\LoteCotacaoIa;
use App\Models\User;
use App\Notifications\CotacaoIaConcluida;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cota um item de um lote de cotação por IA (art. 23, § 1º, II). Uma falha no item fica registrada nele e não
 * interrompe o lote. Quando o último item termina, quem pediu recebe o aviso por e-mail.
 */
class CotarItemComIa implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $loteId, public int $itemId) {}

    public function handle(CotadorIa $cotador): void
    {
        $lote = LoteCotacaoIa::withoutGlobalScopes()->find($this->loteId);
        $item = ItemProcesso::withoutGlobalScopes()->with('processo')->find($this->itemId);
        if (! $lote || $lote->situacao === 'concluido') {
            return;
        }
        LoteCotacaoIa::withoutGlobalScopes()->whereKey($lote->id)->where('situacao', 'na_fila')->update(['situacao' => 'processando']);

        $cotado = false;
        try {
            if (! $item || $item->processo_id !== $lote->processo_id) {
                throw new RegraViolada(['O item foi excluído do processo.']);
            }
            if (! $item->processo->editavel()) {
                throw new RegraViolada(['O processo saiu da fase preparatória: os preços não mudam mais.']);
            }
            $r = $cotador->cotar($item, $lote->resultados, User::findOrFail($lote->user_id));
            $cotado = $r['situacao'] === 'cotado';
            $this->marcar($item, $r['situacao'], $r['mensagem']);
        } catch (RegraViolada|IaIndisponivel $e) {
            $this->marcar($item, 'erro', $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $this->marcar($item, 'erro', 'Falha inesperada ao cotar o item. Tente de novo mais tarde.');
        }

        $this->avancar($lote, $cotado);
    }

    private function marcar(?ItemProcesso $item, string $situacao, string $mensagem): void
    {
        $item?->forceFill(['cotacao_ia_situacao' => $situacao, 'cotacao_ia_mensagem' => mb_substr($mensagem, 0, 500), 'cotacao_ia_em' => now()])->saveQuietly();
    }

    /** Contadores atualizados direto no banco, para dois itens em paralelo não se sobrescreverem. */
    private function avancar(LoteCotacaoIa $lote, bool $cotado): void
    {
        $q = LoteCotacaoIa::withoutGlobalScopes()->whereKey($lote->id);
        $q->increment('itens_processados', 1, $cotado ? ['itens_cotados' => DB::raw('itens_cotados + 1')] : []);

        $fechou = LoteCotacaoIa::withoutGlobalScopes()->whereKey($lote->id)->where('situacao', '!=', 'concluido')
            ->whereColumn('itens_processados', '>=', 'total_itens')->update(['situacao' => 'concluido', 'concluido_em' => now()]);

        if ($fechou) {
            $lote = $lote->fresh();
            $lote->usuario?->notify(new CotacaoIaConcluida($lote));
        }
    }
}
