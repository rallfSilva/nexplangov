<?php

namespace App\Licitacao;

use App\Licitacao\Regras\TramiteUnidades as R;
use App\Models\Processo;
use App\Models\TramiteProcesso;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Movimenta os autos entre unidades: remessa, recebimento, cancelamento da remessa (só antes do recebimento),
 * arquivamento e desarquivamento. Não muda a situação do processo, que é da TramitacaoProcesso.
 */
final class TramitacaoUnidades
{
    public function enviar(Processo $p, User $u, ?int $destino, ?string $despacho): TramiteProcesso
    {
        $this->exigir(R::motivosRemessa($p->arquivado(), $p->remessaPendente() !== null, $p->unidade_atual_id, $destino, $u->atuaNaUnidade($p->unidade_atual_id), $despacho));

        return TramiteProcesso::create([
            'orgao_id' => $p->orgao_id, 'processo_id' => $p->id, 'tipo' => 'remessa', 'unidade_origem_id' => $p->unidade_atual_id,
            'unidade_destino_id' => $destino, 'despacho' => trim((string) $despacho), 'enviado_por' => $u->id, 'enviado_em' => now(),
        ]);
    }

    public function receber(TramiteProcesso $t, User $u): void
    {
        $this->exigir(R::motivosRecebimento($t->pendente(), $u->atuaNaUnidade($t->unidade_destino_id)));

        DB::transaction(function () use ($t, $u) {
            $t->update(['recebido_por' => $u->id, 'recebido_em' => now()]);
            $t->processo->forceFill(['unidade_atual_id' => $t->unidade_destino_id])->save();
        });
    }

    public function cancelar(TramiteProcesso $t, User $u, ?string $motivo): void
    {
        $this->exigir(R::motivosCancelamento($t->pendente(), $t->enviado_por === $u->id || $u->atuaNaUnidade($t->unidade_origem_id), $motivo));

        $t->update(['cancelado_por' => $u->id, 'cancelado_em' => now(), 'motivo_cancelamento' => trim((string) $motivo)]);
    }

    public function arquivar(Processo $p, User $u, ?string $despacho): void
    {
        $this->exigir(R::motivosArquivamento($p->situacaoEnum()->encerrado(), $p->arquivado(), $p->remessaPendente() !== null, $u->atuaNaUnidade($p->unidade_atual_id), $despacho));

        DB::transaction(function () use ($p, $u, $despacho) {
            $this->registrar($p, $u, 'arquivamento', $despacho);
            $p->forceFill(['arquivado_em' => now(), 'arquivado_por' => $u->id])->save();
        });
    }

    public function desarquivar(Processo $p, User $u, ?string $motivo): void
    {
        $this->exigir(R::motivosDesarquivamento($p->arquivado(), $u->atuaNaUnidade($p->unidade_atual_id), $motivo));

        DB::transaction(function () use ($p, $u, $motivo) {
            $this->registrar($p, $u, 'desarquivamento', $motivo);
            $p->forceFill(['arquivado_em' => null, 'arquivado_por' => null])->save();
        });
    }

    /**
     * Permanência dos autos em cada unidade, com o responsável que recebeu.
     *
     * @return list<array{unidade: ?int, transito: bool, inicio: CarbonImmutable, fim: ?CarbonImmutable, dias: float, responsavel: ?int}>
     */
    public function periodos(Processo $p): array
    {
        $tramites = $p->tramites()->get()->map(fn (TramiteProcesso $t) => [
            'tipo' => $t->tipo, 'origem' => $t->unidade_origem_id, 'destino' => $t->unidade_destino_id, 'enviado_em' => $t->enviado_em,
            'recebido_em' => $t->recebido_em, 'cancelado_em' => $t->cancelado_em, 'recebido_por' => $t->recebido_por,
        ])->all();

        // Antes da primeira remessa os autos estavam na unidade de origem do primeiro trâmite (ou na atual, se nunca saíram).
        $inicial = $p->tramites()->where('tipo', 'remessa')->whereNull('cancelado_em')->value('unidade_origem_id') ?? $p->unidade_atual_id;

        return R::periodos($p->created_at, $inicial, $p->responsavel_id, $tramites, now());
    }

    private function registrar(Processo $p, User $u, string $tipo, ?string $texto): void
    {
        TramiteProcesso::create([
            'orgao_id' => $p->orgao_id, 'processo_id' => $p->id, 'tipo' => $tipo, 'unidade_origem_id' => $p->unidade_atual_id,
            'despacho' => trim((string) $texto), 'enviado_por' => $u->id, 'enviado_em' => now(),
        ]);
    }

    /** @param list<string> $motivos */
    private function exigir(array $motivos): void
    {
        if ($motivos !== []) {
            throw new RegraViolada($motivos);
        }
    }
}
