<?php

namespace App\Models;

use App\Licitacao\Regras\NotaEmpenho;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Nota de empenho: reserva da dotação para o credor, contra a qual se liquida (Lei 4.320, arts. 58 a 63; Lei 14.133, art. 92, VIII). */
#[Fillable(['orgao_id', 'fornecedor_id', 'contrato_id', 'ata_id', 'numero', 'exercicio', 'tipo', 'emitido_em', 'natureza_despesa', 'fonte_recurso', 'valor', 'valor_anulado', 'motivo_anulacao', 'registrado_por'])]
class Empenho extends Model
{
    use Auditable, BelongsToOrgao;

    protected function casts(): array
    {
        return ['emitido_em' => 'date', 'valor' => 'decimal:2', 'valor_anulado' => 'decimal:2', 'exercicio' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Empenho não pode ser apagado; anule o saldo com motivo.'));
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    public function ata(): BelongsTo
    {
        return $this->belongsTo(Ata::class);
    }

    public function liquidacoes(): HasMany
    {
        return $this->hasMany(Liquidacao::class);
    }

    public function liquidado(): float
    {
        return (float) $this->liquidacoes()->where('situacao', '!=', 'cancelado')->sum('valor_bruto');
    }

    public function saldo(): float
    {
        return NotaEmpenho::saldo((float) $this->valor, (float) $this->valor_anulado, $this->liquidado());
    }

    public function anulado(): bool
    {
        return round((float) $this->valor - (float) $this->valor_anulado, 2) <= 0;
    }

    /** Empenhos do credor com saldo, para escolher na liquidação. */
    public function scopeDoCredor(Builder $q, int $fornecedorId, ?int $contratoId = null, ?int $ataId = null): Builder
    {
        return $q->where('fornecedor_id', $fornecedorId)
            ->when($contratoId, fn ($w) => $w->where(fn ($x) => $x->where('contrato_id', $contratoId)->orWhereNull('contrato_id')))
            ->when($ataId, fn ($w) => $w->where(fn ($x) => $x->where('ata_id', $ataId)->orWhereNull('ata_id')))
            ->whereColumn('valor_anulado', '<', 'valor')->orderBy('emitido_em')->orderBy('id');
    }

    public function identificacao(): string
    {
        return $this->numero.'/'.$this->exercicio;
    }

    protected function generoAuditoria(): string
    {
        return 'o';
    }

    protected function rotuloAuditoria(): string
    {
        return "Empenho {$this->identificacao()}";
    }
}
