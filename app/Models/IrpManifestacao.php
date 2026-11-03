<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['orgao_id', 'irp_id', 'unidade_id', 'participante', 'descricao', 'unidade_medida', 'quantidade', 'justificativa', 'situacao', 'motivo_recusa', 'enviada_por'])]
class IrpManifestacao extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'irp_manifestacoes';

    protected function casts(): array
    {
        return ['quantidade' => 'decimal:4'];
    }

    public function irp(): BelongsTo
    {
        return $this->belongsTo(Irp::class);
    }

    protected function rotuloAuditoria(): string
    {
        return "Manifestação de {$this->participante} na IRP #{$this->irp_id}";
    }
}
