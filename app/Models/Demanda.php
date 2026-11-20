<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Documento de formalização da demanda (DFD) feito pela área requisitante. */
#[Fillable(['orgao_id', 'unidade_id', 'item_pca_id', 'solicitante_id', 'numero', 'ano', 'exercicio', 'objeto', 'justificativa', 'quantidade', 'valor_estimado', 'data_pretendida', 'prioridade', 'situacao', 'motivo_recusa', 'excecao_prazo', 'justificativa_excecao', 'enviada_em', 'consolidada_em'])]
class Demanda extends Model
{
    use Auditable, BelongsToOrgao;

    public const SITUACOES = [
        'rascunho' => 'Em elaboração', 'enviada' => 'Em análise', 'aceita' => 'Aprovada', 'recusada' => 'Devolvida',
        'consolidada' => 'Consolidada no PCA', 'atendida' => 'Virou processo',
    ];

    /** Situações em que a unidade ainda pode alterar o DFD. */
    public const EDITAVEIS = ['rascunho', 'recusada'];

    protected function casts(): array
    {
        return ['quantidade' => 'decimal:4', 'valor_estimado' => 'decimal:2', 'data_pretendida' => 'date', 'prioridade' => 'integer', 'exercicio' => 'integer', 'enviada_em' => 'datetime', 'consolidada_em' => 'datetime'];
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ItemDemanda::class)->orderBy('numero');
    }

    public function editavel(): bool
    {
        return in_array($this->situacao, self::EDITAVEIS, true);
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
