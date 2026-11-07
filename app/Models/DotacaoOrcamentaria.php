<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Dotação orçamentária do exercício por natureza de despesa, usada no cronograma do PCA (art. 18, caput). */
#[Fillable(['orgao_id', 'exercicio', 'natureza_despesa', 'descricao', 'valor_autorizado', 'registrado_por'])]
class DotacaoOrcamentaria extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'dotacoes_orcamentarias';

    protected function casts(): array
    {
        return ['exercicio' => 'integer', 'valor_autorizado' => 'decimal:2'];
    }

    protected function generoAuditoria(): string
    {
        return 'a';
    }

    protected function rotuloAuditoria(): string
    {
        return "Dotação {$this->natureza_despesa} de {$this->exercicio}";
    }
}
