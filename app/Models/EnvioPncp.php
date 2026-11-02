<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['orgao_id', 'user_id', 'publicavel_type', 'publicavel_id', 'recurso', 'operacao', 'estado', 'ambiente', 'tentativas', 'payload', 'metodo', 'rota', 'http_status', 'resposta', 'location', 'erro', 'publicado_em'])]
class EnvioPncp extends Model
{
    use BelongsToOrgao;

    protected $table = 'envios_pncp';

    public const ESTADOS = ['pendente' => 'Na fila', 'enviando' => 'Enviando', 'publicado' => 'Publicado', 'erro' => 'Com erro'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'resposta' => 'array', 'publicado_em' => 'datetime'];
    }

    public function publicavel(): MorphTo
    {
        return $this->morphTo();
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function badge(): string
    {
        return ['pendente' => 'b-info', 'enviando' => 'b-info', 'publicado' => 'b-ok', 'erro' => 'b-err'][$this->estado] ?? 'b-mute';
    }
}
