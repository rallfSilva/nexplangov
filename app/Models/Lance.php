<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Lance registrado na disputa. Não se altera nem se apaga: a sessão fica integralmente registrada. */
#[Fillable(['orgao_id', 'processo_id', 'item_id', 'proposta_id', 'valor', 'user_id'])]
class Lance extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'lances';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['valor' => 'decimal:4', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Lance não pode ser alterado.'));
        static::deleting(fn () => throw new LogicException('Lance não pode ser apagado.'));
    }

    public function proposta(): BelongsTo
    {
        return $this->belongsTo(Proposta::class);
    }

    protected function rotuloAuditoria(): string
    {
        return "Lance no item #{$this->item_id}";
    }
}
