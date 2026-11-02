<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Fluxo de documentos: modelo que o órgão salva por tipo de contratação. */
#[Fillable(['orgao_id', 'nome', 'descricao', 'aplicacao', 'categoria', 'padrao', 'ativo'])]
class Fluxo extends Model
{
    use Auditable, BelongsToOrgao;

    public const APLICACOES = ['todos' => 'Licitações e contratações diretas', 'licitacao' => 'Só licitações', 'direta' => 'Só contratações diretas'];

    protected function casts(): array
    {
        return ['padrao' => 'boolean', 'ativo' => 'boolean', 'categoria' => 'integer'];
    }

    public function etapas(): HasMany
    {
        return $this->hasMany(FluxoEtapa::class)->orderBy('ordem');
    }

    protected function rotuloAuditoria(): string
    {
        return "Fluxo {$this->nome}";
    }
}
