<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Consumo do saldo da ata (empenho ou ordem de fornecimento). Registro que não se altera nem se apaga. */
#[Fillable(['orgao_id', 'ata_item_id', 'adesao_id', 'unidade_id', 'quantidade', 'referencia', 'user_id'])]
class AtaConsumo extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'ata_consumos';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['quantidade' => 'decimal:4', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Consumo da ata não pode ser alterado.'));
        static::deleting(fn () => throw new LogicException('Consumo da ata não pode ser apagado.'));
    }

    public function ataItem(): BelongsTo
    {
        return $this->belongsTo(AtaItem::class);
    }

    protected function rotuloAuditoria(): string
    {
        return "Consumo de {$this->quantidade} no item #{$this->ata_item_id} da ata";
    }
}
