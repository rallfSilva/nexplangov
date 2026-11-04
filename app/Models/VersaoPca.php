<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cópia dos itens do PCA no momento de cada aprovação (art. 12, VII e § 1º). Nunca é alterada. */
#[Fillable(['orgao_id', 'plano_contratacao_id', 'numero', 'justificativa', 'itens', 'qtd_itens', 'valor_total', 'aberta_por', 'aprovado_por', 'aprovado_em'])]
class VersaoPca extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'versoes_pca';

    /** Campos copiados de cada item. */
    public const CAMPOS_ITEM = ['numero', 'material_servico', 'categoria', 'codigo_catalogo', 'descricao', 'unidade_fornecimento', 'quantidade', 'valor_unitario_estimado', 'valor_total_estimado', 'unidade_id', 'data_desejada', 'prioridade', 'natureza_despesa'];

    protected function casts(): array
    {
        return ['itens' => 'array', 'valor_total' => 'decimal:2', 'aprovado_em' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Versão do PCA não pode ser alterada.'));
        static::deleting(fn () => throw new \LogicException('Versão do PCA não pode ser excluída.'));
    }

    public function plano(): BelongsTo
    {
        return $this->belongsTo(PlanoContratacao::class, 'plano_contratacao_id');
    }

    public function aprovador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprovado_por');
    }

    public function autorRevisao(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aberta_por');
    }

    protected function camposSensiveisDoModelo(): array
    {
        return ['itens'];
    }

    protected function generoAuditoria(): string
    {
        return 'a';
    }

    protected function rotuloAuditoria(): string
    {
        return "Versão {$this->numero} do PCA";
    }
}
