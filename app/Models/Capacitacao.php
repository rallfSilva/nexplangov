<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use App\Support\Parametros;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Curso concluído por um agente público (art. 7º, II, e art. 173). */
#[Fillable(['orgao_id', 'user_id', 'curso', 'instituicao', 'escola_governo', 'tema', 'carga_horaria', 'concluida_em', 'certificado', 'registrada_por'])]
class Capacitacao extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'capacitacoes';

    public const TEMAS = ['planejamento' => 'Planejamento e fase preparatória', 'licitacao' => 'Licitação e julgamento', 'pregao' => 'Pregão e agente de contratação', 'contratos' => 'Gestão e fiscalização de contratos', 'governanca' => 'Governança, riscos e integridade', 'outro' => 'Outro tema'];

    protected function casts(): array
    {
        return ['concluida_em' => 'date', 'escola_governo' => 'boolean', 'carga_horaria' => 'integer'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function vigente(): bool
    {
        return $this->concluida_em->gte(today()->subMonths(Parametros::valor('capacitacao_validade_meses', $this->orgao_id)));
    }

    protected function rotuloAuditoria(): string
    {
        return "Capacitação #{$this->id}";
    }
}
