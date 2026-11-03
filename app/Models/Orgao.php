<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\OrgaoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['nome', 'sigla', 'cnpj', 'esfera', 'poder', 'natureza_juridica', 'uf', 'municipio', 'limite_dispensa_em_dobro', 'pncp_ambiente', 'pncp_orgao_preparado', 'ativo', 'treinamento', 'treinamento_expira_em'])]
class Orgao extends Model
{
    /** @use HasFactory<OrgaoFactory> */
    use Auditable, HasFactory;

    protected $table = 'orgaos';

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'limite_dispensa_em_dobro' => 'boolean', 'pncp_orgao_preparado' => 'boolean', 'treinamento' => 'boolean', 'treinamento_expira_em' => 'date'];
    }

    public function unidades(): HasMany
    {
        return $this->hasMany(Unidade::class);
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Turma de treinamento cujo prazo já terminou. */
    public function treinamentoEncerrado(): bool
    {
        return $this->treinamento && $this->treinamento_expira_em !== null && $this->treinamento_expira_em->lt(today());
    }

    protected function rotuloAuditoria(): string
    {
        return 'Órgão '.$this->sigla;
    }
}
