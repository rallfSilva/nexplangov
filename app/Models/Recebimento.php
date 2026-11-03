<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Recebimento provisório ou definitivo (art. 140). Não se altera nem se apaga. */
#[Fillable(['orgao_id', 'ordem_id', 'tipo', 'quantidade', 'documento', 'data', 'observacao', 'recebido_por'])]
class Recebimento extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'recebimentos';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['quantidade' => 'decimal:4', 'data' => 'date', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Recebimento não pode ser alterado.'));
        static::deleting(fn () => throw new LogicException('Recebimento não pode ser apagado.'));
    }

    public function ordem(): BelongsTo
    {
        return $this->belongsTo(OrdemFornecimento::class, 'ordem_id');
    }

    public function recebedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recebido_por');
    }

    protected function rotuloAuditoria(): string
    {
        return "Recebimento {$this->tipo} da ordem #{$this->ordem_id}";
    }
}
