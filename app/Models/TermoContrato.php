<?php

namespace App\Models;

use App\Licitacao\Enums\TipoTermo;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['orgao_id', 'contrato_id', 'tipo', 'numero', 'assinatura', 'valor_acrescimo', 'valor_supressao', 'valor_reajuste', 'nova_vigencia_fim', 'justificativa', 'reequilibrio'])]
class TermoContrato extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'termos_contrato';

    protected function casts(): array
    {
        return ['assinatura' => 'date', 'nova_vigencia_fim' => 'date', 'valor_acrescimo' => 'decimal:2', 'valor_supressao' => 'decimal:2', 'valor_reajuste' => 'decimal:2', 'reequilibrio' => 'boolean'];
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    public function tipoEnum(): TipoTermo
    {
        return TipoTermo::from($this->tipo);
    }

    protected function rotuloAuditoria(): string
    {
        return "Termo {$this->numero} do contrato #{$this->contrato_id}";
    }
}
