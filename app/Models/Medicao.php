<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** Medição do que foi executado no período, feita pelo fiscal e aprovada pelo gestor (arts. 117 e 140). */
#[Fillable(['orgao_id', 'contrato_id', 'numero', 'periodo_inicio', 'periodo_fim', 'valor', 'descricao', 'situacao', 'medido_por', 'aprovado_por', 'aprovado_em', 'motivo_recusa'])]
class Medicao extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'medicoes';

    protected function casts(): array
    {
        return ['periodo_inicio' => 'date', 'periodo_fim' => 'date', 'valor' => 'decimal:2', 'aprovado_em' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Medição não pode ser apagada; recuse-a com motivo.'));
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    public function medidor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'medido_por');
    }

    public function liquidacao(): HasOne
    {
        return $this->hasOne(Liquidacao::class);
    }

    protected function generoAuditoria(): string
    {
        return 'a';
    }

    protected function rotuloAuditoria(): string
    {
        return "Medição {$this->numero} do contrato #{$this->contrato_id}";
    }
}
