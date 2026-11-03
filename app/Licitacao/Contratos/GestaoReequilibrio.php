<?php

namespace App\Licitacao\Contratos;

use App\Licitacao\Enums\TipoTermo;
use App\Licitacao\RegraViolada;
use App\Models\Contrato;
use App\Models\Reequilibrio;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reequilíbrio econômico-financeiro (art. 124, II, d). Deferido, vira termo aditivo de valor. Como é
 * alteração por acordo, não entra no limite de 25% do art. 125, que vale para as alterações unilaterais.
 */
final class GestaoReequilibrio
{
    public function solicitar(Contrato $c, string $fundamento, string $motivo, float $valor, User $u): Reequilibrio
    {
        $m = [];

        if ($c->situacao !== 'vigente') {
            $m[] = 'O contrato não está vigente.';
        }
        if (! array_key_exists($fundamento, Reequilibrio::FUNDAMENTOS)) {
            $m[] = 'Fundamento inválido (art. 124, II, d).';
        }
        if (mb_strlen(trim($motivo)) < 30) {
            $m[] = 'Descreva o fato e como ele desequilibrou o contrato (mínimo 30 caracteres), respeitada a repartição de riscos do contrato.';
        }
        if ($valor <= 0) {
            $m[] = 'Informe o valor pedido.';
        }
        if ($m) {
            throw new RegraViolada($m);
        }

        return Reequilibrio::create([
            'orgao_id' => $c->orgao_id, 'contrato_id' => $c->id, 'fundamento' => $fundamento, 'motivo' => trim($motivo),
            'valor_pedido' => $valor, 'solicitado_em' => today(), 'solicitado_por' => $u->id,
        ]);
    }

    public function decidir(Reequilibrio $r, bool $deferir, ?float $concedido, string $parecer, User $u): void
    {
        $m = [];

        if ($r->situacao !== 'solicitado') {
            $m[] = 'Este pedido já foi decidido.';
        }
        if ($r->solicitado_por === $u->id) {
            $m[] = 'Quem pediu não decide o próprio pedido.';
        }
        if (mb_strlen(trim($parecer)) < 20) {
            $m[] = 'Fundamente a decisão (mínimo 20 caracteres).';
        }
        if ($deferir && ($concedido === null || $concedido <= 0 || $concedido > (float) $r->valor_pedido)) {
            $m[] = 'O valor concedido precisa ser maior que zero e não passar do pedido.';
        }
        if ($deferir && $r->contrato->situacao !== 'vigente') {
            $m[] = 'O contrato não está vigente.';
        }
        if ($m) {
            throw new RegraViolada($m);
        }

        DB::transaction(function () use ($r, $deferir, $concedido, $parecer, $u) {
            $termoId = null;

            if ($deferir) {
                $c = $r->contrato;
                $termo = $c->termos()->create([
                    'orgao_id' => $c->orgao_id, 'tipo' => TipoTermo::AditivoValor->value, 'numero' => 'REQ-'.$r->id, 'assinatura' => today(),
                    'valor_acrescimo' => $concedido, 'valor_supressao' => 0, 'valor_reajuste' => 0, 'justificativa' => 'Reequilíbrio econômico-financeiro: '.trim($parecer), 'reequilibrio' => true,
                ]);
                $c->forceFill(['valor_global' => round((float) $c->valor_global + $concedido, 2)])->save();
                $termoId = $termo->id;
            }

            $r->update([
                'situacao' => $deferir ? 'deferido' : 'indeferido', 'valor_concedido' => $deferir ? $concedido : null,
                'parecer' => trim($parecer), 'decidido_em' => today(), 'decidido_por' => $u->id, 'termo_id' => $termoId,
            ]);
        });
    }
}
