<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Documento de formalização da demanda (DFD) feito pela área requisitante. */
#[Fillable(['orgao_id', 'unidade_id', 'item_pca_id', 'solicitante_id', 'numero', 'ano', 'exercicio', 'objeto', 'justificativa', 'quantidade', 'valor_estimado', 'data_pretendida', 'prioridade', 'situacao', 'motivo_recusa', 'excecao_prazo', 'justificativa_excecao'])]
class Demanda extends Model
{
    use Auditable, BelongsToOrgao;

    public const SITUACOES = ['enviada' => 'Enviada ao planejamento', 'aceita' => 'Aceita', 'recusada' => 'Devolvida', 'atendida' => 'Virou processo'];

    protected function casts(): array
    {
        return ['quantidade' => 'decimal:4', 'valor_estimado' => 'decimal:2', 'data_pretendida' => 'date', 'prioridade' => 'integer', 'exercicio' => 'integer'];
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }

    public function itemPca(): BelongsTo
    {
        return $this->belongsTo(ItemPca::class);
    }

    /** Demandas indicadas como vinculadas a esta (dependentes ou complementares). */
    public function vinculadas(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'demanda_vinculos', 'demanda_id', 'vinculada_id');
    }

    protected function generoAuditoria(): string
    {
        return 'a';
    }

    protected function rotuloAuditoria(): string
    {
        return "Demanda {$this->numero}/{$this->ano}";
    }
}
