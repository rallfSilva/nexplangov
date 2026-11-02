<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Etapa do fluxo congelada no processo: o que vale para este processo, mesmo que o modelo mude depois. */
#[Fillable(['orgao_id', 'processo_id', 'tipo', 'ordem', 'depende_de', 'prazo_dias', 'prazo_em', 'solicitado_em', 'exige_revisao', 'exige_aprovacao', 'exige_assinatura', 'origem', 'responsavel_id', 'substituto_id', 'revisor_id', 'aprovador_id', 'assinantes'])]
class EtapaProcesso extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'etapas_processo';

    protected function casts(): array
    {
        return ['depende_de' => 'array', 'assinantes' => 'array', 'exige_revisao' => 'boolean', 'exige_aprovacao' => 'boolean', 'exige_assinatura' => 'boolean', 'prazo_em' => 'date', 'solicitado_em' => 'datetime'];
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function substituto(): BelongsTo
    {
        return $this->belongsTo(User::class, 'substituto_id');
    }

    public function prazoVencido(): bool
    {
        return $this->prazo_em !== null && $this->prazo_em->isPast() && ! $this->prazo_em->isToday();
    }

    protected function rotuloAuditoria(): string
    {
        return "Etapa {$this->tipo} do processo";
    }
}
