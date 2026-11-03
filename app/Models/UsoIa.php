<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Registro imutável de uso da NexMind IA. */
#[Fillable(['orgao_id', 'user_id', 'processo_id', 'tipo_documento', 'acao', 'hash_pedido', 'tokens_entrada', 'tokens_saida'])]
class UsoIa extends Model
{
    use BelongsToOrgao;

    protected $table = 'usos_ia';

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('O registro de uso da IA não pode ser alterado.'));
        static::deleting(fn () => throw new LogicException('O registro de uso da IA não pode ser apagado.'));
    }
}
