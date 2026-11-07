<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Permissão extra concedida a um servidor, com justificativa (11.4). A permissão em si fica no Spatie. */
#[Fillable(['orgao_id', 'user_id', 'permissao', 'justificativa', 'concedida_por', 'revogada_em', 'revogada_por', 'motivo_revogacao'])]
class ConcessaoPermissao extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'concessoes_permissao';

    protected function casts(): array
    {
        return ['revogada_em' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function concedente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'concedida_por');
    }

    public function scopeVigentes(Builder $q): Builder
    {
        return $q->whereNull('revogada_em');
    }

    protected function generoAuditoria(): string
    {
        return 'a';
    }

    protected function rotuloAuditoria(): string
    {
        return "Concessão da permissão \"{$this->permissao}\"";
    }
}
