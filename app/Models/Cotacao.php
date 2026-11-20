<?php

namespace App\Models;

use App\Licitacao\Enums\FontePreco;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['orgao_id', 'item_processo_id', 'fonte', 'referencia', 'documento_fonte', 'valor_unitario', 'data_cotacao', 'desconsiderada', 'motivo_desconsideracao', 'origem', 'quantidade', 'unidade_fonte', 'descricao_fonte', 'url_fonte', 'analise_ia', 'identificacao_fonte', 'consultado_em'])]
class Cotacao extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'cotacoes';

    protected function casts(): array
    {
        return ['valor_unitario' => 'decimal:4', 'data_cotacao' => 'date', 'desconsiderada' => 'boolean', 'quantidade' => 'decimal:4', 'consultado_em' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ItemProcesso::class, 'item_processo_id');
    }

    public function daIa(): bool
    {
        return $this->origem === 'ia';
    }

    public function fonteEnum(): FontePreco
    {
        return FontePreco::from($this->fonte);
    }

    protected function generoAuditoria(): string
    {
        return 'a';
    }

    protected function rotuloAuditoria(): string
    {
        return "Cotação de {$this->referencia}";
    }
}
