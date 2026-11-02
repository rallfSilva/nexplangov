<?php

namespace App\Models;

use App\Licitacao\Enums\CategoriaProcesso;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['orgao_id', 'plano_contratacao_id', 'unidade_id', 'numero', 'material_servico', 'categoria', 'codigo_catalogo', 'descricao', 'unidade_fornecimento', 'quantidade', 'valor_unitario_estimado', 'data_desejada', 'prioridade', 'natureza_despesa'])]
class ItemPca extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'itens_pca';

    public const PRIORIDADES = [1 => 'Alta', 2 => 'Média', 3 => 'Baixa'];

    protected function casts(): array
    {
        return ['quantidade' => 'decimal:4', 'valor_unitario_estimado' => 'decimal:4', 'valor_total_estimado' => 'decimal:2', 'data_desejada' => 'date', 'categoria' => 'integer', 'prioridade' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(fn (ItemPca $i) => $i->valor_total_estimado = round((float) $i->quantidade * (float) $i->valor_unitario_estimado, 2));
    }

    public function plano(): BelongsTo
    {
        return $this->belongsTo(PlanoContratacao::class, 'plano_contratacao_id');
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function categoriaEnum(): CategoriaProcesso
    {
        return CategoriaProcesso::from($this->categoria);
    }

    protected function rotuloAuditoria(): string
    {
        return "Item {$this->numero} do PCA";
    }
}
