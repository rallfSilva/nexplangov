<?php

namespace App\Models;

use App\Licitacao\Enums\Modalidade;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Fluxo de documentos: modelo que o órgão salva por tipo de contratação. */
#[Fillable(['orgao_id', 'nome', 'descricao', 'aplicacao', 'categoria', 'modalidade', 'unidade_id', 'roteiro_unidades', 'padrao', 'ativo'])]
class Fluxo extends Model
{
    use Auditable, BelongsToOrgao;

    public const APLICACOES = ['todos' => 'Licitações e contratações diretas', 'licitacao' => 'Só licitações', 'direta' => 'Só contratações diretas'];

    protected function casts(): array
    {
        return ['padrao' => 'boolean', 'ativo' => 'boolean', 'categoria' => 'integer', 'modalidade' => 'integer', 'unidade_id' => 'integer', 'roteiro_unidades' => 'array'];
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    /** Resumo de onde o fluxo vale, para listas. */
    public function abrangencia(): string
    {
        $partes = [self::APLICACOES[$this->aplicacao] ?? $this->aplicacao];
        if ($this->modalidade) {
            $partes[] = Modalidade::from($this->modalidade)->rotulo();
        }
        if ($this->unidade_id) {
            $partes[] = 'secretaria '.($this->unidade?->sigla ?? '?');
        }

        return implode(' · ', $partes);
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
