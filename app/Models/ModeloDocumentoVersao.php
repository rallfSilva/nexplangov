<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['orgao_id', 'modelo_id', 'versao', 'titulo', 'conteudo', 'user_id'])]
class ModeloDocumentoVersao extends Model
{
    use BelongsToOrgao;

    protected $table = 'modelos_documento_versoes';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['conteudo' => 'array', 'created_at' => 'datetime'];
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
