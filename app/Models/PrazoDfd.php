<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Data-limite para as unidades enviarem demandas ao PCA de um exercício (art. 12, VII). */
#[Fillable(['orgao_id', 'exercicio', 'data_limite', 'definido_por'])]
class PrazoDfd extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'prazos_dfd';

    protected function casts(): array
    {
        return ['data_limite' => 'date', 'exercicio' => 'integer'];
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'definido_por');
    }

    protected function rotuloAuditoria(): string
    {
        return "Prazo de envio de demandas para o PCA {$this->exercicio}";
    }
}
