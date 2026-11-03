<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pedido de uma unidade para consumir saldo de um item de ata de registro de preços. */
#[Fillable(['orgao_id', 'ata_item_id', 'unidade_id', 'requisitante_id', 'quantidade', 'justificativa', 'situacao', 'motivo_decisao', 'decidida_por', 'decidida_em'])]
class Requisicao extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'requisicoes';

    public const SITUACOES = ['solicitada' => 'Solicitada', 'aprovada' => 'Aprovada', 'recusada' => 'Recusada', 'atendida' => 'Atendida', 'cancelada' => 'Cancelada'];

    protected function casts(): array
    {
        return ['quantidade' => 'decimal:4', 'decidida_em' => 'datetime'];
    }

    public function ataItem(): BelongsTo
    {
        return $this->belongsTo(AtaItem::class);
    }

    public function requisitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requisitante_id');
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    protected function rotuloAuditoria(): string
    {
        return "Requisição #{$this->id}";
    }
}
