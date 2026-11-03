<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['orgao_id', 'processo_id', 'item_id', 'fornecedor_id', 'valor', 'marca_modelo', 'situacao', 'motivo', 'me_epp', 'habilitacao', 'habilitacao_motivo', 'desempate_criterio', 'desempate_justificativa', 'registrada_por'])]
class Proposta extends Model
{
    use Auditable, BelongsToOrgao;

    protected function casts(): array
    {
        return ['valor' => 'decimal:4', 'me_epp' => 'boolean'];
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemProcesso::class, 'item_id');
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function lances(): HasMany
    {
        return $this->hasMany(Lance::class)->orderBy('id');
    }

    /** Valor vigente: o último lance ou, sem lances, a proposta inicial. */
    public function valorVigente(): float
    {
        return (float) ($this->lances()->latest('id')->value('valor') ?? $this->valor);
    }

    protected function rotuloAuditoria(): string
    {
        return "Proposta do item #{$this->item_id}";
    }
}
