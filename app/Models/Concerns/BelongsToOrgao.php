<?php

namespace App\Models\Concerns;

use App\Models\Orgao;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Isola os registros por órgão (multi-órgão). Quem está logado só enxerga
 * e só cria registros do próprio órgão. O administrador geral (orgao_id nulo)
 * enxerga todos os órgãos.
 */
trait BelongsToOrgao
{
    public static function bootBelongsToOrgao(): void
    {
        static::addGlobalScope('orgao', function (Builder $query) {
            // hasUser() evita recarregar o usuário dentro do próprio escopo.
            if (! Auth::hasUser()) {
                return;
            }

            $orgaoId = Auth::user()->orgao_id;

            if ($orgaoId !== null) {
                $query->where($query->qualifyColumn('orgao_id'), $orgaoId);
            }
        });

        static::creating(function ($model) {
            if ($model->orgao_id === null && Auth::hasUser()) {
                $model->orgao_id = Auth::user()->orgao_id;
            }
        });
    }

    public function orgao(): BelongsTo
    {
        return $this->belongsTo(Orgao::class);
    }
}
