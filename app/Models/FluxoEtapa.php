<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['orgao_id', 'fluxo_id', 'tipo', 'ordem', 'depende_de', 'prazo_dias', 'exige_revisao', 'exige_aprovacao', 'exige_assinatura', 'origem', 'responsavel_id', 'substituto_id', 'revisor_id', 'aprovador_id', 'assinantes'])]
class FluxoEtapa extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'fluxo_etapas';

    protected function casts(): array
    {
        return ['depende_de' => 'array', 'assinantes' => 'array', 'exige_revisao' => 'boolean', 'exige_aprovacao' => 'boolean', 'exige_assinatura' => 'boolean'];
    }

    public function fluxo(): BelongsTo
    {
        return $this->belongsTo(Fluxo::class);
    }

    protected function rotuloAuditoria(): string
    {
        return "Etapa {$this->tipo} do fluxo";
    }
}
