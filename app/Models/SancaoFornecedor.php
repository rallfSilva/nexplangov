<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sanções do art. 156 da Lei 14.133. */
#[Fillable(['orgao_id', 'fornecedor_id', 'tipo', 'inicio', 'fim', 'processo', 'fundamento', 'processo_sancionador_id', 'multa_valor', 'abrangencia'])]
class SancaoFornecedor extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'sancoes_fornecedor';

    public const TIPOS = [
        'advertencia' => 'Advertência (art. 156, I)',
        'multa' => 'Multa (art. 156, II)',
        'impedimento' => 'Impedimento de licitar e contratar, até 3 anos (art. 156, III)',
        'inidoneidade' => 'Declaração de inidoneidade, de 3 a 6 anos (art. 156, IV)',
    ];

    protected function casts(): array
    {
        return ['inicio' => 'date', 'fim' => 'date', 'multa_valor' => 'decimal:2'];
    }

    public function processoSancionador(): BelongsTo
    {
        return $this->belongsTo(ProcessoSancionador::class);
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    protected function generoAuditoria(): string
    {
        return 'a';
    }

    protected function rotuloAuditoria(): string
    {
        return 'Sanção #'.$this->id;
    }
}
