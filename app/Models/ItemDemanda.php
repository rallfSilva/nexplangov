<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Item do DFD, identificado pelo catálogo CATMAT/CATSER (art. 12, VII, e art. 19, II). */
#[Fillable(['orgao_id', 'demanda_id', 'item_pca_id', 'numero', 'material_servico', 'categoria', 'codigo_catalogo', 'descricao', 'unidade_fornecimento', 'quantidade', 'valor_unitario_estimado'])]
class ItemDemanda extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'itens_demanda';

    protected function casts(): array
    {
        return ['quantidade' => 'decimal:4', 'valor_unitario_estimado' => 'decimal:4', 'valor_total_estimado' => 'decimal:2', 'categoria' => 'integer', 'numero' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(fn (ItemDemanda $i) => $i->valor_total_estimado = round((float) $i->quantidade * (float) $i->valor_unitario_estimado, 2));
    }

    public function demanda(): BelongsTo
    {
        return $this->belongsTo(Demanda::class);
    }

    public function itemPca(): BelongsTo
    {
        return $this->belongsTo(ItemPca::class);
    }

    protected function rotuloAuditoria(): string
    {
        return "Item {$this->numero} do DFD";
    }
}
