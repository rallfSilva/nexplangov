<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Contrarrazões de um licitante ao recurso de outro (art. 165, § 4º). */
#[Fillable(['orgao_id', 'recurso_id', 'fornecedor_id', 'texto', 'registrado_por'])]
class RecursoContrarrazao extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'recurso_contrarrazoes';

    public function recurso(): BelongsTo
    {
        return $this->belongsTo(Recurso::class);
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    protected function rotuloAuditoria(): string
    {
        return "Contrarrazões ao recurso #{$this->recurso_id}";
    }
}
