<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Livro dos alertas já enviados: evita repetir o mesmo aviso ao mesmo usuário (chave tipo:referência:marco). */
#[Fillable(['orgao_id', 'user_id', 'chave', 'enviado_em'])]
class AlertaEnviado extends Model
{
    use BelongsToOrgao;

    protected $table = 'alertas_enviados';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['enviado_em' => 'datetime'];
    }
}
