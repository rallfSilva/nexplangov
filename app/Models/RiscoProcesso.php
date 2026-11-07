<?php

namespace App\Models;

use App\Licitacao\Regras\MatrizRiscos;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Linha da matriz de riscos do processo (art. 6º, XXVII, e art. 22). */
#[Fillable(['orgao_id', 'processo_id', 'ordem', 'risco', 'causa', 'consequencia', 'probabilidade', 'impacto', 'alocacao', 'tratamento', 'responsavel', 'origem', 'criado_por'])]
class RiscoProcesso extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'riscos_processo';

    protected function casts(): array
    {
        return ['probabilidade' => 'integer', 'impacto' => 'integer', 'ordem' => 'integer'];
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function nivel(): int
    {
        return MatrizRiscos::nivel($this->probabilidade, $this->impacto);
    }

    protected function rotuloAuditoria(): string
    {
        return "Risco \"{$this->risco}\" do processo";
    }
}
