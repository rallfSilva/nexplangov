<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Intenção de registro de preços (art. 86): o órgão gerenciador colhe as necessidades dos participantes. */
#[Fillable(['orgao_id', 'unidade_id', 'processo_id', 'criada_por', 'ano', 'numero', 'objeto', 'justificativa', 'manifestacao_ate', 'situacao'])]
class Irp extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'irps';

    public const SITUACOES = ['aberta' => 'Aberta para manifestação', 'encerrada' => 'Encerrada', 'consolidada' => 'Consolidada no processo', 'cancelada' => 'Cancelada'];

    protected function casts(): array
    {
        return ['manifestacao_ate' => 'date'];
    }

    public function manifestacoes(): HasMany
    {
        return $this->hasMany(IrpManifestacao::class)->orderBy('id');
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function identificacao(): string
    {
        return "{$this->numero}/{$this->ano}";
    }

    public function aceitaManifestacao(): bool
    {
        return $this->situacao === 'aberta' && $this->manifestacao_ate->gte(today());
    }

    protected function rotuloAuditoria(): string
    {
        return "IRP {$this->identificacao()}";
    }
}
