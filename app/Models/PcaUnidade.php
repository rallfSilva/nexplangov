<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Parte do PCA de uma unidade compradora, como o PNCP recebe o plano (art. 12, § 1º, e art. 174). */
#[Fillable(['orgao_id', 'plano_contratacao_id', 'unidade_id', 'numero_controle_pncp'])]
class PcaUnidade extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'pcas_unidade';

    public function plano(): BelongsTo
    {
        return $this->belongsTo(PlanoContratacao::class, 'plano_contratacao_id');
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function envios(): MorphMany
    {
        return $this->morphMany(EnvioPncp::class, 'publicavel')->latest('id');
    }

    /** @return Collection<int, ItemPca> os itens do plano que esta unidade publica */
    public function itens(): Collection
    {
        $grupos = $this->plano->gruposPncp();

        return $this->plano->itens()->with('unidade')->whereIn('id', $grupos['grupos'][$this->unidade_id] ?? [])->get();
    }

    protected function rotuloAuditoria(): string
    {
        return 'PCA '.($this->plano?->ano).' da unidade '.($this->unidade?->sigla);
    }
}
