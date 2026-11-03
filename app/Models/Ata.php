<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ata de registro de preços de um fornecedor (arts. 82 a 86). */
#[Fillable(['orgao_id', 'processo_id', 'fornecedor_id', 'criada_por', 'ano', 'numero', 'assinatura', 'vigencia_inicio', 'vigencia_fim', 'prorrogada', 'fundamento_prorrogacao', 'situacao', 'motivo_cancelamento'])]
class Ata extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'atas';

    protected function casts(): array
    {
        return ['assinatura' => 'date', 'vigencia_inicio' => 'date', 'vigencia_fim' => 'date', 'prorrogada' => 'boolean'];
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(AtaItem::class)->orderBy('id');
    }

    public function vigente(): bool
    {
        return $this->situacao === 'vigente' && $this->vigencia_fim->gte(today());
    }

    public function situacaoExibida(): string
    {
        return match (true) {
            $this->situacao === 'cancelada' => 'Cancelada',
            $this->vigencia_fim->lt(today()) => 'Vencida',
            default => 'Vigente',
        };
    }

    public function identificacao(): string
    {
        return "{$this->numero}/{$this->ano}";
    }

    protected function rotuloAuditoria(): string
    {
        return "Ata {$this->identificacao()}";
    }
}
