<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['orgao_id', 'ata_id', 'item_id', 'quantidade_registrada', 'valor_unitario'])]
class AtaItem extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'ata_itens';

    protected function casts(): array
    {
        return ['quantidade_registrada' => 'decimal:4', 'valor_unitario' => 'decimal:4'];
    }

    public function ata(): BelongsTo
    {
        return $this->belongsTo(Ata::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemProcesso::class, 'item_id');
    }

    public function adesoes(): HasMany
    {
        return $this->hasMany(AtaAdesao::class);
    }

    public function consumos(): HasMany
    {
        return $this->hasMany(AtaConsumo::class);
    }

    /** Consumo do que foi registrado para o órgão gerenciador e os participantes. */
    public function consumido(): float
    {
        return (float) $this->consumos()->whereNull('adesao_id')->sum('quantidade');
    }

    /** Saldo do que foi registrado para o gerenciador e os participantes. */
    public function saldo(): float
    {
        return round((float) $this->quantidade_registrada - $this->consumido(), 4);
    }

    public function autorizadoEmAdesoes(): float
    {
        return (float) $this->adesoes()->where('situacao', 'autorizada')->sum('quantidade');
    }

    public function consumidoEmAdesoes(): float
    {
        return (float) $this->consumos()->whereNotNull('adesao_id')->sum('quantidade');
    }

    protected function rotuloAuditoria(): string
    {
        return "Item registrado #{$this->item_id} da ata #{$this->ata_id}";
    }
}
