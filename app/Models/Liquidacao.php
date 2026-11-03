<?php

namespace App\Models;

use App\Licitacao\Regras\OrdemCronologica;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Crédito liquidado à espera de pagamento na ordem cronológica (art. 141). */
#[Fillable(['orgao_id', 'fornecedor_id', 'contrato_id', 'medicao_id', 'ordem_id', 'categoria', 'fonte_recurso', 'nota_fiscal', 'nota_emitida_em', 'valor_bruto', 'retencoes', 'valor_liquido',
    'atesto', 'liquidado_em', 'liquidado_por', 'vencimento', 'situacao', 'pago_em', 'ordem_bancaria', 'pago_por', 'motivo_cancelamento',
    'quebra_hipotese', 'quebra_justificativa', 'quebra_autorizada_por', 'quebra_autorizada_em', 'comunicado_controle_em', 'comunicado_tribunal_em'])]
class Liquidacao extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'liquidacoes';

    protected function casts(): array
    {
        return [
            'nota_emitida_em' => 'date', 'valor_bruto' => 'decimal:2', 'retencoes' => 'decimal:2', 'valor_liquido' => 'decimal:2', 'liquidado_em' => 'date', 'vencimento' => 'date',
            'pago_em' => 'date', 'quebra_autorizada_em' => 'datetime', 'comunicado_controle_em' => 'date', 'comunicado_tribunal_em' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Liquidação não pode ser apagada; cancele-a com motivo.'));
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    public function medicao(): BelongsTo
    {
        return $this->belongsTo(Medicao::class);
    }

    public function ordem(): BelongsTo
    {
        return $this->belongsTo(OrdemFornecimento::class, 'ordem_id');
    }

    /** A mesma fila: mesma fonte de recursos e mesma categoria do art. 141. */
    public function scopeDaFila(Builder $q, string $fonte, string $categoria): Builder
    {
        return $q->where('fonte_recurso', $fonte)->where('categoria', $categoria);
    }

    /** Ordem de exigibilidade: data da liquidação e, no mesmo dia, a ordem de registro. */
    public function scopeEmOrdem(Builder $q): Builder
    {
        return $q->orderBy('liquidado_em')->orderBy('id');
    }

    public function identificacao(): string
    {
        return 'L-'.$this->id;
    }

    public function quebraAutorizada(): bool
    {
        return $this->quebra_autorizada_por !== null;
    }

    /** Pagamento fora da ordem ainda sem a comunicação posterior ao controle interno e ao tribunal de contas. */
    public function comunicacaoPendente(): bool
    {
        return $this->situacao === 'pago' && $this->quebraAutorizada() && ($this->comunicado_controle_em === null || $this->comunicado_tribunal_em === null);
    }

    public function atrasada(): bool
    {
        return $this->situacao === 'liquidado' && $this->vencimento->lt(today());
    }

    public function atrasoPermiteExtincao(): bool
    {
        return $this->situacao === 'liquidado' && (new OrdemCronologica)->atrasoPermiteExtincao($this->vencimento, today());
    }

    public function referencia(): string
    {
        return $this->contrato ? 'Contrato '.$this->contrato->identificacao().($this->medicao ? ' · medição '.$this->medicao->numero : '') : ($this->ordem ? 'Ordem de fornecimento '.$this->ordem->identificacao() : '');
    }

    protected function generoAuditoria(): string
    {
        return 'a';
    }

    protected function rotuloAuditoria(): string
    {
        return "Liquidação {$this->identificacao()}";
    }
}
