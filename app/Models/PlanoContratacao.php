<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Plano de Contratações Anual (art. 12, VII). Depois de aprovado, só muda por revisão registrada. */
#[Fillable(['orgao_id', 'ano', 'situacao', 'observacoes'])]
class PlanoContratacao extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'planos_contratacao';

    public const SITUACOES = ['rascunho' => 'Em elaboração', 'em_aprovacao' => 'Em aprovação', 'aprovado' => 'Aprovado'];

    protected function casts(): array
    {
        return ['aprovado_em' => 'datetime'];
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ItemPca::class)->orderBy('numero');
    }

    public function aprovador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprovado_por');
    }

    public function editavel(): bool
    {
        return $this->situacao !== 'aprovado';
    }

    public function valorTotal(): float
    {
        return (float) $this->itens()->sum('valor_total_estimado');
    }

    protected function rotuloAuditoria(): string
    {
        return 'PCA '.$this->ano;
    }
}
