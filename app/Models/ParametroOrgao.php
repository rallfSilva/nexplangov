<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Valor de um parâmetro ajustado pelo órgão (ParametrosOrgao::DEFINICOES). */
#[Fillable(['orgao_id', 'chave', 'valor', 'justificativa', 'alterado_por'])]
class ParametroOrgao extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'parametros_orgao';

    protected function rotuloAuditoria(): string
    {
        return "Parâmetro \"{$this->chave}\" do órgão";
    }
}
