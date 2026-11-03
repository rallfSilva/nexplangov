<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['orgao_id', 'processo_id', 'item_pca_id', 'numero', 'material_servico', 'codigo_catalogo', 'descricao', 'unidade_medida', 'quantidade', 'metodo_preco', 'tipo_beneficio', 'situacao', 'fornecedor_vencedor_id', 'valor_unitario_homologado', 'proposta_id', 'adjudicado_em', 'adjudicado_por'])]
class ItemProcesso extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'itens_processo';

    /** Tabela 5.7 do PNCP. */
    public const BENEFICIOS = [1 => 'Cota exclusiva ME/EPP (art. 48, I, da LC 123)', 2 => 'Subcontratação ME/EPP', 3 => 'Cota reservada ME/EPP', 4 => 'Sem benefício', 5 => 'Não se aplica'];

    public const SITUACOES = ['pendente' => 'Pendente', 'homologado' => 'Homologado', 'cancelado' => 'Cancelado', 'deserto' => 'Deserto'];

    protected function casts(): array
    {
        return ['quantidade' => 'decimal:4', 'valor_unitario_estimado' => 'decimal:4', 'valor_total_estimado' => 'decimal:2', 'valor_unitario_homologado' => 'decimal:4', 'tipo_beneficio' => 'integer', 'adjudicado_em' => 'datetime'];
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function cotacoes(): HasMany
    {
        return $this->hasMany(Cotacao::class)->orderBy('data_cotacao');
    }

    public function propostas(): HasMany
    {
        return $this->hasMany(Proposta::class, 'item_id');
    }

    public function vencedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class, 'fornecedor_vencedor_id');
    }

    /** Código da situação do item no PNCP (tabela 5.6). */
    public function situacaoPncp(): int
    {
        return ['pendente' => 1, 'homologado' => 2, 'cancelado' => 3, 'deserto' => 4][$this->situacao] ?? 1;
    }

    protected function rotuloAuditoria(): string
    {
        return "Item {$this->numero} do processo #{$this->processo_id}";
    }

    public function itemPca(): BelongsTo
    {
        return $this->belongsTo(ItemPca::class, 'item_pca_id');
    }

    public function fornecedorVencedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class, 'fornecedor_vencedor_id');
    }
}
