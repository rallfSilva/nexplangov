<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pedido de reequilíbrio econômico-financeiro (art. 124, II, d). */
#[Fillable(['orgao_id', 'contrato_id', 'fundamento', 'motivo', 'valor_pedido', 'valor_concedido', 'situacao', 'solicitado_em', 'decidido_em', 'parecer', 'termo_id', 'solicitado_por', 'decidido_por'])]
class Reequilibrio extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'reequilibrios';

    public const FUNDAMENTOS = [
        'caso_fortuito_forca_maior' => 'Caso fortuito ou força maior',
        'fato_do_principe' => 'Fato do príncipe',
        'fato_imprevisivel' => 'Fato imprevisível',
        'fato_previsivel_incalculavel' => 'Fato previsível de consequências incalculáveis',
    ];

    protected function casts(): array
    {
        return ['valor_pedido' => 'decimal:2', 'valor_concedido' => 'decimal:2', 'solicitado_em' => 'date', 'decidido_em' => 'date'];
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    protected function rotuloAuditoria(): string
    {
        return "Reequilíbrio do contrato #{$this->contrato_id}";
    }
}
