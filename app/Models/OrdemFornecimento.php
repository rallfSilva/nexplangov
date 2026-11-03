<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['orgao_id', 'ata_item_id', 'requisicao_id', 'consumo_id', 'unidade_id', 'emitida_por', 'ano', 'numero', 'quantidade', 'valor_unitario', 'valor_total', 'prazo_entrega', 'situacao', 'motivo_cancelamento'])]
class OrdemFornecimento extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'ordens_fornecimento';

    public const SITUACOES = ['emitida' => 'Emitida', 'parcial' => 'Entrega parcial', 'entregue' => 'Entregue', 'cancelada' => 'Cancelada'];

    protected function casts(): array
    {
        return ['quantidade' => 'decimal:4', 'valor_unitario' => 'decimal:4', 'valor_total' => 'decimal:2', 'prazo_entrega' => 'date'];
    }

    public function ataItem(): BelongsTo
    {
        return $this->belongsTo(AtaItem::class);
    }

    public function requisicao(): BelongsTo
    {
        return $this->belongsTo(Requisicao::class);
    }

    public function consumo(): BelongsTo
    {
        return $this->belongsTo(AtaConsumo::class, 'consumo_id');
    }

    public function emissor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emitida_por');
    }

    public function recebimentos(): HasMany
    {
        return $this->hasMany(Recebimento::class, 'ordem_id')->orderBy('id');
    }

    public function recebido(string $tipo): float
    {
        return (float) $this->recebimentos()->where('tipo', $tipo)->sum('quantidade');
    }

    public function atrasada(): bool
    {
        return in_array($this->situacao, ['emitida', 'parcial'], true) && $this->prazo_entrega->lt(today());
    }

    public function identificacao(): string
    {
        return "{$this->numero}/{$this->ano}";
    }

    protected function rotuloAuditoria(): string
    {
        return "Ordem de fornecimento {$this->identificacao()}";
    }
}
