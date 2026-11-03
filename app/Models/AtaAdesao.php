<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['orgao_id', 'ata_item_id', 'aderente_nome', 'aderente_cnpj', 'quantidade', 'justificativa', 'vantagem', 'situacao', 'motivo_decisao', 'decidida_por', 'decidida_em', 'solicitada_por'])]
class AtaAdesao extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'ata_adesoes';

    protected function casts(): array
    {
        return ['quantidade' => 'decimal:4', 'decidida_em' => 'datetime'];
    }

    public function ataItem(): BelongsTo
    {
        return $this->belongsTo(AtaItem::class);
    }

    protected function rotuloAuditoria(): string
    {
        return "Adesão de {$this->aderente_nome} à ata";
    }
}
