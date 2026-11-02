<?php

namespace App\Models;

use App\Licitacao\Enums\SituacaoProcesso;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Histórico de tramitação. Somente inclusão. */
#[Fillable(['orgao_id', 'processo_id', 'user_id', 'de', 'para', 'justificativa'])]
class EventoProcesso extends Model
{
    use BelongsToOrgao;

    public const UPDATED_AT = null;

    protected $table = 'eventos_processo';

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('O histórico de tramitação não pode ser alterado.'));
        static::deleting(fn () => throw new \LogicException('O histórico de tramitação não pode ser apagado.'));
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function rotuloPara(): string
    {
        return SituacaoProcesso::tryFrom($this->para)?->rotulo() ?? $this->para;
    }
}
