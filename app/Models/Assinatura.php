<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Assinatura eletrônica de uma peça. Imutável: não se altera nem se apaga. */
#[Fillable(['orgao_id', 'documento_id', 'user_id', 'solicitacao_externa_id', 'nome', 'perfil', 'assinado_em', 'hash_documento', 'codigo', 'selo', 'ip'])]
class Assinatura extends Model
{
    use Auditable, BelongsToOrgao;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['assinado_em' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Assinatura não pode ser alterada.'));
        static::deleting(fn () => throw new LogicException('Assinatura não pode ser apagada.'));
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoProcesso::class, 'documento_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function rotuloAuditoria(): string
    {
        return "Assinatura de {$this->nome}";
    }
}
